<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use MageOS\WorkflowsApprovals\Model\EntityUrlResolver;

/**
 * Row actions for the approvals grid (docs/discovery/approval-gate.md §6):
 * "view" opens the decision view (the grid's primary row action); "workflow"
 * deep-links the owning workflow's edit page; "entity" deep-links the subject
 * entity when EntityUrlResolver knows its type. Follows the same
 * href/label-array actionsColumn contract as admin-ui's ExecutionActions —
 * the one link mechanism already proven in this codebase's grids.
 */
class ApprovalActions extends Column
{
    private const URL_DECISION_VIEW = 'mageos_workflows_approvals/approval/view';
    private const URL_WORKFLOW_EDIT = 'mageos_workflows/workflow/edit';

    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        private readonly EntityUrlResolver $entityUrlResolver,
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
            if (!isset($item['uuid'])) {
                continue;
            }
            $actions = [
                'view' => [
                    'href' => $this->urlBuilder->getUrl(self::URL_DECISION_VIEW, ['uuid' => $item['uuid']]),
                    'label' => __('View'),
                ],
            ];
            if (!empty($item['workflow_id'])) {
                $actions['workflow'] = [
                    'href' => $this->urlBuilder->getUrl(
                        self::URL_WORKFLOW_EDIT,
                        ['workflow_id' => $item['workflow_id']]
                    ),
                    'label' => __('View Workflow'),
                ];
            }
            $entityUrl = $this->entityUrlResolver->getUrl(
                (string) ($item['entity_type'] ?? ''),
                (int) ($item['entity_id'] ?? 0)
            );
            if ($entityUrl !== null) {
                $actions['entity'] = ['href' => $entityUrl, 'label' => __('View Entity')];
            }
            $item[$name] = $actions;
        }

        return $dataSource;
    }
}
