<?php
declare(strict_types=1);

namespace Magento\Framework\Api;

/**
 * Minimal shim for Magento\Framework\Api\SearchCriteriaBuilder.
 * Returns stub objects; intended for interface verification only.
 */
interface SearchCriteriaBuilder
{
    public function create();
    public function setFilterGroups($groups);
    public function addFilter($field, $value, $conditionType = 'eq');
    public function addFilters($filters);
    public function setPageSize($size);
    public function setCurrentPage($page);
    public function addSortOrder($field, $direction = 'ASC');
}
