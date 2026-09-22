<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use MageOS\WorkflowsApprovals\Model\DueInFormatter;

/**
 * "Due-in" column (docs/discovery/approval-gate.md §6): renders due_at as
 * time-remaining ("Due in 2h") or overdue ("Overdue by 3h") text, the grid's
 * SLA-at-a-glance column.
 */
class ApprovalDueIn extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly DueInFormatter $dueInFormatter,
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

        $name = $this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            $item[$name] = $this->dueInFormatter->format($item['due_at'] ?? null);
        }

        return $dataSource;
    }
}
