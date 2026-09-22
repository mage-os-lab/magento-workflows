<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\ResourceModel\Workflow\CollectionFactory;

/**
 * Cache-backed index of enabled/shadow schedule-type workflow IDs, so
 * RunScheduledWorkflows (which runs every minute) can skip its repository
 * query entirely on ticks where a store has no schedule workflows at all.
 * Repository save/delete calls clean() to invalidate.
 *
 * This index does NOT cover event-type workflows: event dispatch is resolved
 * per-subscription by the async-events recipients (WorkflowNotifier), not by
 * an "any workflows for this event?" lookup, so an event half of this index
 * would have no caller.
 */
class WorkflowIndex
{
    private const CACHE_KEY = 'mageos_workflows_schedule_index';
    private const CACHE_TAG = 'MAGEOS_WORKFLOWS';
    private const CACHE_LIFETIME = 86400;

    private const ACTIVE_STATUSES = [
        WorkflowInterface::STATUS_ENABLED,
        WorkflowInterface::STATUS_SHADOW,
    ];

    /**
     * @var int[]|null
     */
    private ?array $index = null;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * IDs of enabled/shadow workflows with trigger_type=schedule
     *
     * @return int[]
     */
    public function getScheduledWorkflowIds(): array
    {
        return $this->getIndex();
    }

    /**
     * Invalidate the index; called by the repository on save/delete
     */
    public function clean(): void
    {
        $this->index = null;
        $this->cache->remove(self::CACHE_KEY);
    }

    /**
     * @return int[]
     */
    private function getIndex(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $cached = $this->cache->load(self::CACHE_KEY);
        if ($cached) {
            try {
                $index = $this->serializer->unserialize($cached);
                if (is_array($index)) {
                    $this->index = $index;
                    return $this->index;
                }
            } catch (\InvalidArgumentException $e) {
                // corrupt cache entry: fall through and rebuild
            }
        }

        $this->index = $this->build();
        $this->cache->save(
            $this->serializer->serialize($this->index),
            self::CACHE_KEY,
            [self::CACHE_TAG],
            self::CACHE_LIFETIME
        );

        return $this->index;
    }

    /**
     * @return int[]
     */
    private function build(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToSelect(WorkflowInterface::WORKFLOW_ID);
        $collection->addFieldToFilter(WorkflowInterface::STATUS, ['in' => self::ACTIVE_STATUSES]);
        $collection->addFieldToFilter(WorkflowInterface::TRIGGER_TYPE, WorkflowInterface::TRIGGER_TYPE_SCHEDULE);

        $ids = [];
        foreach ($collection->getData() as $row) {
            $ids[] = (int)$row[WorkflowInterface::WORKFLOW_ID];
        }

        return $ids;
    }
}
