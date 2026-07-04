<?php
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Unit\Cron;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\WorkflowIndex;
use MageOS\WorkflowsScheduler\Cron\RunScheduledWorkflows;
use MageOS\WorkflowsScheduler\Model\QueryRunner;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * WorkflowIndex::getScheduledWorkflowIds() gates execute()'s repository
 * query (05/14 risk item): the cron runs every minute, so a store with zero
 * schedule workflows must never reach WorkflowRepositoryInterface::getList().
 * When the index reports at least one ID, the repository getList() call
 * remains the authoritative load (the index only carries IDs, not models).
 */
class RunScheduledWorkflowsTest extends TestCase
{
    /**
     * Test-double WorkflowIndex. Bypasses the real constructor entirely: its
     * dependencies (CacheInterface, SerializerInterface, CollectionFactory)
     * are not available in the standalone runner and are never touched by
     * this stub, which just hands back the configured IDs.
     *
     * @param int[] $ids
     */
    private function workflowIndex(array $ids): WorkflowIndex
    {
        return new class ($ids) extends WorkflowIndex {
            /** @param int[] $ids */
            public function __construct(private readonly array $ids)
            {
            }

            public function getScheduledWorkflowIds(): array
            {
                return $this->ids;
            }
        };
    }

    /**
     * Repository stub that records whether getList() was ever invoked and,
     * when allowed to run, returns the given workflows.
     *
     * @param WorkflowInterface[] $workflows
     */
    private function repository(array $workflows = []): object
    {
        return new class ($workflows) implements WorkflowRepositoryInterface {
            public bool $getListCalled = false;

            /** @param WorkflowInterface[] $workflows */
            public function __construct(private readonly array $workflows)
            {
            }

            public function save(WorkflowInterface $workflow): WorkflowInterface
            {
                throw new \LogicException('not used in this test');
            }

            public function getById(int $workflowId): WorkflowInterface
            {
                throw new \LogicException('not used in this test');
            }

            public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
            {
                $this->getListCalled = true;

                $workflows = $this->workflows;
                return new class ($workflows) implements SearchResultsInterface {
                    /** @param WorkflowInterface[] $items */
                    public function __construct(private readonly array $items)
                    {
                    }

                    /** @return WorkflowInterface[] */
                    public function getItems(): array
                    {
                        return $this->items;
                    }
                };
            }

            public function delete(WorkflowInterface $workflow): bool
            {
                throw new \LogicException('not used in this test');
            }

            public function deleteById(int $workflowId): bool
            {
                throw new \LogicException('not used in this test');
            }
        };
    }

    private function searchCriteriaBuilder(): SearchCriteriaBuilder
    {
        return new class implements SearchCriteriaBuilder {
            public function create()
            {
                return new class implements SearchCriteriaInterface {
                };
            }

            public function setFilterGroups($groups)
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

            public function setPageSize($size)
            {
                return $this;
            }

            public function setCurrentPage($page)
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
        return new class implements FilterBuilder {
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
        return new class implements FilterGroupBuilder {
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

    private function storeManager(): StoreManagerInterface
    {
        return new class implements StoreManagerInterface {
            public function getStore($storeId = null)
            {
                throw new \LogicException('not used in this test');
            }
        };
    }

    private function timezone(): TimezoneInterface
    {
        return new class implements TimezoneInterface {
            public function getConfigTimezone($scopeType = null, $scopeCode = null): string
            {
                throw new \LogicException('not used in this test');
            }
        };
    }

    /**
     * Bare QueryRunner test double: bypasses the real constructor (dispatcher,
     * condition mapping, repositories, etc. are not needed) since the gate
     * under test never reaches QueryRunner::run().
     */
    private function queryRunner(): QueryRunner
    {
        return new class extends QueryRunner {
            public function __construct()
            {
            }
        };
    }

    private function cron(WorkflowIndex $index, object $repository): RunScheduledWorkflows
    {
        return new RunScheduledWorkflows(
            $repository,
            $this->searchCriteriaBuilder(),
            $this->filterBuilder(),
            $this->filterGroupBuilder(),
            $this->storeManager(),
            $this->timezone(),
            new ResourceConnection(),
            $this->queryRunner(),
            new NullLogger(),
            $index
        );
    }

    public function testEmptyIndexSkipsRepositoryQueryEntirely(): void
    {
        $repository = $this->repository();

        $this->cron($this->workflowIndex([]), $repository)->execute();

        $this->assertFalse(
            $repository->getListCalled,
            'RunScheduledWorkflows must not query the repository when the index reports no schedule workflows'
        );
    }

    public function testNonEmptyIndexStillQueriesTheRepository(): void
    {
        // No workflows actually returned by getList() here: this test only
        // proves the gate does not over-trigger and suppress the real load
        // when the index says there IS at least one schedule workflow.
        $repository = $this->repository([]);

        $this->cron($this->workflowIndex([42]), $repository)->execute();

        $this->assertTrue(
            $repository->getListCalled,
            'RunScheduledWorkflows must still query the repository when the index reports schedule workflows'
        );
    }
}
