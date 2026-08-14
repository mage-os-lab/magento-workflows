<?php
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Unit\Model;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\DataObject;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\DispatcherInterface;
use MageOS\Workflows\Test\Unit\Stub\StubScopeConfig;
use MageOS\WorkflowsScheduler\Model\ConditionToSearchCriteria;
use MageOS\WorkflowsScheduler\Model\QueryRunner;
use MageOS\WorkflowsScheduler\Model\RootConditionPreFilter;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\WorkflowStub;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * Per-entity FALLBACK path (unmapped condition tree): every scanned row is
 * checked in-process against the root conditions before dispatch, the match
 * cap bounds SCANNED rows, and the watermark advances per scanned row so a
 * non-matching row is never re-scanned into a later tick. The mapped path
 * never consults the pre-filter, and a runner constructed without one (the
 * pre-di.xml wiring state) keeps the old dispatch-everything behavior.
 */
class QueryRunnerFallbackTest extends TestCase
{
    /** A nested tree: ConditionToSearchCriteria returns null => fallback path */
    private const UNMAPPED_CONDITIONS = '{"aggregator":"all","conditions":[{"aggregator":"any","conditions":'
        . '[{"attribute":"state","operator":"==","value":"new"}]}]}';

    /** A flat tree: index-mappable => mapped path */
    private const MAPPED_CONDITIONS =
        '{"aggregator":"all","conditions":[{"attribute":"state","operator":"==","value":"new"}]}';

    /** @var array<int, array{0:int,1:array,2:string}> captured dispatch calls */
    private array $dispatched = [];

    public function setUp(): void
    {
        $this->dispatched = [];
    }

    public function recordDispatch(int $workflowId, array $payload, string $triggerType): void
    {
        $this->dispatched[] = [$workflowId, $payload, $triggerType];
    }

    // ------------------------------------------------------------------
    // Fakes (same anonymous-class style as QueryRunnerCollectedModeTest)
    // ------------------------------------------------------------------

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
            public function __construct(private readonly QueryRunnerFallbackTest $test)
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
     * Pre-filter double: verdicts by entity id, calls recorded. The parent
     * constructor is deliberately not invoked — only matches() is overridden.
     */
    private function preFilter(callable $verdict): RootConditionPreFilter
    {
        return new class ($verdict) extends RootConditionPreFilter {
            /** @var int[] entity ids the runner asked about */
            public array $asked = [];

            public function __construct(private $verdict)
            {
            }

            public function matches(WorkflowInterface $workflow, int $entityId, array $payload): bool
            {
                $this->asked[] = $entityId;
                return ($this->verdict)($entityId, $payload);
            }
        };
    }

    private function queryRunner(
        object $repository,
        ?RootConditionPreFilter $preFilter,
        int $matchCap = 5000
    ): QueryRunner {
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
            new StubScopeConfig(['mageos_workflows/scheduler/match_cap' => $matchCap]),
            new NullLogger(),
            ['sales_order' => $repository],
            null,
            null,
            null,
            null,
            [],
            $preFilter
        );
    }

    private function order(int $id, string $createdAt, string $state): DataObject
    {
        return new DataObject([
            'entity_id' => $id,
            'created_at' => $createdAt,
            'state' => $state,
        ]);
    }

    private function workflow(string $conditions): WorkflowStub
    {
        return new WorkflowStub([
            'workflow_id' => 11,
            'name' => 'Fallback schedule',
            'entity_type' => 'sales_order',
            'conditions_serialized' => $conditions,
        ]);
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function testFallbackSkipsNonMatchingRowsButAdvancesTheWatermarkPastThem(): void
    {
        $repository = $this->repository([
            $this->order(1, '2026-07-01 10:00:00', 'new'),
            $this->order(2, '2026-07-01 11:00:00', 'complete'), // not a match
            $this->order(3, '2026-07-01 12:00:00', 'new'),
        ]);
        $preFilter = $this->preFilter(static fn (int $id, array $payload): bool => $payload['state'] === 'new');

        $watermark = $this->queryRunner($repository, $preFilter)
            ->run($this->workflow(self::UNMAPPED_CONDITIONS), null);

        $this->assertSame([1, 2, 3], $preFilter->asked, 'every scanned row is checked in-process');
        $this->assertSame([1, 3], array_column(array_column($this->dispatched, 1), 'entity_id'));
        // The non-matching row still advanced the watermark: it is never
        // re-scanned into a later tick.
        $this->assertSame('2026-07-01 12:00:00', $watermark);
    }

    public function testFallbackCapBoundsScannedRowsNotDispatches(): void
    {
        $repository = $this->repository([
            $this->order(1, '2026-07-01 10:00:00', 'complete'), // not a match
            $this->order(2, '2026-07-01 11:00:00', 'complete'), // not a match
            $this->order(3, '2026-07-01 12:00:00', 'new'),      // never scanned: cap hit
        ]);
        $preFilter = $this->preFilter(static fn (int $id, array $payload): bool => $payload['state'] === 'new');

        $watermark = $this->queryRunner($repository, $preFilter, 2)
            ->run($this->workflow(self::UNMAPPED_CONDITIONS), null);

        // Cap 2 bounds the SCAN (like collected mode): rows 1-2 are scanned
        // and skipped, row 3 waits for the next tick past the watermark.
        $this->assertSame([1, 2], $preFilter->asked);
        $this->assertCount(0, $this->dispatched);
        $this->assertSame('2026-07-01 11:00:00', $watermark);
    }

    public function testMappedPathNeverConsultsThePreFilter(): void
    {
        $repository = $this->repository([
            $this->order(1, '2026-07-01 10:00:00', 'new'),
            $this->order(2, '2026-07-01 11:00:00', 'new'),
        ]);
        // Verdict false everywhere: would suppress every dispatch IF consulted.
        $preFilter = $this->preFilter(static fn (): bool => false);

        $this->queryRunner($repository, $preFilter)
            ->run($this->workflow(self::MAPPED_CONDITIONS), null);

        $this->assertSame([], $preFilter->asked, 'mapped rows already passed the index-mapped criteria');
        $this->assertCount(2, $this->dispatched);
    }

    public function testFallbackWithoutAPreFilterKeepsTheOldDispatchEverythingBehavior(): void
    {
        $repository = $this->repository([
            $this->order(1, '2026-07-01 10:00:00', 'complete'),
            $this->order(2, '2026-07-01 11:00:00', 'new'),
        ]);

        $this->queryRunner($repository, null)
            ->run($this->workflow(self::UNMAPPED_CONDITIONS), null);

        $this->assertCount(2, $this->dispatched, 'no pre-filter wired => engine-side skip, as before');
    }
}
