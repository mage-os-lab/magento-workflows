<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\Workflows\Console\Command;

use Magento\Framework\Exception\LocalizedException;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\Template\TemplateInstaller;
use MageOS\Workflows\Model\Template\TemplateInstallRequest;
use MageOS\Workflows\Model\Validation\ValidationContext;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Installs a workflow template from the CLI (06), in SYSTEM mode with the same
 * warning the untrusted-import path prints: no admin session exists, so the
 * per-action ACL re-authorization is skipped and operators own that risk
 * (docs/04-definition-format.md). Installs disabled by default; --shadow /
 * --activate are explicit opt-ins.
 *
 * Parameters are supplied with repeated --param key=value or a --params-file
 * JSON object. `secret`-typed parameters reference EXISTING secrets by key name;
 * creating a new secret is an admin-form action (its value must not travel on a
 * command line), so the CLI installs against already-stored secrets only.
 */
class TemplateInstallCommand extends Command
{
    private const ARG_CODE = 'code';
    private const OPT_PARAM = 'param';
    private const OPT_PARAMS_FILE = 'params-file';
    private const OPT_ACTIVATE = 'activate';
    private const OPT_SHADOW = 'shadow';

    public function __construct(
        private readonly TemplateInstaller $templateInstaller,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('workflow:template:install')
            ->setDescription(
                'Install a workflow template by code. Installs disabled by default. WARNING: like '
                . 'workflow:import, CLI install runs with system privileges and does NOT re-authorize '
                . 'template actions against an admin ACL.'
            )
            ->addArgument(self::ARG_CODE, InputArgument::REQUIRED, 'Template code to install')
            ->addOption(
                self::OPT_PARAM,
                'p',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Parameter value as key=value (repeatable)'
            )
            ->addOption(
                self::OPT_PARAMS_FILE,
                null,
                InputOption::VALUE_REQUIRED,
                'Path to a JSON object of parameter key => value pairs'
            )
            ->addOption(
                self::OPT_ACTIVATE,
                null,
                InputOption::VALUE_NONE,
                'Enable the installed workflow immediately; otherwise it is created disabled'
            )
            ->addOption(
                self::OPT_SHADOW,
                null,
                InputOption::VALUE_NONE,
                'Install the workflow in shadow mode (evaluates and logs, no side effects)'
            );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(
            '<comment>Warning: CLI template install runs with system privileges and does NOT '
            . 're-authorize the template\'s actions against an admin ACL, unlike the admin gallery '
            . '(see docs/04-definition-format.md#import-is-untrusted-input).</comment>'
        );

        if ($input->getOption(self::OPT_ACTIVATE) && $input->getOption(self::OPT_SHADOW)) {
            $output->writeln('<error>--activate and --shadow are mutually exclusive</error>');
            return Command::FAILURE;
        }

        try {
            $parameters = $this->collectParameters($input);
        } catch (LocalizedException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        $status = WorkflowInterface::STATUS_DISABLED;
        if ($input->getOption(self::OPT_ACTIVATE)) {
            $status = WorkflowInterface::STATUS_ENABLED;
        } elseif ($input->getOption(self::OPT_SHADOW)) {
            $status = WorkflowInterface::STATUS_SHADOW;
        }

        try {
            $result = $this->templateInstaller->install(new TemplateInstallRequest(
                (string) $input->getArgument(self::ARG_CODE),
                $parameters,
                $status,
                ValidationContext::MODE_SYSTEM,
                'system'
            ));
        } catch (LocalizedException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        foreach ($result->getImportResult()->getValidationResult()->getWarnings() as $warning) {
            $output->writeln(sprintf(
                '<comment>Warning [%s]%s: %s</comment>',
                $warning->getCode(),
                $warning->getStepKey() !== null ? sprintf(' (step "%s")', $warning->getStepKey()) : '',
                $warning->getMessage()
            ));
        }

        $output->writeln(sprintf(
            '<info>Installed "%s" v%s as workflow #%d (%s). Dry-run and review it before enabling.</info>',
            $result->getTemplateCode(),
            $result->getTemplateVersion(),
            (int) $result->getWorkflow()->getWorkflowId(),
            $this->statusLabel($status)
        ));

        return Command::SUCCESS;
    }

    /**
     * Merge --params-file (base) with repeated --param key=value (override).
     *
     * @return array<string, string>
     * @throws LocalizedException
     */
    private function collectParameters(InputInterface $input): array
    {
        $parameters = [];

        $file = $input->getOption(self::OPT_PARAMS_FILE);
        if (is_string($file) && $file !== '') {
            if (!is_readable($file)) {
                throw new LocalizedException(__('Cannot read params file "%1".', $file));
            }
            $decoded = json_decode((string) file_get_contents($file), true);
            if (!is_array($decoded)) {
                throw new LocalizedException(__('Params file "%1" must be a JSON object.', $file));
            }
            foreach ($decoded as $key => $value) {
                $parameters[(string) $key] = is_scalar($value) ? (string) $value : '';
            }
        }

        foreach ((array) $input->getOption(self::OPT_PARAM) as $pair) {
            $pair = (string) $pair;
            $eq = strpos($pair, '=');
            if ($eq === false) {
                throw new LocalizedException(__('Malformed --param "%1"; expected key=value.', $pair));
            }
            $parameters[substr($pair, 0, $eq)] = substr($pair, $eq + 1);
        }

        return $parameters;
    }

    private function statusLabel(int $status): string
    {
        return match ($status) {
            WorkflowInterface::STATUS_ENABLED => 'enabled',
            WorkflowInterface::STATUS_SHADOW => 'shadow',
            default => 'disabled',
        };
    }
}
