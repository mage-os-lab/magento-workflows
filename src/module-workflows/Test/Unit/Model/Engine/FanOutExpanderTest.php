<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Engine;

use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\DispatcherInterface;
use MageOS\Workflows\Api\RelationInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\Engine\FanOutExpander;
use MageOS\Workflows\Model\Relation\RelationContext;
use MageOS\Workflows\Model\Relation\RelationPool;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;
use MageOS\Workflows\Test\Unit\Stub\StubHydrationProvider;
use MageOS\Workflows\Test\Unit\Stub\StubScopeConfig;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\Workflows\Test\Unit\Stub\WorkflowStub;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * FanOutExpander behaviour: relation-driven expansion into per-target
 * executions, the storm/debounce collapse, the mid-expansion failure policy,
 * cap/truncation, the empty relation, origin injection and snapshot parity.
 */
class FanOutExpanderTest extends TestCase
{
    public const CUSTOMER = HydrationProviderInterface::TYPE_CUSTOMER;
    public const ORDER = HydrationProviderInterface::TYPE_ORDER;

    /**
     * A `customer.open_orders`-shaped relation (customer -> sales_order, many)
     * resolving to the given ids.
     *
     * @param int[] $ids
     */
    private function relation(array $ids): RelationInterface
    {
        return new class ($ids) implements RelationInterface {
            /** @param int[] $ids */
            public function __construct(private readonly array $ids)
            {
            }

            public function getCode(): string
            {
                return 'customer.open_orders';
            }

            public function getLabel(): string
            {
                return "the customer's open orders";
            }

            public function getSourceEntityType(): string
            {
                return FanOutExpanderTest::CUSTOMER;
            }

            public function getTargetEntityType(): string
            {
                return FanOutExpanderTest::ORDER;
            }

            public function getCardinality(): string
            {
                return self::CARDINALITY_MANY;
            }

            public function resolveIds(DataObject $source, ?int $websiteId): array
            {
                return $this->ids;
            }
        };
    }

    /**
     * Order snapshots for the given ids, keyed "sales_order:<id>" as the
     * hydration provider expects — each carrying its own entity_id/store_id so
     * the child snapshot mirrors what the order's own event would produce.
     *
     * @param int[] $ids
     * @return array<string, DataObject>
     */
    private function orderEntities(array $ids): array
    {
        $entities = [];
        foreach ($ids as $id) {
            $entities[self::ORDER . ':' . $id] = new DataObject([
                'entity_id' => $id,
                'store_id' => 1,
                'increment_id' => '10000' . $id,
                'state' => 'processing',
            ]);
        }
        return $entities;
    }

    private function relationContext(RelationInterface $relation, array $config = []): RelationContext
    {
        $storeManager = new class implements StoreManagerInterface {
            public function getStore($storeId = null)
            {
                return new DataObject(['website_id' => 1]);
            }

            public function setIsSingleStoreModeAllowed($value)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function hasSingleStore()
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function isSingleStoreMode()
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function getStores($withDefault = false, $codeKey = false)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function getWebsite($websiteId = null)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function getWebsites($withDefault = false, $codeKey = false)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function reinitStores()
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function getDefaultStoreView()
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function getGroup($groupId = null)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function getGroups($withDefault = false)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function setCurrentStore($store)
            {
                throw new \BadMethodCallException(__METHOD__);
            }
        };
        return new RelationContext(
            new RelationPool(['customer.open_orders' => $relation]),
            $storeManager,
            new StubScopeConfig($config),
            new NullLogger()
        );
    }

    private function repository(WorkflowInterface $workflow): WorkflowRepositoryInterface
    {
        return new class ($workflow) implements WorkflowRepositoryInterface {
            public function __construct(private readonly WorkflowInterface $workflow)
            {
            }

            public function save(WorkflowInterface $workflow): WorkflowInterface
            {
                return $workflow;
            }

            public function getById(int $workflowId): WorkflowInterface
            {
                if ($this->workflow->getWorkflowId() === null) {
                    throw new NoSuchEntityException();
                }
                return $this->workflow;
            }

            public function getList(
                \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
            ): \Magento\Framework\Api\SearchResultsInterface {
                throw new \RuntimeException('not used');
            }

            public function delete(WorkflowInterface $workflow): bool
            {
                return true;
            }

            public function deleteById(int $workflowId): bool
            {
                return true;
            }
        };
    }

    /**
     * A dispatcher spy that records each dispatched payload and models the
     * per-child debounce (a (workflow_id, entity_id) already seen returns null),
     * and can be told to throw for one entity id (a mid-expansion child failure).
     */
    private function dispatcher(?int $throwOnEntityId = null): DispatcherInterface
    {
        return new class ($throwOnEntityId) implements DispatcherInterface {
            /** @var array<int, array> */
            public array $payloads = [];
            /** @var array<string, true> */
            private array $seen = [];
            public int $created = 0;

            public function __construct(private readonly ?int $throwOnEntityId)
            {
            }

            public function dispatch(
                int $workflowId,
                array $triggerPayload,
                string $triggerType = 'event',
                int $chainDepth = 0
            ): ?WorkflowExecutionInterface {
                $entityId = (int) ($triggerPayload['entity_id'] ?? 0);
                if ($this->throwOnEntityId !== null && $entityId === $this->throwOnEntityId) {
                    throw new \RuntimeException('child dispatch exploded');
                }
                $this->payloads[] = $triggerPayload;
                $key = $workflowId . ':' . $entityId;
                if (isset($this->seen[$key])) {
                    return null; // debounced
                }
                $this->seen[$key] = true;
                $this->created++;
                return (new WorkflowExecutionStub())->setWorkflowId($workflowId)->setEntityId($entityId);
            }

            public function resumeWaiting(int $workflowId, string $event, array $eventPayload): int
            {
                return 0;
            }
        };
    }

    private function expander(
        WorkflowInterface $workflow,
        RelationInterface $relation,
        DispatcherInterface $dispatcher,
        array $entities,
        array $config = []
    ): FanOutExpander {
        return new FanOutExpander(
            $this->repository($workflow),
            new RelationPool(['customer.open_orders' => $relation]),
            $this->relationContext($relation, $config),
            new StubHydrationProvider($entities),
            $dispatcher,
            new DataObjectFactory(),
            new StubScopeConfig($config),
            new NullLogger()
        );
    }

    private function fanOutWorkflow(?int $cap = null): WorkflowStub
    {
        $config = ['relation' => 'customer.open_orders'];
        if ($cap !== null) {
            $config['cap'] = $cap;
        }
        return (new WorkflowStub(42))
            ->setTriggerType(WorkflowInterface::TRIGGER_TYPE_EVENT)
            ->setTriggerRef('customer.group_changed')
            ->setEntityType(self::ORDER)
            ->setFanOut((string) json_encode($config));
    }

    public function testNoFanOutClauseReturnsNull(): void
    {
        $workflow = (new WorkflowStub(42))->setFanOut(null);
        $relation = $this->relation([1, 2, 3]);
        $dispatcher = $this->dispatcher();
        $expander = $this->expander($workflow, $relation, $dispatcher, $this->orderEntities([1, 2, 3]));

        $result = $expander->expand(42, ['entity_id' => 7], 'customer.group_changed', 'trace-abc');

        $this->assertNull($result);
        $this->assertSame(0, $dispatcher->created);
    }

    public function testExpandsOnePerTargetWithOrigin(): void
    {
        $ids = [11, 12, 13];
        $workflow = $this->fanOutWorkflow();
        $dispatcher = $this->dispatcher();
        $expander = $this->expander($workflow, $this->relation($ids), $dispatcher, $this->orderEntities($ids));

        $result = $expander->expand(42, ['entity_id' => 7, 'store_id' => 1], 'customer.group_changed', 'trace-abc');

        $this->assertNotNull($result);
        $this->assertSame(3, $result->getDispatched());
        $this->assertSame(0, $result->getSkipped());
        $this->assertFalse($result->isTruncated());
        $this->assertSame(3, $dispatcher->created);

        // Each child carries the origin and the target's own entity_id.
        foreach ($dispatcher->payloads as $index => $payload) {
            $this->assertSame($ids[$index], $payload['entity_id']);
            $this->assertArrayHasKey('origin', $payload);
            $this->assertSame('customer.group_changed', $payload['origin']['event']);
            $this->assertSame(self::CUSTOMER, $payload['origin']['entity_type']);
            $this->assertSame(7, $payload['origin']['entity_id']);
            $this->assertSame('fan_out', $payload['origin']['via']);
            $this->assertSame('trace-abc', $payload['origin']['trace_uuid']);
        }
    }

    public function testChildSnapshotParityWithHydratedEntity(): void
    {
        $ids = [11];
        $entities = $this->orderEntities($ids);
        $workflow = $this->fanOutWorkflow();
        $dispatcher = $this->dispatcher();
        $expander = $this->expander($workflow, $this->relation($ids), $dispatcher, $entities);

        $expander->expand(42, ['entity_id' => 7], 'customer.group_changed', 'trace-abc');

        $payload = $dispatcher->payloads[0];
        unset($payload['origin']);
        // The child snapshot is exactly the hydrated order data (parity): the
        // expander-built snapshot == what the order's own event would carry.
        $this->assertSame($entities[self::ORDER . ':11']->getData(), $payload);
    }

    public function testOriginOmitsTraceUuidWhenAbsent(): void
    {
        $ids = [11];
        $workflow = $this->fanOutWorkflow();
        $dispatcher = $this->dispatcher();
        $expander = $this->expander($workflow, $this->relation($ids), $dispatcher, $this->orderEntities($ids));

        $expander->expand(42, ['entity_id' => 7], 'customer.group_changed', null);

        $origin = $dispatcher->payloads[0]['origin'];
        $this->assertFalse(array_key_exists('trace_uuid', $origin));
        $this->assertSame('fan_out', $origin['via']);
    }

    public function testStormWithDuplicateDeliveryCollapsesToDistinctExecutions(): void
    {
        $ids = range(1, 100);
        $workflow = $this->fanOutWorkflow();
        $dispatcher = $this->dispatcher();
        $expander = $this->expander($workflow, $this->relation($ids), $dispatcher, $this->orderEntities($ids));

        // 1 event x 100 targets, delivered twice (redelivery re-expands).
        $first = $expander->expand(42, ['entity_id' => 7], 'customer.group_changed', 'trace-abc');
        $second = $expander->expand(42, ['entity_id' => 7], 'customer.group_changed', 'trace-abc');

        $this->assertSame(100, $first->getDispatched());
        // The redelivery re-expands but every child is debounced.
        $this->assertSame(0, $second->getDispatched());
        $this->assertSame(100, $second->getSkipped());
        // Exactly 100 executions exist despite duplicate delivery.
        $this->assertSame(100, $dispatcher->created);
    }

    public function testMidExpansionThrowSkipsChildAndContinues(): void
    {
        $ids = [1, 2, 3, 4, 5];
        $workflow = $this->fanOutWorkflow();
        $dispatcher = $this->dispatcher(3); // child with entity_id 3 throws
        $expander = $this->expander($workflow, $this->relation($ids), $dispatcher, $this->orderEntities($ids));

        $result = $expander->expand(42, ['entity_id' => 7], 'customer.group_changed', 'trace-abc');

        $this->assertSame(4, $result->getDispatched());
        $this->assertSame(1, $result->getSkipped());
        $this->assertSame(4, $dispatcher->created);
        // Expansion continued past the throwing child: 4 and 5 dispatched.
        $dispatchedIds = array_column($dispatcher->payloads, 'entity_id');
        $this->assertSame([1, 2, 4, 5], $dispatchedIds);
    }

    public function testMissingTargetEntityIsSkipped(): void
    {
        $ids = [1, 2, 3];
        $workflow = $this->fanOutWorkflow();
        $dispatcher = $this->dispatcher();
        // Only ids 1 and 3 hydrate; 2 has vanished.
        $entities = $this->orderEntities([1, 3]);
        $expander = $this->expander($workflow, $this->relation($ids), $dispatcher, $entities);

        $result = $expander->expand(42, ['entity_id' => 7], 'customer.group_changed', 'trace-abc');

        $this->assertSame(2, $result->getDispatched());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testPerWorkflowCapTruncates(): void
    {
        $ids = range(1, 50);
        $workflow = $this->fanOutWorkflow(5);
        $dispatcher = $this->dispatcher();
        $expander = $this->expander(
            $workflow,
            $this->relation($ids),
            $dispatcher,
            $this->orderEntities($ids),
            [FanOutExpander::CONFIG_FAN_OUT_CAP => 100]
        );

        $result = $expander->expand(42, ['entity_id' => 7], 'customer.group_changed', 'trace-abc');

        $this->assertSame(5, $result->getDispatched());
        $this->assertTrue($result->isTruncated());
        $this->assertSame(5, $dispatcher->created);
    }

    public function testPerWorkflowCapClampsToGlobalCeiling(): void
    {
        $ids = range(1, 30);
        // Per-workflow cap 200 clamps to the global ceiling 10.
        $workflow = $this->fanOutWorkflow(200);
        $dispatcher = $this->dispatcher();
        $expander = $this->expander(
            $workflow,
            $this->relation($ids),
            $dispatcher,
            $this->orderEntities($ids),
            [FanOutExpander::CONFIG_FAN_OUT_CAP => 10]
        );

        $result = $expander->expand(42, ['entity_id' => 7], 'customer.group_changed', 'trace-abc');

        $this->assertSame(10, $result->getDispatched());
        $this->assertTrue($result->isTruncated());
    }

    public function testEmptyRelationDispatchesNothing(): void
    {
        $workflow = $this->fanOutWorkflow();
        $dispatcher = $this->dispatcher();
        $expander = $this->expander($workflow, $this->relation([]), $dispatcher, []);

        $result = $expander->expand(42, ['entity_id' => 7], 'customer.group_changed', 'trace-abc');

        $this->assertNotNull($result);
        $this->assertSame(0, $result->getDispatched());
        $this->assertSame(0, $result->getSkipped());
        $this->assertFalse($result->isTruncated());
        $this->assertSame(0, $dispatcher->created);
    }

    public function testUnknownRelationExpandsToZeroChildren(): void
    {
        $workflow = (new WorkflowStub(42))
            ->setEntityType(self::ORDER)
            ->setFanOut((string) json_encode(['relation' => 'does.not.exist']));
        $dispatcher = $this->dispatcher();
        // Pool holds a different relation; the configured code is absent.
        $expander = $this->expander($workflow, $this->relation([1, 2]), $dispatcher, $this->orderEntities([1, 2]));

        $result = $expander->expand(42, ['entity_id' => 7], 'customer.group_changed', 'trace-abc');

        $this->assertNotNull($result);
        $this->assertSame(0, $result->getDispatched());
        $this->assertSame(0, $dispatcher->created);
    }
}
