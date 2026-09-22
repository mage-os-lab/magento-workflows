<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model;

use Magento\Framework\Model\AbstractModel;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\ResourceModel\Workflow as WorkflowResource;

class Workflow extends AbstractModel implements WorkflowInterface
{
    /**
     * Data key populated by the resource model from mageos_workflow_website
     */
    public const WEBSITE_IDS = 'website_ids';

    /**
     * @var string
     */
    protected $_eventPrefix = 'mageos_workflow';

    /**
     * @var string
     */
    protected $_eventObject = 'workflow';

    protected function _construct(): void
    {
        $this->_init(WorkflowResource::class);
    }

    public function getWorkflowId(): ?int
    {
        $id = $this->getData(self::WORKFLOW_ID);
        return $id === null ? null : (int)$id;
    }

    public function setWorkflowId(int $workflowId): WorkflowInterface
    {
        return $this->setData(self::WORKFLOW_ID, $workflowId);
    }

    public function getName(): string
    {
        return (string)$this->getData(self::NAME);
    }

    public function setName(string $name): WorkflowInterface
    {
        return $this->setData(self::NAME, $name);
    }

    public function getStatus(): int
    {
        return (int)$this->getData(self::STATUS);
    }

    public function setStatus(int $status): WorkflowInterface
    {
        return $this->setData(self::STATUS, $status);
    }

    public function getTriggerType(): string
    {
        return (string)$this->getData(self::TRIGGER_TYPE);
    }

    public function setTriggerType(string $triggerType): WorkflowInterface
    {
        return $this->setData(self::TRIGGER_TYPE, $triggerType);
    }

    public function getTriggerRef(): string
    {
        return (string)$this->getData(self::TRIGGER_REF);
    }

    public function setTriggerRef(string $triggerRef): WorkflowInterface
    {
        return $this->setData(self::TRIGGER_REF, $triggerRef);
    }

    public function getEntityType(): string
    {
        return (string)$this->getData(self::ENTITY_TYPE);
    }

    public function setEntityType(string $entityType): WorkflowInterface
    {
        return $this->setData(self::ENTITY_TYPE, $entityType);
    }

    public function getConditionsSerialized(): ?string
    {
        $conditions = $this->getData(self::CONDITIONS_SERIALIZED);
        return $conditions === null ? null : (string)$conditions;
    }

    public function setConditionsSerialized(?string $conditions): WorkflowInterface
    {
        return $this->setData(self::CONDITIONS_SERIALIZED, $conditions);
    }

    public function getDefinition(): string
    {
        return (string)$this->getData(self::DEFINITION);
    }

    public function setDefinition(string $definition): WorkflowInterface
    {
        return $this->setData(self::DEFINITION, $definition);
    }

    public function getAggregation(): ?string
    {
        $aggregation = $this->getData(self::AGGREGATION);
        return $aggregation === null || $aggregation === '' ? null : (string)$aggregation;
    }

    public function setAggregation(?string $aggregation): WorkflowInterface
    {
        return $this->setData(self::AGGREGATION, $aggregation);
    }

    public function getVersion(): int
    {
        $version = $this->getData(self::VERSION);
        return $version === null ? 1 : (int)$version;
    }

    public function setVersion(int $version): WorkflowInterface
    {
        return $this->setData(self::VERSION, $version);
    }

    public function getLoopGuardDepth(): int
    {
        $depth = $this->getData(self::LOOP_GUARD_DEPTH);
        return $depth === null ? 1 : (int)$depth;
    }

    public function setLoopGuardDepth(int $depth): WorkflowInterface
    {
        return $this->setData(self::LOOP_GUARD_DEPTH, $depth);
    }

    public function getFanOut(): ?string
    {
        $fanOut = $this->getData(self::FAN_OUT);
        if ($fanOut === null || $fanOut === '') {
            return null;
        }
        return is_array($fanOut) ? (string) json_encode($fanOut) : (string) $fanOut;
    }

    public function setFanOut(?string $fanOut): WorkflowInterface
    {
        return $this->setData(self::FAN_OUT, $fanOut === '' ? null : $fanOut);
    }

    /**
     * @inheritDoc
     */
    public function getWebsiteIds(): array
    {
        $websiteIds = $this->getData(self::WEBSITE_IDS);
        if ($websiteIds === null || $websiteIds === '') {
            return [];
        }
        if (!is_array($websiteIds)) {
            $websiteIds = explode(',', (string)$websiteIds);
        }
        return array_values(array_map('intval', $websiteIds));
    }

    /**
     * @inheritDoc
     */
    public function setWebsiteIds(array $websiteIds): WorkflowInterface
    {
        return $this->setData(self::WEBSITE_IDS, array_values(array_map('intval', $websiteIds)));
    }
}
