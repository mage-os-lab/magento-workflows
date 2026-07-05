<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model;

use MageOS\WorkflowsApprovals\Api\Data\ApprovalDecisionResultInterface;

/**
 * Immutable outcome of a decision, returned by ApprovalService::decide and
 * serialized by the REST decision endpoint.
 */
class ApprovalDecisionResult implements ApprovalDecisionResultInterface
{
    public function __construct(
        private readonly string $uuid,
        private readonly string $status,
        private readonly int $executionId
    ) {
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getExecutionId(): int
    {
        return $this->executionId;
    }
}
