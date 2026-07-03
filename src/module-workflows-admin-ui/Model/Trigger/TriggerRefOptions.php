<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Model\Trigger;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\Workflows\Model\Trigger\TriggerRegistry;

/**
 * Grouped trigger_ref select for event-type workflows, sourced from the trigger metadata
 * registry (peer: MageOS\Workflows\Model\Trigger\TriggerRegistry::getAll(), each entry
 * ['event', 'entity', 'label', 'group']).
 */
class TriggerRefOptions implements OptionSourceInterface
{
    public function __construct(
        private readonly TriggerRegistry $triggerRegistry
    ) {
    }

    public function toOptionArray(): array
    {
        $groups = [];
        foreach ($this->triggerRegistry->getAll() as $trigger) {
            $group = (string) ($trigger['group'] ?? __('Other'));
            $groups[$group][] = [
                'value' => (string) ($trigger['event'] ?? ''),
                'label' => (string) ($trigger['label'] ?? ($trigger['event'] ?? '')),
            ];
        }

        $options = [];
        foreach ($groups as $group => $items) {
            $options[] = [
                'label' => $group,
                'value' => $items,
            ];
        }

        return $options;
    }
}
