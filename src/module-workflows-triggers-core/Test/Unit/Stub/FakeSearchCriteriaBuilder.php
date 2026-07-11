<?php
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Test\Unit\Stub;

use Magento\Framework\Api\SearchCriteriaBuilder;

/**
 * SearchCriteriaBuilder stand-in: records addFilter() calls and hands them to
 * a RecordedSearchCriteria on create(), resetting its own state like the real
 * builder does.
 */
class FakeSearchCriteriaBuilder extends SearchCriteriaBuilder
{
    /** @var array<int, array{field: mixed, value: mixed, conditionType: mixed}> */
    private array $pendingFilters = [];

    public function __construct()
    {
    }

    public function addFilter($field, $value, $conditionType = 'eq')
    {
        $this->pendingFilters[] = ['field' => $field, 'value' => $value, 'conditionType' => $conditionType];
        return $this;
    }

    public function setPageSize($size)
    {
        return $this;
    }

    public function create()
    {
        $criteria = new RecordedSearchCriteria($this->pendingFilters);
        $this->pendingFilters = [];
        return $criteria;
    }
}
