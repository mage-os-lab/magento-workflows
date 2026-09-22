<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model;

use Magento\Framework\Model\AbstractModel;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Model\ResourceModel\WorkflowExecution as WorkflowExecutionResource;

class WorkflowExecution extends AbstractModel implements WorkflowExecutionInterface
{
    /**
     * @var string
     */
    protected $_eventPrefix = 'mageos_workflow_execution';

    /**
     * @var string
     */
    protected $_eventObject = 'execution';

    protected function _construct(): void
    {
        $this->_init(WorkflowExecutionResource::class);
    }

    public function getExecutionId(): ?int
    {
        $id = $this->getData(self::EXECUTION_ID);
        return $id === null ? null : (int)$id;
    }

    public function setExecutionId(int $executionId): WorkflowExecutionInterface
    {
        return $this->setData(self::EXECUTION_ID, $executionId);
    }

    public function getUuid(): string
    {
        return (string)$this->getData(self::UUID);
    }

    public function setUuid(string $uuid): WorkflowExecutionInterface
    {
        return $this->setData(self::UUID, $uuid);
    }

    public function getWorkflowId(): int
    {
        return (int)$this->getData(self::WORKFLOW_ID);
    }

    public function setWorkflowId(int $workflowId): WorkflowExecutionInterface
    {
        return $this->setData(self::WORKFLOW_ID, $workflowId);
    }

    public function getWorkflowVersion(): int
    {
        return (int)$this->getData(self::WORKFLOW_VERSION);
    }

    public function setWorkflowVersion(int $version): WorkflowExecutionInterface
    {
        return $this->setData(self::WORKFLOW_VERSION, $version);
    }

    public function getDefinitionSnapshot(): string
    {
        return (string)$this->getData(self::DEFINITION_SNAPSHOT);
    }

    public function setDefinitionSnapshot(string $definition): WorkflowExecutionInterface
    {
        return $this->setData(self::DEFINITION_SNAPSHOT, $definition);
    }

    public function getEntityId(): int
    {
        return (int)$this->getData(self::ENTITY_ID);
    }

    /**
     * @param int $entityId
     * @return WorkflowExecutionInterface
     */
    public function setEntityId($entityId): WorkflowExecutionInterface
    {
        return $this->setData(self::ENTITY_ID, (int)$entityId);
    }

    public function getStoreId(): int
    {
        return (int)$this->getData(self::STORE_ID);
    }

    public function setStoreId(int $storeId): WorkflowExecutionInterface
    {
        return $this->setData(self::STORE_ID, $storeId);
    }

    public function getStatus(): string
    {
        return (string)$this->getData(self::STATUS);
    }

    public function setStatus(string $status): WorkflowExecutionInterface
    {
        return $this->setData(self::STATUS, $status);
    }

    public function getTriggerType(): ?string
    {
        $triggerType = $this->getData(self::TRIGGER_TYPE);
        return $triggerType === null || $triggerType === '' ? null : (string)$triggerType;
    }

    public function setTriggerType(?string $triggerType): WorkflowExecutionInterface
    {
        return $this->setData(self::TRIGGER_TYPE, $triggerType === '' ? null : $triggerType);
    }

    public function getMode(): string
    {
        $mode = $this->getData(self::MODE);
        return $mode !== null && $mode !== '' ? (string)$mode : self::MODE_LIVE;
    }

    public function setMode(string $mode): WorkflowExecutionInterface
    {
        return $this->setData(self::MODE, $mode);
    }

    public function getContext(): ?string
    {
        $context = $this->getData(self::CONTEXT);
        return $context === null ? null : (string)$context;
    }

    public function setContext(?string $context): WorkflowExecutionInterface
    {
        return $this->setData(self::CONTEXT, $context);
    }

    public function getChainDepth(): int
    {
        return (int)$this->getData(self::CHAIN_DEPTH);
    }

    public function setChainDepth(int $chainDepth): WorkflowExecutionInterface
    {
        return $this->setData(self::CHAIN_DEPTH, $chainDepth);
    }

    public function getCurrentStep(): ?string
    {
        $stepKey = $this->getData(self::CURRENT_STEP);
        return $stepKey === null ? null : (string)$stepKey;
    }

    public function setCurrentStep(?string $stepKey): WorkflowExecutionInterface
    {
        return $this->setData(self::CURRENT_STEP, $stepKey);
    }

    public function getWaitingEvent(): ?string
    {
        $event = $this->getData(self::WAITING_EVENT);
        return $event === null || $event === '' ? null : (string)$event;
    }

    public function setWaitingEvent(?string $event): WorkflowExecutionInterface
    {
        return $this->setData(self::WAITING_EVENT, $event === '' ? null : $event);
    }

    public function getOriginUuid(): ?string
    {
        $originUuid = $this->getData(self::ORIGIN_UUID);
        return $originUuid === null || $originUuid === '' ? null : (string)$originUuid;
    }

    public function setOriginUuid(?string $originUuid): WorkflowExecutionInterface
    {
        return $this->setData(self::ORIGIN_UUID, $originUuid === '' ? null : $originUuid);
    }

    public function getTriggeredAt(): ?string
    {
        $triggeredAt = $this->getData(self::TRIGGERED_AT);
        return $triggeredAt === null ? null : (string)$triggeredAt;
    }

    public function setTriggeredAt(?string $triggeredAt): WorkflowExecutionInterface
    {
        return $this->setData(self::TRIGGERED_AT, $triggeredAt);
    }

    public function getCompletedAt(): ?string
    {
        $completedAt = $this->getData(self::COMPLETED_AT);
        return $completedAt === null ? null : (string)$completedAt;
    }

    public function setCompletedAt(?string $completedAt): WorkflowExecutionInterface
    {
        return $this->setData(self::COMPLETED_AT, $completedAt);
    }
}
