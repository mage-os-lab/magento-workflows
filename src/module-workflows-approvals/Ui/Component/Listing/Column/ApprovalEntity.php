<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * "Entity" column for the approvals grid (docs/discovery/approval-gate.md §6):
 * renders the denormalized (entity_type, entity_id) pair as one label; the
 * clickable deep link (when EntityUrlResolver knows the type) lives in the
 * actions column (ApprovalActions), same convention as the "workflow" column.
 */
class ApprovalEntity extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
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
            $item[$name] = $entityType !== '' ? sprintf('%s #%d', $entityType, $entityId) : (string) $entityId;
        }

        return $dataSource;
    }
}
