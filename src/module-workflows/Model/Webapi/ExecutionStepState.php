<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Webapi;

use MageOS\Workflows\Api\Data\ExecutionStepStateInterface;

/**
 * Immutable webapi DTO for one GET /V1/workflow-executions/:id/steps row (07).
 * A safe, narrow projection — see the interface for the security rationale.
 */
class ExecutionStepState implements ExecutionStepStateInterface
{
    public function __construct(
        private readonly string $stepKey,
        private readonly string $status,
        private readonly ?string $startedAt,
        private readonly ?string $finishedAt,
        private readonly ?string $edgeTaken,
        private readonly ?string $errorSummary
    ) {
    }

    public function getStepKey(): string
    {
        return $this->stepKey;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStartedAt(): ?string
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?string
    {
        return $this->finishedAt;
    }

    public function getEdgeTaken(): ?string
    {
        return $this->edgeTaken;
    }

    public function getErrorSummary(): ?string
    {
        return $this->errorSummary;
    }
}
