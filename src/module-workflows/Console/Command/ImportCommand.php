<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\Workflows\Console\Command;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\SerializerInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\Import\WorkflowImporter;
use MageOS\Workflows\Model\Validation\ValidationContext;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Thin CLI shell over Model\Import\WorkflowImporter (F3): reads the file,
 * decodes the JSON, and delegates envelope + definition validation and
 * persistence to the shared import path.
 *
 * ACL re-authorization warning: the security model additionally requires
 * re-authorizing an imported definition against the *importing admin's* ACL,
 * so a definition containing actions the importer couldn't author themselves
 * fails loudly. The CLI has no admin session — it runs with system
 * privileges — so the importer is invoked in SYSTEM mode and that
 * re-authorization step is skipped, exactly like the documented risk for
 * data-patch-run-as-system imports (docs/04-definition-format.md: "patches
 * run as system; agencies own that risk"). A warning is printed on every
 * invocation; operators are responsible for reviewing definitions from
 * untrusted sources before running this command.
 */
class ImportCommand extends Command
{
    private const ARG_FILE = 'file';
    private const OPT_ACTIVATE = 'activate';
    private const OPT_SHADOW = 'shadow';

    public function __construct(
        private readonly WorkflowImporter $workflowImporter,
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
            )
            ->addOption(
                self::OPT_SHADOW,
                null,
                InputOption::VALUE_NONE,
                'Create the imported workflow in shadow mode (evaluates and logs, no side effects)'
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

        if ($input->getOption(self::OPT_ACTIVATE) && $input->getOption(self::OPT_SHADOW)) {
            $output->writeln('<error>--activate and --shadow are mutually exclusive</error>');
            return Command::FAILURE;
        }

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

        $status = WorkflowInterface::STATUS_DISABLED;
        if ($input->getOption(self::OPT_ACTIVATE)) {
            $status = WorkflowInterface::STATUS_ENABLED;
        } elseif ($input->getOption(self::OPT_SHADOW)) {
            $status = WorkflowInterface::STATUS_SHADOW;
        }

        try {
            $result = $this->workflowImporter->import($envelope, ValidationContext::MODE_SYSTEM, $status);
        } catch (LocalizedException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        foreach ($result->getValidationResult()->getWarnings() as $warning) {
            $output->writeln(sprintf(
                '<comment>Warning [%s]%s: %s</comment>',
                $warning->getCode(),
                $warning->getStepKey() !== null ? sprintf(' (step "%s")', $warning->getStepKey()) : '',
                $warning->getMessage()
            ));
        }

        $output->writeln((string) $result->getWorkflow()->getWorkflowId());
        return Command::SUCCESS;
    }
}
