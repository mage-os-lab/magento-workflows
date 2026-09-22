<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Model\Workflow;

use MageOS\Workflows\Model\ResourceModel\Workflow\CollectionFactory;

/**
 * Per-request batch loader of workflow display data (name, status) for grid columns.
 *
 * The executions grid rows carry only workflow_id; the Workflow Name and Mode columns both
 * need the owning workflow. Shared (ObjectManager-singleton) so all columns on the page issue
 * one collection query per page of rows, cached for the rest of the request.
 */
class GridBatchLoader
{
    /**
     * @var array<int, array{name: string, status: int}>
     */
    private array $rows = [];

    /**
     * @var array<int, bool>
     */
    private array $attempted = [];

    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @param int[] $workflowIds
     */
    public function preload(array $workflowIds): void
    {
        $missing = [];
        foreach ($workflowIds as $workflowId) {
            $workflowId = (int) $workflowId;
            if ($workflowId > 0 && !isset($this->attempted[$workflowId])) {
                $this->attempted[$workflowId] = true;
                $missing[] = $workflowId;
            }
        }
        if ($missing === []) {
            return;
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('workflow_id', ['in' => $missing]);
        foreach ($collection->getItems() as $workflow) {
            $this->rows[(int) $workflow->getId()] = [
                'name' => (string) $workflow->getName(),
                'status' => (int) $workflow->getStatus(),
            ];
        }
    }

    /**
     * Null when the workflow no longer exists (deleted after its executions were logged).
     */
    public function getName(int $workflowId): ?string
    {
        $this->preload([$workflowId]);
        return $this->rows[$workflowId]['name'] ?? null;
    }

    public function getStatus(int $workflowId): ?int
    {
        $this->preload([$workflowId]);
        return $this->rows[$workflowId]['status'] ?? null;
    }
}
