<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;

class ExecutionStatus implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => WorkflowExecutionInterface::STATUS_PENDING, 'label' => __('Pending')],
            ['value' => WorkflowExecutionInterface::STATUS_RUNNING, 'label' => __('Running')],
            ['value' => WorkflowExecutionInterface::STATUS_WAITING, 'label' => __('Waiting')],
            ['value' => WorkflowExecutionInterface::STATUS_COMPLETE, 'label' => __('Complete')],
            ['value' => WorkflowExecutionInterface::STATUS_SKIPPED, 'label' => __('Skipped')],
            ['value' => WorkflowExecutionInterface::STATUS_FAILED, 'label' => __('Failed')],
            ['value' => WorkflowExecutionInterface::STATUS_CANCELLED, 'label' => __('Cancelled')],
        ];
    }
}
