<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;

/**
 * Options for the executions grid "Run Mode" column: a live/shadow execution
 * vs a persisted dry-run preview (03). Distinct from the workflow-status-derived
 * "Mode" (Shadow/Live) column — this reflects the execution's own mode value.
 */
class ExecutionMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => WorkflowExecutionInterface::MODE_LIVE, 'label' => __('Live')],
            ['value' => WorkflowExecutionInterface::MODE_DRY_RUN, 'label' => __('Dry-run')],
        ];
    }
}
