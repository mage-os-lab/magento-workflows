<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\Workflows\Console\Command;

use MageOS\Workflows\Model\Secrets\SecretKeyValidator;
use MageOS\Workflows\Model\Variable\SecretsProviderInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * `bin/magento workflow:secret:set <key>` — the missing entry point for
 * ConfigSecretsProvider::set() (GitHub issue #3): before this command,
 * secrets were readable via {{ secrets.* }} and listable via
 * GET /V1/workflows/meta/secrets, but nothing could create or rotate one.
 * Creating and rotating are the same operation — set() overwrites.
 *
 * Depends on SecretsProviderInterface (the ConfigSecretsProvider preference
 * in etc/di.xml), never the concrete class, so this command follows whatever
 * the DI preference resolves to.
 *
 * Values are write-only (docs/10-security.md, "Secrets"): the value is
 * never echoed back, never logged, and by default is read via a hidden
 * interactive prompt so it doesn't land in shell history or terminal
 * scrollback. --value is a non-interactive escape hatch for scripting and
 * prints a warning that the value will be visible in shell history.
 */
class SecretSetCommand extends Command
{
    private const ARG_KEY = 'key';
    private const OPT_VALUE = 'value';

    public function __construct(
        private readonly SecretsProviderInterface $secretsProvider,
        private readonly SecretKeyValidator $keyValidator,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('workflow:secret:set')
            ->setDescription(
                'Create or rotate a secret referenced from definitions as {{ secrets.<key> }}. '
                . 'Prompts for the value interactively (hidden input) unless --value is given.'
            )
            ->addArgument(self::ARG_KEY, InputArgument::REQUIRED, 'Secret key name')
            ->addOption(
                self::OPT_VALUE,
                null,
                InputOption::VALUE_REQUIRED,
                'Secret value, non-interactive. WARNING: this will be visible in shell history '
                . 'and process listings; prefer the interactive prompt.'
            );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = (string) $input->getArgument(self::ARG_KEY);
        if (!$this->keyValidator->isValid($key)) {
            $output->writeln(sprintf(
                '<error>Invalid secret key "%s". Allowed: %s.</error>',
                $key,
                SecretKeyValidator::DESCRIPTION
            ));
            return Command::FAILURE;
        }

        $valueOption = $input->getOption(self::OPT_VALUE);
        if ($valueOption !== null) {
            $output->writeln(
                '<comment>Warning: --value is visible in shell history and process listings. '
                . 'Prefer the interactive prompt for real secrets.</comment>'
            );
            $value = (string) $valueOption;
        } else {
            $question = new Question('Secret value (input hidden): ');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $helper = $this->getHelper('question');
            $value = (string) $helper->ask($input, $output, $question);
        }

        if ($value === '') {
            $output->writeln('<error>Secret value must not be empty.</error>');
            return Command::FAILURE;
        }

        $this->secretsProvider->set($key, $value);

        $output->writeln(sprintf('<info>Secret "%s" saved.</info>', $key));
        return Command::SUCCESS;
    }
}
