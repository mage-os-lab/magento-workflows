<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminExtension\Test\Unit\Stub;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;

/**
 * WorkflowRepositoryInterface stand-in whose getList() returns a fixed item set
 * (or throws, to exercise the count provider's degrade-to-zero path). Records
 * how many times getList() ran so a test can prove a cache hit skipped it.
 */
class FakeWorkflowRepository implements WorkflowRepositoryInterface
{
    public int $getListCalls = 0;

    /**
     * @param WorkflowInterface[] $items
     */
    public function __construct(
        private readonly array $items = [],
        private readonly bool $throwOnGetList = false
    ) {
    }

    public function save(WorkflowInterface $workflow): WorkflowInterface
    {
        return $workflow;
    }

    public function getById(int $workflowId): WorkflowInterface
    {
        throw new \RuntimeException('not used');
    }

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        $this->getListCalls++;
        if ($this->throwOnGetList) {
            throw new \RuntimeException('workflow repository unavailable');
        }
        return new FakeSearchResults($this->items);
    }

    public function delete(WorkflowInterface $workflow): bool
    {
        return true;
    }

    public function deleteById(int $workflowId): bool
    {
        return true;
    }
}
