<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\Workflows\Console\Command;

use MageOS\Workflows\Model\Variable\SecretsProviderInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento workflow:secret:list` — key names only, one per line.
 *
 * Values are write-only and never re-displayed (docs/10-security.md,
 * "Secrets"): this command must NEVER print a secret value, only the names
 * returned by SecretsProviderInterface::listKeys() (the same surface exposed
 * over the webapi at GET /V1/workflows/meta/secrets).
 *
 * Depends on SecretsProviderInterface (the ConfigSecretsProvider preference
 * in etc/di.xml), never the concrete class.
 */
class SecretListCommand extends Command
{
    public function __construct(
        private readonly SecretsProviderInterface $secretsProvider,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('workflow:secret:list')
            ->setDescription('List configured secret key names. Never prints values.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $keys = $this->secretsProvider->listKeys();

        if ($keys === []) {
            $output->writeln('<comment>No secrets configured.</comment>');
            return Command::SUCCESS;
        }

        foreach ($keys as $key) {
            $output->writeln($key);
        }

        return Command::SUCCESS;
    }
}
