<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

/**
 * Typed search-results contract so the REST list endpoint (§5) serializes each
 * item as an ApprovalInterface rather than a generic object.
 */
interface ApprovalSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return ApprovalInterface[]
     */
    public function getItems();

    /**
     * @param ApprovalInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
