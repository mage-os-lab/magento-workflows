<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use MageOS\WorkflowsAdminUi\Model\Workflow\GridBatchLoader;

/**
 * "Workflow" column for the approvals grid (docs/discovery/approval-gate.md §6).
 * Reuses admin-ui's GridBatchLoader (one batched query per grid page) the same
 * way its own executions-grid WorkflowName column does — the workflow-edit
 * deep link itself lives in the actions column (ApprovalActions), since this
 * codebase renders grid links via the actionsColumn href/label contract, not
 * an inline anchor component.
 */
class ApprovalWorkflow extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly GridBatchLoader $workflowLoader,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $this->workflowLoader->preload(
            array_map(
                static fn (array $item): int => (int) ($item['workflow_id'] ?? 0),
                $dataSource['data']['items']
            )
        );

        $name = $this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            $workflowName = $this->workflowLoader->getName((int) ($item['workflow_id'] ?? 0));
            $item[$name] = $workflowName ?? (string) __('(workflow deleted)');
        }

        return $dataSource;
    }
}
