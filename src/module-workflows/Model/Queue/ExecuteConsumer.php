<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Queue;

use MageOS\Workflows\Model\Engine\Executor;
use Psr\Log\LoggerInterface;

/**
 * Consumer for the mageos.workflow.execute topic. Payload is the execution id
 * as a string. Terminal failures are handled inside the Executor (execution
 * marked failed, message acked); anything escaping it is retryable and is
 * rethrown so the queue redelivers.
 */
class ExecuteConsumer
{
    public function __construct(
        private readonly Executor $executor,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @throws \Throwable retryable failures rethrow for queue redelivery
     */
    public function process(string $executionId): void
    {
        try {
            $this->executor->execute((int) $executionId);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('Workflow execution %s hit a retryable failure: %s', $executionId, $e->getMessage()),
                ['exception' => $e]
            );
            throw $e;
        }
    }
}
