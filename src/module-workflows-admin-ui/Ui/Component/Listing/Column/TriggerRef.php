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
use MageOS\Workflows\Model\Trigger\TriggerRegistry;

/**
 * "Trigger" column for the workflows grid: shows the declared label of an event
 * trigger_ref ("sales.order.created" => "Order Created") instead of the raw
 * event name.
 *
 * trigger_ref is polymorphic — an async event name for TRIGGER_TYPE_EVENT, a cron
 * expression for TRIGGER_TYPE_SCHEDULE, empty/free-form for manual. Only declared
 * events resolve; everything else (a cron string, an event whose pack was
 * uninstalled) renders raw, which is both the honest and the useful rendering.
 *
 * Cheap: trigger_ref is already on the raw grid row and TriggerRegistry reads the
 * cached merged workflow_triggers.xml, so no extra query per row.
 */
class TriggerRef extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly TriggerRegistry $triggerRegistry,
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
            $item[$name] = $this->label((string) ($item['trigger_ref'] ?? ''));
        }

        return $dataSource;
    }

    private function label(string $triggerRef): string
    {
        if ($triggerRef === '') {
            return '';
        }

        $trigger = $this->triggerRegistry->getByEvent($triggerRef);
        $label = is_array($trigger) ? (string) ($trigger['label'] ?? '') : '';

        return $label !== '' ? $label : $triggerRef;
    }
}
