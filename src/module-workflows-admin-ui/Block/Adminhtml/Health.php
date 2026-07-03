<?php

declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use MageOS\Workflows\Model\Health\HealthCheck;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Compact health notice rendered above the workflow grid (mageos_workflows_workflow_index.xml)
 * to close the "first-run silently does nothing" gap: on a default install (no RabbitMQ,
 * consumers not running, cron not configured) workflows queue executions that nothing
 * consumes, and nothing tells the operator. See templates/health.phtml and
 * docs/15-operations.md.
 *
 * getChecks() never throws: a health check going sideways must never break the admin
 * grid page. Any failure while computing checks collapses to a single synthetic 'fail'
 * row explaining that the health check itself is broken.
 */
class Health extends Template
{
    /**
     * @var array<int, array{code: string, status: string, message: string}>|null
     */
    private ?array $checks = null;

    public function __construct(
        Context $context,
        private readonly HealthCheck $healthCheck,
        private readonly LoggerInterface $logger,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Cached per request. Never throws.
     *
     * @return array<int, array{code: string, status: string, message: string}>
     */
    public function getChecks(): array
    {
        if ($this->checks !== null) {
            return $this->checks;
        }

        try {
            $this->checks = $this->healthCheck->runChecks();
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('Workflow health block could not run health checks: %s', $e->getMessage()),
                ['exception' => $e]
            );
            $this->checks = [[
                'code' => 'health_check_error',
                'status' => HealthCheck::STATUS_FAIL,
                'message' => (string) __('Workflow health checks could not run. See the log for details.'),
            ]];
        }

        return $this->checks;
    }

    /**
     * @return array<int, array{code: string, status: string, message: string}>
     */
    public function getProblemChecks(): array
    {
        return array_values(array_filter(
            $this->getChecks(),
            static fn (array $check): bool => $check['status'] !== HealthCheck::STATUS_OK
        ));
    }

    public function hasProblems(): bool
    {
        return $this->getProblemChecks() !== [];
    }

    public function hasFailure(): bool
    {
        foreach ($this->getProblemChecks() as $check) {
            if ($check['status'] === HealthCheck::STATUS_FAIL) {
                return true;
            }
        }
        return false;
    }
}
