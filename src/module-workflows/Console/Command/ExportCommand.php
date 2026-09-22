<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\Workflows\Console\Command;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\SerializerInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Exports a workflow as the portable JSON envelope described in
 * docs/04-definition-format.md ("Workflow-as-code").
 *
 * Security note: the export NEVER contains secret values. Stored definitions only ever
 * reference secrets by name (e.g. "{{ secrets.fraud_hmac }}"), never resolved values, so
 * emitting the stored columns verbatim carries no secret material. Do not extend this
 * command to dereference or resolve `secrets.*` placeholders before printing.
 */
class ExportCommand extends Command
{
    private const ARG_WORKFLOW_ID = 'workflow_id';
    private const OPT_FILE = 'file';

    /**
     * Export envelope format tag (docs/04-definition-format.md). Alias of
     * the importer's constant — the single source of truth for the shared
     * import/export path (F3).
     */
    public const FORMAT = \MageOS\Workflows\Model\Import\WorkflowImporter::FORMAT;

    public function __construct(
        private readonly WorkflowRepositoryInterface $workflowRepository,
        private readonly SerializerInterface $serializer,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('workflow:export')
            ->setDescription(
                'Export a workflow definition as a JSON envelope. Exports never contain secret '
                . 'values: definitions reference secrets by name only (e.g. "{{ secrets.foo }}"), '
                . 'never resolved values.'
            )
            ->addArgument(
                self::ARG_WORKFLOW_ID,
                InputArgument::REQUIRED,
                'Workflow ID to export'
            )
            ->addOption(
                self::OPT_FILE,
                null,
                InputOption::VALUE_REQUIRED,
                'Path to write the export JSON to; defaults to stdout'
            );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workflowId = (int) $input->getArgument(self::ARG_WORKFLOW_ID);

        try {
            $workflow = $this->workflowRepository->getById($workflowId);
        } catch (NoSuchEntityException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        try {
            $definition = $this->serializer->unserialize($workflow->getDefinition());
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf(
                '<error>Stored definition for workflow %d is not valid JSON: %s</error>',
                $workflowId,
                $e->getMessage()
            ));
            return Command::FAILURE;
        }

        $envelope = [
            'format' => self::FORMAT,
            'name' => $workflow->getName(),
            'entity_type' => $workflow->getEntityType(),
            'trigger_type' => $workflow->getTriggerType(),
            'trigger_ref' => $workflow->getTriggerRef(),
            'conditions_serialized' => $workflow->getConditionsSerialized(),
            'definition' => $definition,
            'loop_guard_depth' => $workflow->getLoopGuardDepth(),
        ];

        $json = json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $file = $input->getOption(self::OPT_FILE);
        if ($file !== null) {
            if (@file_put_contents($file, $json . PHP_EOL) === false) { // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
                $output->writeln(sprintf('<error>Could not write export to "%s"</error>', $file));
                return Command::FAILURE;
            }
            $output->writeln(sprintf('<info>Workflow %d exported to %s</info>', $workflowId, $file));
            return Command::SUCCESS;
        }

        $output->writeln($json);
        return Command::SUCCESS;
    }
}
