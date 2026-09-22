<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminExtension\Test\Unit\Stub;

use Magento\Framework\Api\SearchResultsInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;

/**
 * Minimal SearchResultsInterface stand-in exposing just getItems().
 */
class FakeSearchResults implements SearchResultsInterface
{
    /**
     * @param WorkflowInterface[] $items
     */
    public function __construct(
        private readonly array $items = []
    ) {
    }

    /**
     * @return WorkflowInterface[]
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function setItems(array $items)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getSearchCriteria()
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function setSearchCriteria($searchCriteria)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getTotalCount()
    {
        return count($this->items);
    }

    public function setTotalCount($totalCount)
    {
        throw new \BadMethodCallException(__METHOD__);
    }
}
