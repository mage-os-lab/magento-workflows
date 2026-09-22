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
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento workflow:secret:delete <key>` — removes a secret. Any
 * definition still referencing {{ secrets.<key> }} resolves it to null
 * afterward (VariableResolver's documented behavior for an unresolved
 * secret), it does not fail the workflow. See docs/10-security.md
 * ("Secrets") for the write-only value contract this whole command group
 * exists to fill (GitHub issue #3: set()/delete() had no entry point).
 *
 * Depends on SecretsProviderInterface (the ConfigSecretsProvider preference
 * in etc/di.xml), never the concrete class.
 */
class SecretDeleteCommand extends Command
{
    private const ARG_KEY = 'key';

    public function __construct(
        private readonly SecretsProviderInterface $secretsProvider,
        private readonly SecretKeyValidator $keyValidator,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('workflow:secret:delete')
            ->setDescription('Delete a secret. Definitions referencing it afterward resolve it to null.')
            ->addArgument(self::ARG_KEY, InputArgument::REQUIRED, 'Secret key name');
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

        // ConfigSecretsProvider::delete() is unconditional and reports no
        // rows-affected count, but existence is detectable via get() first.
        $existed = $this->secretsProvider->get($key) !== null;

        $this->secretsProvider->delete($key);

        if ($existed) {
            $output->writeln(sprintf('<info>Secret "%s" deleted.</info>', $key));
        } else {
            $output->writeln(sprintf('<comment>Secret "%s" did not exist; nothing to delete.</comment>', $key));
        }

        return Command::SUCCESS;
    }
}
