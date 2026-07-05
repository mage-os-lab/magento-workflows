<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model;

use Magento\Framework\Model\AbstractModel;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;
use MageOS\WorkflowsApprovals\Model\ResourceModel\Approval as ApprovalResource;

class Approval extends AbstractModel implements ApprovalInterface
{
    /**
     * @var string
     */
    protected $_eventPrefix = 'mageos_workflow_approval';

    /**
     * @var string
     */
    protected $_eventObject = 'approval';

    protected function _construct(): void
    {
        $this->_init(ApprovalResource::class);
    }

    public function getUuid(): string
    {
        return (string) $this->getData(self::UUID);
    }

    public function setUuid(string $uuid): ApprovalInterface
    {
        return $this->setData(self::UUID, $uuid);
    }

    public function getExecutionId(): int
    {
        return (int) $this->getData(self::EXECUTION_ID);
    }

    public function setExecutionId(int $executionId): ApprovalInterface
    {
        return $this->setData(self::EXECUTION_ID, $executionId);
    }

    public function getStepKey(): string
    {
        return (string) $this->getData(self::STEP_KEY);
    }

    public function setStepKey(string $stepKey): ApprovalInterface
    {
        return $this->setData(self::STEP_KEY, $stepKey);
    }

    public function getWorkflowId(): int
    {
        return (int) $this->getData(self::WORKFLOW_ID);
    }

    public function setWorkflowId(int $workflowId): ApprovalInterface
    {
        return $this->setData(self::WORKFLOW_ID, $workflowId);
    }

    public function getEntityType(): string
    {
        return (string) $this->getData(self::ENTITY_TYPE);
    }

    public function setEntityType(string $entityType): ApprovalInterface
    {
        return $this->setData(self::ENTITY_TYPE, $entityType);
    }

    public function getEntityId(): int
    {
        return (int) $this->getData(self::ENTITY_ID);
    }

    public function setEntityId(int $entityId): ApprovalInterface
    {
        return $this->setData(self::ENTITY_ID, $entityId);
    }

    public function getTitle(): string
    {
        return (string) $this->getData(self::TITLE);
    }

    public function setTitle(string $title): ApprovalInterface
    {
        return $this->setData(self::TITLE, $title);
    }

    public function getInstructions(): ?string
    {
        $value = $this->getData(self::INSTRUCTIONS);
        return $value === null ? null : (string) $value;
    }

    public function setInstructions(?string $instructions): ApprovalInterface
    {
        return $this->setData(self::INSTRUCTIONS, $instructions);
    }

    public function getAssigneeRole(): ?string
    {
        $value = $this->getData(self::ASSIGNEE_ROLE);
        return $value === null || $value === '' ? null : (string) $value;
    }

    public function setAssigneeRole(?string $assigneeRole): ApprovalInterface
    {
        return $this->setData(self::ASSIGNEE_ROLE, $assigneeRole === '' ? null : $assigneeRole);
    }

    public function getStatus(): string
    {
        return (string) $this->getData(self::STATUS);
    }

    public function setStatus(string $status): ApprovalInterface
    {
        return $this->setData(self::STATUS, $status);
    }

    public function getDueAt(): ?string
    {
        $value = $this->getData(self::DUE_AT);
        return $value === null ? null : (string) $value;
    }

    public function setDueAt(?string $dueAt): ApprovalInterface
    {
        return $this->setData(self::DUE_AT, $dueAt);
    }

    public function getDecidedByType(): ?string
    {
        $value = $this->getData(self::DECIDED_BY_TYPE);
        return $value === null || $value === '' ? null : (string) $value;
    }

    public function setDecidedByType(?string $decidedByType): ApprovalInterface
    {
        return $this->setData(self::DECIDED_BY_TYPE, $decidedByType);
    }

    public function getDecidedById(): ?string
    {
        $value = $this->getData(self::DECIDED_BY_ID);
        return $value === null || $value === '' ? null : (string) $value;
    }

    public function setDecidedById(?string $decidedById): ApprovalInterface
    {
        return $this->setData(self::DECIDED_BY_ID, $decidedById);
    }

    public function getDecisionNote(): ?string
    {
        $value = $this->getData(self::DECISION_NOTE);
        return $value === null ? null : (string) $value;
    }

    public function setDecisionNote(?string $decisionNote): ApprovalInterface
    {
        return $this->setData(self::DECISION_NOTE, $decisionNote);
    }

    public function getDecisionPayload(): ?string
    {
        $value = $this->getData(self::DECISION_PAYLOAD);
        return $value === null ? null : (string) $value;
    }

    public function setDecisionPayload(?string $decisionPayload): ApprovalInterface
    {
        return $this->setData(self::DECISION_PAYLOAD, $decisionPayload);
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData(self::CREATED_AT);
        return $value === null ? null : (string) $value;
    }

    public function setCreatedAt(?string $createdAt): ApprovalInterface
    {
        return $this->setData(self::CREATED_AT, $createdAt);
    }

    public function getDecidedAt(): ?string
    {
        $value = $this->getData(self::DECIDED_AT);
        return $value === null ? null : (string) $value;
    }

    public function setDecidedAt(?string $decidedAt): ApprovalInterface
    {
        return $this->setData(self::DECIDED_AT, $decidedAt);
    }
}
