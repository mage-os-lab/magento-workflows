<?php
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Test\Unit\Stub;

use MageOS\AsyncEvents\Api\Data\AsyncEventSearchResultsInterface;

/**
 * Fixed-item search results for InMemoryAsyncEventRepository::getList().
 */
class FakeAsyncEventSearchResults implements AsyncEventSearchResultsInterface
{
    /**
     * @param FakeAsyncEvent[] $items
     */
    public function __construct(private readonly array $items = [])
    {
    }

    /**
     * @return FakeAsyncEvent[]
     */
    public function getItems()
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
