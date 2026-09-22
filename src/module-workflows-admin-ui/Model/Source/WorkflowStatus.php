<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;

class WorkflowStatus implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => WorkflowInterface::STATUS_DISABLED, 'label' => __('Disabled')],
            ['value' => WorkflowInterface::STATUS_ENABLED, 'label' => __('Enabled')],
            ['value' => WorkflowInterface::STATUS_SHADOW, 'label' => __('Shadow')],
            ['value' => WorkflowInterface::STATUS_SUSPENDED, 'label' => __('Suspended')],
        ];
    }
}
