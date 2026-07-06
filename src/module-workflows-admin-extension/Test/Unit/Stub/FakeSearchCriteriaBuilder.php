<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminExtension\Test\Unit\Stub;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;

/**
 * SearchCriteriaBuilder stand-in that records addFilter() calls so a test can
 * assert the count provider filtered on entity_type; create() returns an empty
 * SearchCriteria stub.
 */
class FakeSearchCriteriaBuilder extends SearchCriteriaBuilder
{
    /** @var array<int, array{field: mixed, value: mixed, conditionType: mixed}> */
    public array $filters = [];

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

    public function addFilter($field, $value, $conditionType = 'eq')
    {
        $this->filters[] = ['field' => $field, 'value' => $value, 'conditionType' => $conditionType];
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
}
