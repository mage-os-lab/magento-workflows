<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Model;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use MageOS\WorkflowsApprovals\Api\ApprovalRepositoryInterface;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalSearchResultsInterface;
use MageOS\WorkflowsApprovals\Model\OpenTaskLookup;
use MageOS\WorkflowsApprovals\Test\Unit\Stub\FakeApproval;
use PHPUnit\Framework\TestCase;

/**
 * The execution-view panel's render gate (docs/discovery/approval-gate.md §6):
 * finds the open task for one execution's parked step.
 */
class OpenTaskLookupTest extends TestCase
{
    /**
     * @param ApprovalInterface[] $items
     */
    private function repository(array $items): ApprovalRepositoryInterface
    {
        return new class($items) implements ApprovalRepositoryInterface {
            public function __construct(private readonly array $items)
            {
            }

            public function getByUuid(string $uuid): ApprovalInterface
            {
                throw new \RuntimeException('Not used by this test');
            }

            public function getList(SearchCriteriaInterface $searchCriteria): ApprovalSearchResultsInterface
            {
                return new class($this->items) implements ApprovalSearchResultsInterface {
                    public function __construct(private readonly array $items)
                    {
                    }

                    public function getItems(): array
                    {
                        return $this->items;
                    }

                    public function setItems(array $items)
                    {
                        return $this;
                    }

                    public function getSearchCriteria()
                    {
                        return null;
                    }

                    public function setSearchCriteria(SearchCriteriaInterface $searchCriteria)
                    {
                        return $this;
                    }

                    public function getTotalCount()
                    {
                        return count($this->items);
                    }

                    public function setTotalCount($totalCount)
                    {
                        return $this;
                    }
                };
            }
        };
    }

    /**
     * @return SearchCriteriaBuilder&object{filters: array<string, mixed>}
     */
    private function searchCriteriaBuilder(): SearchCriteriaBuilder
    {
        return new class extends SearchCriteriaBuilder {
            public array $filters = [];

            // SearchCriteriaBuilder is a concrete class with a collaborator-heavy
            // constructor this test never exercises; skip it entirely.
            public function __construct()
            {
            }

            public function create()
            {
                return new class implements SearchCriteriaInterface {
                    public function getFilterGroups()
                    {
                        return [];
                    }

                    public function setFilterGroups($filterGroups = null)
                    {
                        return $this;
                    }

                    public function getSortOrders()
                    {
                        return null;
                    }

                    public function setSortOrders($sortOrders = null)
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

            public function addFilter($field, $value, $conditionType = 'eq')
            {
                $this->filters[$field] = $value;
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

    public function testReturnsFirstOpenTaskFound(): void
    {
        $task = (new FakeApproval())->setUuid('u-1')->setExecutionId(10)->setStepKey('gate');
        $lookup = new OpenTaskLookup($this->repository([$task]), $this->searchCriteriaBuilder());

        $found = $lookup->findOpenTask(10, 'gate');

        $this->assertSame($task, $found);
    }

    public function testReturnsNullWhenNoneFound(): void
    {
        $lookup = new OpenTaskLookup($this->repository([]), $this->searchCriteriaBuilder());
        $this->assertNull($lookup->findOpenTask(10, 'gate'));
    }

    public function testFiltersByExecutionStepAndOpenStatus(): void
    {
        $builder = $this->searchCriteriaBuilder();
        $lookup = new OpenTaskLookup($this->repository([]), $builder);

        $lookup->findOpenTask(10, 'gate');

        $this->assertSame(10, $builder->filters[ApprovalInterface::EXECUTION_ID]);
        $this->assertSame('gate', $builder->filters[ApprovalInterface::STEP_KEY]);
        $this->assertSame(ApprovalInterface::STATUS_OPEN, $builder->filters[ApprovalInterface::STATUS]);
    }
}
