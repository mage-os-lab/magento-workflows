<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\WorkflowsAdminUi\Model\Workflow\GridBatchLoader;

/**
 * "Mode" column for the executions grid: Shadow (the owning workflow logs would-be effects
 * without mutating anything) vs Live, derived from the owning workflow's current status via
 * the same per-page batch loader as the Workflow Name column.
 */
class WorkflowMode extends Column
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
            $status = $this->workflowLoader->getStatus((int) ($item['workflow_id'] ?? 0));
            if ($status === null) {
                $item[$name] = '';
                continue;
            }
            $item[$name] = $status === WorkflowInterface::STATUS_SHADOW
                ? (string) __('Shadow')
                : (string) __('Live');
        }

        return $dataSource;
    }
}
