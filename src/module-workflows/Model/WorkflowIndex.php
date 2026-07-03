<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\ResourceModel\Workflow\CollectionFactory;

/**
 * Cache-backed index of active (enabled or shadow) workflows keyed by trigger,
 * so the event dispatcher answers "any workflows for this event?" without a DB
 * round-trip on every async event. Repository save/delete calls clean().
 */
class WorkflowIndex
{
    private const CACHE_KEY = 'mageos_workflows_trigger_index';
    private const CACHE_TAG = 'MAGEOS_WORKFLOWS';
    private const CACHE_LIFETIME = 86400;

    private const ACTIVE_STATUSES = [
        WorkflowInterface::STATUS_ENABLED,
        WorkflowInterface::STATUS_SHADOW,
    ];

    /**
     * @var array{event: array<string, int[]>, schedule: int[]}|null
     */
    private ?array $index = null;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * IDs of enabled/shadow workflows with trigger_type=event bound to the given event name
     *
     * @return int[]
     */
    public function getWorkflowIdsForEvent(string $eventName): array
    {
        return $this->getIndex()['event'][$eventName] ?? [];
    }

    /**
     * IDs of enabled/shadow workflows with trigger_type=schedule
     *
     * @return int[]
     */
    public function getScheduledWorkflowIds(): array
    {
        return $this->getIndex()['schedule'] ?? [];
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
     * @return array{event: array<string, int[]>, schedule: int[]}
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
                if (is_array($index) && isset($index['event'], $index['schedule'])) {
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
     * @return array{event: array<string, int[]>, schedule: int[]}
     */
    private function build(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToSelect([
            WorkflowInterface::WORKFLOW_ID,
            WorkflowInterface::TRIGGER_TYPE,
            WorkflowInterface::TRIGGER_REF,
        ]);
        $collection->addFieldToFilter(WorkflowInterface::STATUS, ['in' => self::ACTIVE_STATUSES]);
        $collection->addFieldToFilter(
            WorkflowInterface::TRIGGER_TYPE,
            ['in' => [WorkflowInterface::TRIGGER_TYPE_EVENT, WorkflowInterface::TRIGGER_TYPE_SCHEDULE]]
        );

        $index = ['event' => [], 'schedule' => []];
        foreach ($collection->getData() as $row) {
            $workflowId = (int)$row[WorkflowInterface::WORKFLOW_ID];
            if ($row[WorkflowInterface::TRIGGER_TYPE] === WorkflowInterface::TRIGGER_TYPE_EVENT) {
                $index['event'][(string)$row[WorkflowInterface::TRIGGER_REF]][] = $workflowId;
            } else {
                $index['schedule'][] = $workflowId;
            }
        }

        return $index;
    }
}
