<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Stub;

use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;

/**
 * Minimal in-memory stand-in for WorkflowExecutionInterface, since Magento's
 * generated data model classes are not available outside a Magento install.
 * Only the accessors exercised by unit tests under Test/Unit are meaningfully
 * implemented; the rest are simple property round-trips.
 */
class WorkflowExecutionStub implements WorkflowExecutionInterface
{
    private ?int $executionId = null;
    private string $uuid = '';
    private int $workflowId = 0;
    private int $workflowVersion = 0;
    private string $definitionSnapshot = '';
    private int $entityId = 0;
    private int $storeId = 0;
    private string $status = self::STATUS_PENDING;
    private string $mode = self::MODE_LIVE;
    private ?string $context = null;
    private int $chainDepth = 0;
    private ?string $currentStep = null;
    private ?string $waitingEvent = null;
    private ?string $originUuid = null;

    public function __construct(
        string $uuid = 'test-uuid-0000',
        int $entityId = 1,
        int $storeId = 1
    ) {
        $this->uuid = $uuid;
        $this->entityId = $entityId;
        $this->storeId = $storeId;
    }

    public function getExecutionId(): ?int
    {
        return $this->executionId;
    }

    public function setExecutionId(int $executionId): self
    {
        $this->executionId = $executionId;
        return $this;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function setUuid(string $uuid): self
    {
        $this->uuid = $uuid;
        return $this;
    }

    public function getWorkflowId(): int
    {
        return $this->workflowId;
    }

    public function setWorkflowId(int $workflowId): self
    {
        $this->workflowId = $workflowId;
        return $this;
    }

    public function getWorkflowVersion(): int
    {
        return $this->workflowVersion;
    }

    public function setWorkflowVersion(int $version): self
    {
        $this->workflowVersion = $version;
        return $this;
    }

    public function getDefinitionSnapshot(): string
    {
        return $this->definitionSnapshot;
    }

    public function setDefinitionSnapshot(string $definition): self
    {
        $this->definitionSnapshot = $definition;
        return $this;
    }

    public function getEntityId(): int
    {
        return $this->entityId;
    }

    public function setEntityId(int $entityId): self
    {
        $this->entityId = $entityId;
        return $this;
    }

    public function getStoreId(): int
    {
        return $this->storeId;
    }

    public function setStoreId(int $storeId): self
    {
        $this->storeId = $storeId;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): self
    {
        $this->mode = $mode;
        return $this;
    }

    public function getContext(): ?string
    {
        return $this->context;
    }

    public function setContext(?string $context): self
    {
        $this->context = $context;
        return $this;
    }

    public function getChainDepth(): int
    {
        return $this->chainDepth;
    }

    public function setChainDepth(int $chainDepth): self
    {
        $this->chainDepth = $chainDepth;
        return $this;
    }

    public function getCurrentStep(): ?string
    {
        return $this->currentStep;
    }

    public function setCurrentStep(?string $stepKey): self
    {
        $this->currentStep = $stepKey;
        return $this;
    }

    public function getWaitingEvent(): ?string
    {
        return $this->waitingEvent;
    }

    public function setWaitingEvent(?string $event): self
    {
        $this->waitingEvent = $event === '' ? null : $event;
        return $this;
    }

    public function getOriginUuid(): ?string
    {
        return $this->originUuid;
    }

    public function setOriginUuid(?string $originUuid): self
    {
        $this->originUuid = $originUuid === '' ? null : $originUuid;
        return $this;
    }
}
