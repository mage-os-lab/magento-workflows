<?php

declare(strict_types=1);

namespace MageOS\Workflows\Console\Command;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Serialize\SerializerInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\Action\ActionPool;
use MageOS\Workflows\Model\Definition\Definition;
use MageOS\Workflows\Model\WorkflowFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Imports a workflow from the JSON envelope produced by `workflow:export`
 * (docs/04-definition-format.md).
 *
 * Import is untrusted input (docs/04-definition-format.md#import-is-untrusted-input,
 * docs/10-security.md#import-is-untrusted-input) and is validated hard:
 *  - the envelope's `format` tag must match the supported version;
 *  - the definition graph is structurally validated via Definition::fromArray()
 *    (schema/step-type/edge/duration checks — throws \InvalidArgumentException);
 *  - every action code referenced by the definition (Definition::getActionCodes())
 *    must be registered in the ActionPool, otherwise the import is rejected with the
 *    full list of unknown codes.
 *
 * ACL re-authorization warning: the security model additionally requires re-authorizing
 * an imported definition against the *importing admin's* ACL, so a definition containing
 * actions the importer couldn't author themselves fails loudly. The CLI has no admin
 * session — it runs with system privileges — so that re-authorization step is skipped
 * here, exactly like the documented risk for data-patch-run-as-system imports
 * (docs/04-definition-format.md: "patches run as system; agencies own that risk"). A
 * warning is printed on every invocation; operators are responsible for reviewing
 * definitions from untrusted sources before running this command.
 */
class ImportCommand extends Command
{
    private const ARG_FILE = 'file';
    private const OPT_ACTIVATE = 'activate';

    public function __construct(
        private readonly WorkflowRepositoryInterface $workflowRepository,
        private readonly WorkflowFactory $workflowFactory,
        private readonly ActionPool $actionPool,
        private readonly SerializerInterface $serializer,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('workflow:import')
            ->setDescription(
                'Import a workflow from a `workflow:export` JSON envelope. Rejects unknown action '
                . 'codes and malformed definitions. WARNING: CLI import bypasses admin ACL '
                . 're-authorization (see docs/04-definition-format.md).'
            )
            ->addArgument(self::ARG_FILE, InputArgument::REQUIRED, 'Path to the export JSON file')
            ->addOption(
                self::OPT_ACTIVATE,
                null,
                InputOption::VALUE_NONE,
                'Enable the imported workflow immediately; otherwise it is created disabled'
            );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(
            '<comment>Warning: CLI import runs with system privileges and does NOT re-authorize '
            . 'imported actions against an admin ACL, unlike the admin-UI import path '
            . '(see docs/04-definition-format.md#import-is-untrusted-input). Review the source '
            . 'of this file before importing.</comment>'
        );

        $path = (string) $input->getArgument(self::ARG_FILE);
        if (!is_readable($path)) {
            $output->writeln(sprintf('<error>Cannot read file "%s"</error>', $path));
            return Command::FAILURE;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            $output->writeln(sprintf('<error>Cannot read file "%s"</error>', $path));
            return Command::FAILURE;
        }

        try {
            $envelope = $this->serializer->unserialize($contents);
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>File "%s" is not valid JSON: %s</error>', $path, $e->getMessage()));
            return Command::FAILURE;
        }

        if (!is_array($envelope)) {
            $output->writeln('<error>Export envelope must be a JSON object</error>');
            return Command::FAILURE;
        }

        $format = $envelope['format'] ?? null;
        if ($format !== ExportCommand::FORMAT) {
            $output->writeln(sprintf(
                '<error>Unsupported export format "%s"; expected "%s"</error>',
                (string) $format,
                ExportCommand::FORMAT
            ));
            return Command::FAILURE;
        }

        $definitionData = $envelope['definition'] ?? null;
        if (!is_array($definitionData)) {
            $output->writeln('<error>Envelope "definition" must be an object</error>');
            return Command::FAILURE;
        }

        try {
            $definition = Definition::fromArray($definitionData);
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>Invalid workflow definition: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        $unknownCodes = [];
        foreach ($definition->getActionCodes() as $code) {
            if (!$this->actionPool->has($code)) {
                $unknownCodes[] = $code;
            }
        }
        if ($unknownCodes !== []) {
            $output->writeln(sprintf(
                '<error>Definition references unknown action code(s): %s</error>',
                implode(', ', $unknownCodes)
            ));
            return Command::FAILURE;
        }

        $name = $envelope['name'] ?? null;
        $entityType = $envelope['entity_type'] ?? null;
        $triggerType = $envelope['trigger_type'] ?? null;
        $triggerRef = $envelope['trigger_ref'] ?? null;
        if (!is_string($name) || $name === ''
            || !is_string($entityType) || $entityType === ''
            || !is_string($triggerType) || $triggerType === ''
            || !is_string($triggerRef) || $triggerRef === ''
        ) {
            $output->writeln(
                '<error>Envelope is missing required field(s): name, entity_type, trigger_type, '
                . 'trigger_ref</error>'
            );
            return Command::FAILURE;
        }

        $conditionsSerialized = $envelope['conditions_serialized'] ?? null;
        $loopGuardDepth = $envelope['loop_guard_depth'] ?? 1;

        $workflow = $this->workflowFactory->create();
        $workflow->setName($name);
        $workflow->setEntityType($entityType);
        $workflow->setTriggerType($triggerType);
        $workflow->setTriggerRef($triggerRef);
        $workflow->setConditionsSerialized(is_string($conditionsSerialized) ? $conditionsSerialized : null);
        $workflow->setDefinition($definition->toJson());
        $workflow->setLoopGuardDepth((int) $loopGuardDepth);
        $workflow->setStatus(
            $input->getOption(self::OPT_ACTIVATE)
                ? WorkflowInterface::STATUS_ENABLED
                : WorkflowInterface::STATUS_DISABLED
        );

        try {
            $saved = $this->workflowRepository->save($workflow);
        } catch (CouldNotSaveException $e) {
            $output->writeln(sprintf('<error>Could not save imported workflow: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        $output->writeln((string) $saved->getWorkflowId());
        return Command::SUCCESS;
    }
}
