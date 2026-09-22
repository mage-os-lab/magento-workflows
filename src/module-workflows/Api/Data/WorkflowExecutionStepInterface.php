<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Api\Data;

interface WorkflowExecutionStepInterface
{
    public const STEP_EXECUTION_ID = 'step_execution_id';
    public const EXECUTION_ID = 'execution_id';
    public const STEP_KEY = 'step_key';
    public const STATUS = 'status';
    public const RESULT = 'result';
    public const ERROR = 'error';
    public const RESUME_AT = 'resume_at';
    public const CLAIMED_AT = 'claimed_at';
    public const STARTED_AT = 'started_at';
    public const FINISHED_AT = 'finished_at';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_WAITING = 'waiting';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public function getStepExecutionId(): ?int;

    public function setStepExecutionId(int $id): self;

    public function getExecutionId(): int;

    public function setExecutionId(int $executionId): self;

    public function getStepKey(): string;

    public function setStepKey(string $stepKey): self;

    public function getStatus(): string;

    public function setStatus(string $status): self;

    public function getResult(): ?string;

    public function setResult(?string $result): self;

    public function getError(): ?string;

    public function setError(?string $error): self;

    public function getResumeAt(): ?string;

    public function setResumeAt(?string $resumeAt): self;

    /**
     * When the step began executing (MySQL datetime), or null while pending.
     * Additive getter (canvas execution overlay, 07): the value column already
     * exists; this exposes it on the contract so the …/steps endpoint and any
     * client can derive per-step duration.
     */
    public function getStartedAt(): ?string;

    public function setStartedAt(?string $startedAt): self;

    /**
     * When the step reached a terminal status (complete/failed/skipped), or
     * null while still pending/running/waiting. Additive getter (07).
     */
    public function getFinishedAt(): ?string;

    public function setFinishedAt(?string $finishedAt): self;
}
