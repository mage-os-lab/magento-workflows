<?php
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Test\Unit\Stub;

use Magento\Framework\Api\SearchCriteriaInterface;

/**
 * SearchCriteriaInterface carrier: holds the filters recorded by
 * FakeSearchCriteriaBuilder so InMemoryAsyncEventRepository::getList() can
 * actually answer recipient_url eq/like lookups.
 */
class RecordedSearchCriteria implements SearchCriteriaInterface
{
    /**
     * @param array<int, array{field: mixed, value: mixed, conditionType: mixed}> $filters
     */
    public function __construct(public readonly array $filters = [])
    {
    }

    public function getFilterGroups()
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
}
