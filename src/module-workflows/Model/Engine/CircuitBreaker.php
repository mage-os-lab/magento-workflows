<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Engine;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Per-workflow consecutive-failure circuit breaker (docs/07-actions.md).
 *
 * N consecutive step failures (default 10, config
 * mageos_workflows/guards/circuit_breaker_threshold) auto-pauses the workflow
 * (status = suspended), logs critical and raises an admin notification.
 * A misconfigured webhook must not silently burn the retry queue for days.
 */
class CircuitBreaker
{
    public const CONFIG_THRESHOLD = 'mageos_workflows/guards/circuit_breaker_threshold';
    public const DEFAULT_THRESHOLD = 10;

    private const CACHE_KEY_PREFIX = 'mageos_workflows_cb_failures_';
    private const CACHE_LIFETIME = 86400;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WorkflowRepositoryInterface $workflowRepository,
        private readonly \Magento\Framework\Notification\NotifierInterface $notifier,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Record a step failure; suspend the workflow once the threshold is reached.
     */
    public function recordFailure(int $workflowId): void
    {
        $count = $this->getFailureCount($workflowId) + 1;
        $this->cache->save((string) $count, $this->getCacheKey($workflowId), [], self::CACHE_LIFETIME);

        if ($count >= $this->getThreshold()) {
            $this->trip($workflowId, $count);
        }
    }

    /**
     * Record a successful step; resets the consecutive-failure counter.
     */
    public function recordSuccess(int $workflowId): void
    {
        $this->cache->remove($this->getCacheKey($workflowId));
    }

    public function getFailureCount(int $workflowId): int
    {
        $cached = $this->cache->load($this->getCacheKey($workflowId));
        return $cached === false ? 0 : (int) $cached;
    }

    private function trip(int $workflowId, int $count): void
    {
        $name = (string) $workflowId;
        try {
            $workflow = $this->workflowRepository->getById($workflowId);
            $name = $workflow->getName();
            if ($workflow->getStatus() !== WorkflowInterface::STATUS_SUSPENDED) {
                $workflow->setStatus(WorkflowInterface::STATUS_SUSPENDED);
                $this->workflowRepository->save($workflow);
            }
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf(
                    'Workflow circuit breaker could not suspend workflow %d: %s',
                    $workflowId,
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
        }

        $this->cache->remove($this->getCacheKey($workflowId));

        $this->logger->critical(
            sprintf(
                'Workflow %d ("%s") suspended by circuit breaker after %d consecutive step failures',
                $workflowId,
                $name,
                $count
            )
        );

        try {
            $this->notifier->addCritical(
                sprintf('Workflow "%s" was suspended', $name),
                sprintf(
                    'Workflow "%s" (ID %d) was automatically suspended after %d consecutive step failures. '
                    . 'Review its recent executions and re-enable it explicitly once the cause is fixed.',
                    $name,
                    $workflowId,
                    $count
                )
            );
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Workflow circuit breaker could not add admin notification: %s', $e->getMessage()),
                ['exception' => $e]
            );
        }
    }

    private function getThreshold(): int
    {
        $threshold = (int) $this->scopeConfig->getValue(self::CONFIG_THRESHOLD);
        return $threshold > 0 ? $threshold : self::DEFAULT_THRESHOLD;
    }

    private function getCacheKey(int $workflowId): string
    {
        return self::CACHE_KEY_PREFIX . $workflowId;
    }
}
