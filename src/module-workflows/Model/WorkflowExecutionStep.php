<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model;

use Magento\Framework\Model\AbstractModel;
use MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface;
use MageOS\Workflows\Model\ResourceModel\WorkflowExecutionStep as WorkflowExecutionStepResource;

class WorkflowExecutionStep extends AbstractModel implements WorkflowExecutionStepInterface
{
    /**
     * @var string
     */
    protected $_eventPrefix = 'mageos_workflow_execution_step';

    /**
     * @var string
     */
    protected $_eventObject = 'execution_step';

    protected function _construct(): void
    {
        $this->_init(WorkflowExecutionStepResource::class);
    }

    public function getStepExecutionId(): ?int
    {
        $id = $this->getData(self::STEP_EXECUTION_ID);
        return $id === null ? null : (int)$id;
    }

    public function setStepExecutionId(int $id): WorkflowExecutionStepInterface
    {
        return $this->setData(self::STEP_EXECUTION_ID, $id);
    }

    public function getExecutionId(): int
    {
        return (int)$this->getData(self::EXECUTION_ID);
    }

    public function setExecutionId(int $executionId): WorkflowExecutionStepInterface
    {
        return $this->setData(self::EXECUTION_ID, $executionId);
    }

    public function getStepKey(): string
    {
        return (string)$this->getData(self::STEP_KEY);
    }

    public function setStepKey(string $stepKey): WorkflowExecutionStepInterface
    {
        return $this->setData(self::STEP_KEY, $stepKey);
    }

    public function getStatus(): string
    {
        return (string)$this->getData(self::STATUS);
    }

    public function setStatus(string $status): WorkflowExecutionStepInterface
    {
        return $this->setData(self::STATUS, $status);
    }

    public function getResult(): ?string
    {
        $result = $this->getData(self::RESULT);
        return $result === null ? null : (string)$result;
    }

    public function setResult(?string $result): WorkflowExecutionStepInterface
    {
        return $this->setData(self::RESULT, $result);
    }

    public function getError(): ?string
    {
        $error = $this->getData(self::ERROR);
        return $error === null ? null : (string)$error;
    }

    public function setError(?string $error): WorkflowExecutionStepInterface
    {
        return $this->setData(self::ERROR, $error);
    }

    public function getResumeAt(): ?string
    {
        $resumeAt = $this->getData(self::RESUME_AT);
        return $resumeAt === null ? null : (string)$resumeAt;
    }

    public function setResumeAt(?string $resumeAt): WorkflowExecutionStepInterface
    {
        return $this->setData(self::RESUME_AT, $resumeAt);
    }

    public function getClaimedAt(): ?string
    {
        $claimedAt = $this->getData(self::CLAIMED_AT);
        return $claimedAt === null ? null : (string)$claimedAt;
    }

    public function setClaimedAt(?string $claimedAt): WorkflowExecutionStepInterface
    {
        return $this->setData(self::CLAIMED_AT, $claimedAt);
    }

    public function getStartedAt(): ?string
    {
        $startedAt = $this->getData(self::STARTED_AT);
        return $startedAt === null ? null : (string)$startedAt;
    }

    public function setStartedAt(?string $startedAt): WorkflowExecutionStepInterface
    {
        return $this->setData(self::STARTED_AT, $startedAt);
    }

    public function getFinishedAt(): ?string
    {
        $finishedAt = $this->getData(self::FINISHED_AT);
        return $finishedAt === null ? null : (string)$finishedAt;
    }

    public function setFinishedAt(?string $finishedAt): WorkflowExecutionStepInterface
    {
        return $this->setData(self::FINISHED_AT, $finishedAt);
    }
}
