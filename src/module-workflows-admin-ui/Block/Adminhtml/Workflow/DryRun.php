<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Workflow;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use MageOS\Workflows\Model\DryRun\Trace;
use MageOS\Workflows\Model\DryRun\TraceStep;
use MageOS\Workflows\Model\DryRun\TraceStepStatus;
use MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow\DryRun as DryRunController;

/**
 * Renders the dry-run form (entity picker + editable definition) and the trace
 * panel: plain-language labels per step, with a technical-details expander for
 * failures/config/condition. Server-rendered, modest JS.
 */
class DryRun extends Template
{
    public function __construct(
        Context $context,
        private readonly Registry $coreRegistry,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function getInput(): array
    {
        $input = $this->coreRegistry->registry(DryRunController::REGISTRY_INPUT);
        return is_array($input) ? $input : [];
    }

    public function getTrace(): ?Trace
    {
        $trace = $this->coreRegistry->registry(DryRunController::REGISTRY_TRACE);
        return $trace instanceof Trace ? $trace : null;
    }

    public function getFormActionUrl(): string
    {
        return $this->getUrl('mageos_workflows/workflow/dryRun');
    }

    public function getBackUrl(): string
    {
        $input = $this->getInput();
        if (!empty($input['workflow_id'])) {
            return $this->getUrl('mageos_workflows/workflow/edit', ['workflow_id' => $input['workflow_id']]);
        }
        return $this->getUrl('mageos_workflows/workflow/index');
    }

    public function getExecutionUrl(int $executionId): string
    {
        return $this->getUrl('mageos_workflows/execution/view', ['execution_id' => $executionId]);
    }

    /**
     * Plain-language label for a step status (docs/11 merchant vocabulary).
     */
    public function statusLabel(TraceStepStatus $status): \Magento\Framework\Phrase
    {
        return match ($status) {
            TraceStepStatus::WOULD_RUN => __('Would run'),
            TraceStepStatus::WOULD_FAIL => __('Would fail'),
            TraceStepStatus::SKIPPED => __('Skipped'),
            TraceStepStatus::PRODUCTION_STOPS_HERE => __('Production would stop before here'),
        };
    }

    public function statusClass(TraceStepStatus $status): string
    {
        return match ($status) {
            TraceStepStatus::WOULD_RUN => 'grid-severity-notice',
            TraceStepStatus::WOULD_FAIL => 'grid-severity-critical',
            TraceStepStatus::SKIPPED, TraceStepStatus::PRODUCTION_STOPS_HERE => 'grid-severity-minor',
        };
    }

    /**
     * Technical-details payload for a step's expander (config, condition,
     * timing, notes, path ids), pretty JSON.
     */
    public function technicalDetails(TraceStep $step): string
    {
        $detail = array_filter([
            'config' => $step->getConfig() !== [] ? $step->getConfig() : null,
            'condition' => $step->getCondition(),
            'timing' => $step->getTiming(),
            'notes' => $step->getNotes() !== [] ? $step->getNotes() : null,
            'path_ids' => $step->getPathIds(),
        ], static fn ($v): bool => $v !== null && $v !== []);

        return (string) json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
