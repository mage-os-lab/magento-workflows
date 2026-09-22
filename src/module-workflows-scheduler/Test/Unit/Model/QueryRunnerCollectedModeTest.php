<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Unit\Model;

use Magento\Framework\Api\ExtensibleDataObjectConverter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\DataObject;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\DispatcherInterface;
use MageOS\Workflows\Model\Aggregation\BatchContextBuilder;
use MageOS\Workflows\Model\Aggregation\ItemProjector;
use MageOS\Workflows\Model\Aggregation\MembershipEvaluatorInterface;
use MageOS\Workflows\Model\Rule\Hydrator\EntityDataConverter;
use MageOS\Workflows\Test\Unit\Stub\StubScopeConfig;
use MageOS\WorkflowsScheduler\Model\ConditionToSearchCriteria;
use MageOS\WorkflowsScheduler\Model\QueryRunner;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\WorkflowStub;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * B1 collected-mode accumulation: one batch execution, membership enforced per
 * item, EntityDataConverter item-shape parity (lifted EAV attributes),
 * item_cap + overflow, and watermark advance.
 */
class QueryRunnerCollectedModeTest extends TestCase
{
    /** @var array<int, array{0:int,1:array,2:string}> captured dispatch calls */
    private array $dispatched = [];

    public function setUp(): void
    {
        $this->dispatched = [];
    }

    private function searchCriteriaBuilder(): SearchCriteriaBuilder
    {
        return new class extends SearchCriteriaBuilder {
            public function __construct()
            {
            }

            public function create()
            {
                return new class implements \Magento\Framework\Api\SearchCriteriaInterface {
                    public function getFilterGroups(): array
                    {
                        return [];
                    }

                    public function setFilterGroups(?array $filterGroups = null)
                    {
                        return $this;
                    }

                    public function getSortOrders()
                    {
                        return [];
                    }

                    public function setSortOrders(?array $sortOrders = null)
                    {
                        return $this;
                    }

                    public function getPageSize()
                    {
                        return null;
                    }

                    public function setPageSize($pageSize)
                    {
                        return $this;
                    }

                    public function getCurrentPage()
                    {
                        return null;
                    }

                    public function setCurrentPage($currentPage)
                    {
                        return $this;
                    }
                };
            }

            public function setFilterGroups($groups)
            {
                return $this;
            }

            public function setSortOrders($sortOrders)
            {
                return $this;
            }

            public function setPageSize($size)
            {
                return $this;
            }

            public function setCurrentPage($page)
            {
                return $this;
            }

            public function addFilter($field, $value, $conditionType = 'eq')
            {
                return $this;
            }

            public function addFilters($filters)
            {
                return $this;
            }

            public function addSortOrder($field, $direction = 'ASC')
            {
                return $this;
            }
        };
    }

    private function filterBuilder(): FilterBuilder
    {
        return new class extends FilterBuilder {
            public function __construct()
            {
            }

            public function create()
            {
                return new DataObject();
            }

            public function setField($field)
            {
                return $this;
            }

            public function setValue($value)
            {
                return $this;
            }

            public function setConditionType($type)
            {
                return $this;
            }
        };
    }

    private function filterGroupBuilder(): FilterGroupBuilder
    {
        return new class extends FilterGroupBuilder {
            public function __construct()
            {
            }

            public function create()
            {
                return new DataObject();
            }

            public function setFilters($filters)
            {
                return $this;
            }
        };
    }

    private function sortOrderBuilder(): SortOrderBuilder
    {
        return new class extends SortOrderBuilder {
            public function __construct()
            {
            }

            public function create()
            {
                return new \Magento\Framework\Api\SortOrder();
            }

            public function setField($field)
            {
                return $this;
            }

            public function setDirection($direction)
            {
                return $this;
            }
        };
    }

    private function dispatcher(): DispatcherInterface
    {
        $test = $this;
        return new class ($test) implements DispatcherInterface {
            public function __construct(private readonly QueryRunnerCollectedModeTest $test)
            {
            }

            public function dispatch(
                int $workflowId,
                array $triggerPayload,
                string $triggerType = 'event',
                int $chainDepth = 0
            ): ?WorkflowExecutionInterface {
                $this->test->recordDispatch($workflowId, $triggerPayload, $triggerType);
                return null;
            }

            public function resumeWaiting(int $workflowId, string $event, array $eventPayload): int
            {
                return 0;
            }
        };
    }

    public function recordDispatch(int $workflowId, array $payload, string $triggerType): void
    {
        $this->dispatched[] = [$workflowId, $payload, $triggerType];
    }

    /**
     * Repository yielding one page of DataObject entities, then empty.
     *
     * @param array<int, DataObject> $entities
     */
    private function repository(array $entities): object
    {
        return new class ($entities) {
            private int $calls = 0;

            /** @param array<int, DataObject> $entities */
            public function __construct(private readonly array $entities)
            {
            }

            public function getList($criteria): object
            {
                $page = $this->calls === 0 ? $this->entities : [];
                $this->calls++;
                return new class ($page) {
                    /** @param array<int, DataObject> $items */
                    public function __construct(private readonly array $items)
                    {
                    }

                    public function getItems(): array
                    {
                        return $this->items;
                    }
                };
            }
        };
    }

    /**
     * Membership fake: a product is a member iff its (lifted) in_stock flag is
     * truthy — proves non-matching rows are excluded from items[].
     */
    private function membership(): MembershipEvaluatorInterface
    {
        return new class implements MembershipEvaluatorInterface {
            public function matches(WorkflowInterface $workflow, array $flatItem): bool
            {
                return (int) ($flatItem['in_stock'] ?? 0) === 1;
            }
        };
    }

    private function queryRunner(object $repository, MembershipEvaluatorInterface $membership): QueryRunner
    {
        $conditionToSearchCriteria = new ConditionToSearchCriteria(
            $this->searchCriteriaBuilder(),
            $this->filterBuilder(),
            $this->filterGroupBuilder(),
            new NullLogger()
        );

        return new QueryRunner(
            $this->dispatcher(),
            $conditionToSearchCriteria,
            $this->searchCriteriaBuilder(),
            $this->filterBuilder(),
            $this->filterGroupBuilder(),
            $this->sortOrderBuilder(),
            new StubScopeConfig(['mageos_workflows/scheduler/match_cap' => 5000]),
            new NullLogger(),
            ['catalog_product' => $repository],
            new EntityDataConverter($this->dataObjectConverter()),
            $membership,
            new ItemProjector(),
            new BatchContextBuilder(),
            []
        );
    }

    /**
     * An ExtensibleDataObjectConverter double with an empty constructor. Real
     * Magento's converter requires a DataObjectProcessor dependency; these tests
     * only exercise DataObject entities, which EntityDataConverter handles
     * without ever reaching the converter, so construction is all that matters.
     */
    private function dataObjectConverter(): ExtensibleDataObjectConverter
    {
        return new class extends ExtensibleDataObjectConverter {
            public function __construct()
            {
            }

            public function toNestedArray($dataObject, $skipCustomAttributes = [], $dataObjectType = null)
            {
                throw new \RuntimeException('toNestedArray() not expected in these tests');
            }
        };
    }

    private function product(int $id, string $updatedAt, int $inStock, string $eav): DataObject
    {
        return new DataObject([
            'entity_id' => $id,
            'updated_at' => $updatedAt,
            'in_stock' => $inStock,
            'custom_attributes' => [
                ['attribute_code' => 'my_eav', 'value' => $eav],
            ],
        ]);
    }

    private function collectedWorkflow(array $aggregation = []): WorkflowStub
    {
        // A nested condition tree so ConditionToSearchCriteria returns null
        // (the unmapped/fallback path): membership must still filter per item.
        $conditions = json_encode([
            'aggregator' => 'all',
            'conditions' => [
                ['aggregator' => 'any', 'conditions' => [
                    ['attribute' => 'in_stock', 'operator' => '==', 'value' => '1'],
                ]],
            ],
        ]);

        return new WorkflowStub([
            'workflow_id' => 7,
            'name' => 'Daily stock digest',
            'entity_type' => 'catalog_product',
            'conditions_serialized' => $conditions,
            'aggregation' => json_encode(['mode' => 'collected'] + $aggregation),
        ]);
    }

    public function testUnmappedNestedConditionsExcludeNonMatchingRows(): void
    {
        $repository = $this->repository([
            $this->product(1, '2026-07-01 10:00:00', 1, 'A'),
            $this->product(2, '2026-07-01 11:00:00', 0, 'B'), // not a member
            $this->product(3, '2026-07-01 12:00:00', 1, 'C'),
        ]);
        $workflow = $this->collectedWorkflow(['projection' => ['entity_id', 'my_eav', 'in_stock']]);

        $watermark = $this->queryRunner($repository, $this->membership())->run($workflow, null);

        // Exactly one batch execution dispatched.
        $this->assertCount(1, $this->dispatched);
        [$workflowId, $payload] = $this->dispatched[0];
        $this->assertSame(7, $workflowId);
        $this->assertTrue($payload['batch']);
        // Two members; the out-of-stock row is excluded from items[].
        $this->assertSame(2, $payload['count']);
        $this->assertCount(2, $payload['items']);
        $this->assertFalse($payload['overflow']);
        $ids = array_column($payload['items'], 'entity_id');
        $this->assertSame([1, 3], $ids);
        // Watermark advanced past every SCANNED row (including the non-member).
        $this->assertSame('2026-07-01 12:00:00', $watermark);
    }

    public function testEavAttributeParityViaConverter(): void
    {
        $repository = $this->repository([
            $this->product(1, '2026-07-01 10:00:00', 1, 'EAVVAL'),
        ]);
        $workflow = $this->collectedWorkflow(['projection' => ['entity_id', 'my_eav']]);

        $this->queryRunner($repository, $this->membership())->run($workflow, null);

        [, $payload] = $this->dispatched[0];
        // custom_attributes lifted to a top-level key by EntityDataConverter,
        // so the projection sees the EAV attribute — B1/B2 item-shape parity.
        $this->assertSame('EAVVAL', $payload['items'][0]['my_eav']);
    }

    public function testItemCapAndOverflow(): void
    {
        $repository = $this->repository([
            $this->product(1, '2026-07-01 10:00:00', 1, 'A'),
            $this->product(2, '2026-07-01 11:00:00', 1, 'B'),
            $this->product(3, '2026-07-01 12:00:00', 1, 'C'),
        ]);
        $workflow = $this->collectedWorkflow(['item_cap' => 2, 'projection' => ['entity_id']]);

        $this->queryRunner($repository, $this->membership())->run($workflow, null);

        [, $payload] = $this->dispatched[0];
        // Three members, cap 2: count is the true match count, items elided.
        $this->assertSame(3, $payload['count']);
        $this->assertCount(2, $payload['items']);
        $this->assertTrue($payload['overflow']);
    }

    public function testBelowMinItemsDropsWithoutDispatch(): void
    {
        $repository = $this->repository([
            $this->product(1, '2026-07-01 10:00:00', 0, 'A'), // not a member
        ]);
        $workflow = $this->collectedWorkflow(['min_items' => 1, 'projection' => ['entity_id']]);

        $watermark = $this->queryRunner($repository, $this->membership())->run($workflow, null);

        // Zero members < min_items: no digest dispatched, but the watermark
        // still advances so the scanned row is not re-digested.
        $this->assertCount(0, $this->dispatched);
        $this->assertSame('2026-07-01 10:00:00', $watermark);
    }

    public function testPerEntityWorkflowStillDispatchesPerMatch(): void
    {
        // No aggregation config => the existing per-entity path: one dispatch
        // per row, no batch context.
        $repository = $this->repository([
            $this->product(1, '2026-07-01 10:00:00', 1, 'A'),
            $this->product(2, '2026-07-01 11:00:00', 1, 'B'),
        ]);
        $workflow = new WorkflowStub([
            'workflow_id' => 9,
            'name' => 'Per entity',
            'entity_type' => 'catalog_product',
            'conditions_serialized' => null,
        ]);

        $this->queryRunner($repository, $this->membership())->run($workflow, null);

        $this->assertCount(2, $this->dispatched);
        foreach ($this->dispatched as [, $payload]) {
            $this->assertTrue(!isset($payload['batch']));
        }
    }
}
