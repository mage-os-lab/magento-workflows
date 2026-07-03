<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use MageOS\WorkflowsAdminUi\Model\Workflow\GridBatchLoader;

/**
 * Workflow name column for the executions grid: the raw row only carries workflow_id, so the
 * owning workflows are batch-loaded once per page via GridBatchLoader (shared with the Mode
 * column -- one query per page, cached per request).
 */
class WorkflowName extends Column
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
