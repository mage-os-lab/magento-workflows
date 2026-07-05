<?php
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
}
