<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;

class TriggerType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => WorkflowInterface::TRIGGER_TYPE_EVENT, 'label' => __('Event')],
            ['value' => WorkflowInterface::TRIGGER_TYPE_SCHEDULE, 'label' => __('Schedule')],
            ['value' => WorkflowInterface::TRIGGER_TYPE_MANUAL, 'label' => __('Manual')],
        ];
    }
}
