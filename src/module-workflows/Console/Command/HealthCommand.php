<?php

declare(strict_types=1);

namespace MageOS\Workflows\Console\Command;

use MageOS\Workflows\Model\Health\HealthCheck;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `bin/magento workflow:health` — prints MageOS\Workflows\Model\Health\HealthCheck::runChecks()
 * as a table (see docs/15-operations.md for what each check means and how to fix it).
 *
 * Exists to close the "first-run silently does nothing" gap: a default install queues
 * executions with no RabbitMQ, no running consumers, and possibly no cron, and nothing
 * tells the operator. This command is the CLI half of that visibility (the admin grid
 * notice, Block\Adminhtml\Health in module-workflows-admin-ui, is the other half).
 *
 * Exit code is 1 if any check is 'fail', 0 otherwise (warnings do not fail the command).
 */
class HealthCommand extends Command
{
    private const STATUS_LABELS = [
        HealthCheck::STATUS_OK => '<info>OK</info>',
        HealthCheck::STATUS_WARN => '<comment>WARN</comment>',
        HealthCheck::STATUS_FAIL => '<error>FAIL</error>',
    ];

    public function __construct(
        private readonly HealthCheck $healthCheck,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('workflow:health')
            ->setDescription(
                'Report operational health of the workflow engine: queue backend, cron, '
                . 'stuck executions, overdue resumes, and the async-events dependency.'
            );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $checks = $this->healthCheck->runChecks();

        $rows = [];
        $hasFailure = false;
        foreach ($checks as $check) {
            $status = $check['status'];
            if ($status === HealthCheck::STATUS_FAIL) {
                $hasFailure = true;
            }
            $rows[] = [
                $check['code'],
                self::STATUS_LABELS[$status] ?? $status,
                $check['message'],
            ];
        }

        $table = new Table($output);
        $table->setHeaders(['Check', 'Status', 'Message'])
            ->setRows($rows)
            ->render();

        if ($hasFailure) {
            $io->error('One or more workflow health checks failed. See docs/15-operations.md for remediation.');
            return Command::FAILURE;
        }

        $io->success('No failing workflow health checks.');
        return Command::SUCCESS;
    }
}
