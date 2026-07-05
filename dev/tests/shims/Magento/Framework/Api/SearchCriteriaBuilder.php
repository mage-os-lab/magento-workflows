<?php
declare(strict_types=1);

namespace Magento\Framework\Api;

/**
 * Minimal shim for Magento\Framework\Api\SearchCriteriaBuilder.
 *
 * In real Magento this is a concrete CLASS (not an interface), so the shim is a
 * class too — test doubles `extends` it (a class cannot `implements` a class),
 * and this keeps the standalone runner structurally aligned with the real type.
 * The no-argument constructor lets anonymous-class doubles instantiate without
 * the real builder's DI dependencies. Methods are inert; doubles override the
 * ones they exercise.
 */
class SearchCriteriaBuilder
{
    public function create()
    {
        return null;
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
}
