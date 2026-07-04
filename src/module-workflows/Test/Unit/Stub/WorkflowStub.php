<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Stub;

use MageOS\Workflows\Api\Data\WorkflowInterface;

/**
 * Minimal in-memory WorkflowInterface stand-in for unit tests (Magento's
 * generated data models are unavailable outside an install). Property
 * round-trips; only the accessors the tests exercise carry non-default seeds.
 */
class WorkflowStub implements WorkflowInterface
{
    private ?int $workflowId;
    private string $name = '';
    private int $status = self::STATUS_ENABLED;
    private string $triggerType = self::TRIGGER_TYPE_EVENT;
    private string $triggerRef = '';
    private string $entityType = '';
    private ?string $conditionsSerialized = null;
    private string $definition = '{"schema":3,"steps":[],"entry":null}';
    private int $version = 1;
    private int $loopGuardDepth = 1;
    private ?string $fanOut = null;
    /** @var int[] */
    private array $websiteIds = [];

    public function __construct(?int $workflowId = 1)
    {
        $this->workflowId = $workflowId;
    }

    public function getWorkflowId(): ?int
    {
        return $this->workflowId;
    }

    public function setWorkflowId(int $workflowId): self
    {
        $this->workflowId = $workflowId;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getTriggerType(): string
    {
        return $this->triggerType;
    }

    public function setTriggerType(string $triggerType): self
    {
        $this->triggerType = $triggerType;
        return $this;
    }

    public function getTriggerRef(): string
    {
        return $this->triggerRef;
    }

    public function setTriggerRef(string $triggerRef): self
    {
        $this->triggerRef = $triggerRef;
        return $this;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function setEntityType(string $entityType): self
    {
        $this->entityType = $entityType;
        return $this;
    }

    public function getConditionsSerialized(): ?string
    {
        return $this->conditionsSerialized;
    }

    public function setConditionsSerialized(?string $conditions): self
    {
        $this->conditionsSerialized = $conditions;
        return $this;
    }

    public function getDefinition(): string
    {
        return $this->definition;
    }

    public function setDefinition(string $definition): self
    {
        $this->definition = $definition;
        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): self
    {
        $this->version = $version;
        return $this;
    }

    public function getLoopGuardDepth(): int
    {
        return $this->loopGuardDepth;
    }

    public function setLoopGuardDepth(int $depth): self
    {
        $this->loopGuardDepth = $depth;
        return $this;
    }

    public function getFanOut(): ?string
    {
        return $this->fanOut;
    }

    public function setFanOut(?string $fanOut): self
    {
        $this->fanOut = $fanOut === '' ? null : $fanOut;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getWebsiteIds(): array
    {
        return $this->websiteIds;
    }

    /**
     * @inheritDoc
     */
    public function setWebsiteIds(array $websiteIds): self
    {
        $this->websiteIds = array_values(array_map('intval', $websiteIds));
        return $this;
    }
}
