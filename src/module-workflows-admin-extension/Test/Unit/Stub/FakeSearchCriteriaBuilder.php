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
class FakeSearchCriteriaBuilder implements SearchCriteriaBuilder
{
    /** @var array<int, array{field: mixed, value: mixed, conditionType: mixed}> */
    public array $filters = [];

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
