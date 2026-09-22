<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Stub;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\WorkflowExecutionRepositoryInterface;

/**
 * In-memory WorkflowExecutionRepositoryInterface double keyed by execution id.
 */
class FakeWorkflowExecutionRepository implements WorkflowExecutionRepositoryInterface
{
    /** @var array<int, WorkflowExecutionInterface> */
    private array $executions = [];

    public function seed(WorkflowExecutionInterface $execution): void
    {
        $this->executions[(int) $execution->getExecutionId()] = $execution;
    }

    public function save(WorkflowExecutionInterface $execution): WorkflowExecutionInterface
    {
        $this->seed($execution);
        return $execution;
    }

    public function getById(int $executionId): WorkflowExecutionInterface
    {
        if (!isset($this->executions[$executionId])) {
            throw NoSuchEntityException::singleField('execution_id', (string) $executionId);
        }
        return $this->executions[$executionId];
    }

    public function getByUuid(string $uuid): WorkflowExecutionInterface
    {
        foreach ($this->executions as $execution) {
            if ($execution->getUuid() === $uuid) {
                return $execution;
            }
        }
        throw NoSuchEntityException::singleField('uuid', $uuid);
    }

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        throw new \RuntimeException('Not used by these tests');
    }
}
