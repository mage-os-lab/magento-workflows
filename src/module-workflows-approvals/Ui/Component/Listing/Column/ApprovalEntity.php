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
use MageOS\WorkflowsAdminUi\Model\OptionLabel;
use MageOS\WorkflowsAdminUi\Model\Source\EntityType;

/**
 * "Entity" column for the approvals grid (docs/discovery/approval-gate.md §6):
 * renders the denormalized (entity_type, entity_id) pair as one label; the
 * clickable deep link (when EntityUrlResolver knows the type) lives in the
 * actions column (ApprovalActions), same convention as the "workflow" column.
 *
 * The type renders as its catalogue LABEL ("Order #142", not "sales_order #142"),
 * from the same admin-ui option source the workflow grid and form select use --
 * this package already requires mage-os/workflows-admin-ui. An entity type whose
 * pack is no longer installed keeps rendering its raw code.
 */
class ApprovalEntity extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly EntityType $entityTypeSource,
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
            $entityType = (string) ($item['entity_type'] ?? '');
            $entityId = (int) ($item['entity_id'] ?? 0);
            $item[$name] = $entityType !== ''
                ? sprintf('%s #%d', OptionLabel::resolve($this->entityTypeSource, $entityType), $entityId)
                : (string) $entityId;
        }

        return $dataSource;
    }
}
