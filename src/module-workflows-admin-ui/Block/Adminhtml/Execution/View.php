<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Execution;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Store\Model\System\Store as SystemStore;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\ResourceModel\WorkflowExecutionStep\CollectionFactory;
use MageOS\Workflows\Model\Webapi\ExecutionDetailRedactor;
use MageOS\WorkflowsAdminUi\Model\OptionLabel;
use MageOS\WorkflowsAdminUi\Model\Source\EntityType;
use MageOS\WorkflowsAdminUi\Model\Source\ExecutionStatus;
use MageOS\WorkflowsAdminUi\Model\Source\TriggerType;

/**
 * Renders one execution plus its per-step timeline (docs/09 -- drill-down timeline).
 *
 * The step rows come from MageOS\Workflows\Model\ResourceModel\WorkflowExecutionStep\Collection
 * (peer, same naming convention as the Workflow/WorkflowExecution collections; no dedicated
 * step repository is part of the given peer context).
 *
 * SECRET REDACTION: this page is the third read path onto stored execution
 * detail (the other two are the steps REST route and the execution read model),
 * and like them it is reachable at the WEAKEST grant, MageOS_Workflows::view.
 * Step `result`/`error` and the execution `context` are written by the
 * production executor from interpolated action config, so a webhook URL with
 * its token or a raw HTTP-client exception message lands in them verbatim.
 * Everything this block hands the template for those three fields therefore
 * goes through the shared ExecutionDetailRedactor — the same rules and the same
 * server-side secret map the REST surfaces use. Redaction lives HERE and not in
 * the .phtml on purpose: a template-side string hack is invisible to tests, is
 * trivially forgotten by the next person who adds a field, and cannot be reused
 * by the canvas overlay. The template's job is escaping; this block's job is
 * deciding what is safe to show.
 */
class View extends Template
{
    public function __construct(
        Context $context,
        private readonly Registry $coreRegistry,
        private readonly WorkflowRepositoryInterface $workflowRepository,
        private readonly CollectionFactory $stepCollectionFactory,
        private readonly \Magento\Framework\Module\Manager $moduleManager,
        private readonly ExecutionStatus $executionStatusSource,
        private readonly TriggerType $triggerTypeSource,
        private readonly EntityType $entityTypeSource,
        private readonly SystemStore $systemStore,
        private readonly ExecutionDetailRedactor $detailRedactor,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * "Order #142" — the entity-type label stamped into the execution context
     * (Dispatcher::createExecution) plus the id. Falls back to the bare id when
     * the context names no type, and to the raw code when no option source
     * knows it: the row is never blank and never lies.
     */
    public function getEntityLabel(): string
    {
        $execution = $this->getExecution();
        if ($execution === null) {
            return '';
        }
        $entityId = $execution->getEntityId();
        $entityType = $this->getEntityType();

        return $entityType === ''
            ? (string) $entityId
            : sprintf('%s #%d', OptionLabel::resolve($this->entityTypeSource, $entityType), $entityId);
    }

    /**
     * Store-view name (the same catalogue the executions grid filters on) with the
     * id kept for support; the bare id when the store no longer resolves.
     */
    public function getStoreLabel(): string
    {
        $execution = $this->getExecution();
        if ($execution === null) {
            return '';
        }
        $storeId = $execution->getStoreId();
        try {
            $name = (string) $this->systemStore->getStoreName($storeId);
        } catch (\Throwable $e) {
            $name = '';
        }
        // System\Store joins website/group/store with newlines.
        $name = trim((string) preg_replace('/\s*\R\s*/', ' / ', $name));

        return $name === '' ? (string) $storeId : sprintf('%s (#%d)', $name, $storeId);
    }

    public function getStatusLabel(): string
    {
        $execution = $this->getExecution();

        return $execution === null
            ? ''
            : OptionLabel::resolve($this->executionStatusSource, $execution->getStatus());
    }

    public function getTriggerTypeLabel(): string
    {
        $execution = $this->getExecution();
        $triggerType = $execution === null ? '' : (string) $execution->getTriggerType();

        return $triggerType === '' ? '' : OptionLabel::resolve($this->triggerTypeSource, $triggerType);
    }

    /**
     * Step statuses are a strict subset of the execution status codes (the step
     * interface declares everything but `cancelled`), so the one ExecutionStatus
     * source labels both — no second, drift-prone list.
     */
    public function getStepStatusLabel(?string $status): string
    {
        return OptionLabel::resolve($this->executionStatusSource, (string) $status);
    }

    /**
     * Entity type of the owning workflow as stamped into the execution context.
     * The execution row itself has no entity_type column.
     */
    private function getEntityType(): string
    {
        $execution = $this->getExecution();
        if ($execution === null) {
            return '';
        }
        $context = json_decode((string) $execution->getContext(), true);
        $workflow = is_array($context) ? ($context['workflow'] ?? null) : null;

        return is_array($workflow) ? (string) ($workflow['entity_type'] ?? '') : '';
    }

    /**
     * URL of the visual canvas overlay for this execution, or null when the
     * optional canvas module is not installed/enabled (link then hidden).
     * admin-ui never depends on the canvas module.
     */
    public function getCanvasUrl(): ?string
    {
        $execution = $this->getExecution();
        if ($execution === null || !$this->moduleManager->isEnabled('MageOS_WorkflowsCanvas')) {
            return null;
        }
        return $this->getUrl('mageos_workflows_canvas/canvas/view', [
            'workflow_id' => $execution->getWorkflowId(),
            'execution_id' => $execution->getExecutionId(),
        ]);
    }

    public function getExecution(): ?WorkflowExecutionInterface
    {
        return $this->coreRegistry->registry('mageos_current_execution');
    }

    public function isDryRun(): bool
    {
        $execution = $this->getExecution();
        return $execution !== null && $execution->getMode() === WorkflowExecutionInterface::MODE_DRY_RUN;
    }

    public function getWorkflowName(): string
    {
        $execution = $this->getExecution();
        if (!$execution) {
            return '';
        }
        try {
            return $this->workflowRepository->getById($execution->getWorkflowId())->getName();
        } catch (NoSuchEntityException $e) {
            return (string) __('Workflow #%1 (deleted)', $execution->getWorkflowId());
        }
    }

    /**
     * @return \MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface[]
     */
    public function getSteps(): array
    {
        $execution = $this->getExecution();
        if (!$execution || !$execution->getExecutionId()) {
            return [];
        }

        $collection = $this->stepCollectionFactory->create();
        $collection->addFieldToFilter('execution_id', $execution->getExecutionId());
        $collection->setOrder('step_execution_id', 'ASC');

        return $collection->getItems();
    }

    /**
     * One step's failure text, secret-masked. Null/empty stays falsy so the
     * template can keep hiding the error box entirely.
     */
    public function getStepError(WorkflowExecutionStepInterface $step): string
    {
        return (string) $this->detailRedactor->redact($step->getError());
    }

    /**
     * One step's stored result blob, pretty-printed and secret-masked. This is
     * the field the executor fills with interpolated action output.
     */
    public function getStepResult(WorkflowExecutionStepInterface $step): string
    {
        return $this->redactedJson($step->getResult());
    }

    /**
     * The execution's context bag, pretty-printed and secret-masked. Holds the
     * trigger snapshot plus every completed step's output.
     */
    public function getContextJson(): string
    {
        return $this->redactedJson($this->getExecution()?->getContext());
    }

    /**
     * Pretty-print FIRST, then mask.
     *
     * Order matters: stored JSON escapes solidus as `\/`, so a webhook URL in
     * the raw blob does not contain the same byte sequence as the plaintext
     * secret and an exact-value match would miss it. Round-tripping through
     * json_decode/encode with JSON_UNESCAPED_SLASHES normalizes that first, so
     * the redactor's strongest layer (exact known secret values) actually
     * fires. Non-JSON input falls through formatJson() unchanged and is still
     * masked by the generic credential-shape rules.
     */
    private function redactedJson(?string $json): string
    {
        return (string) $this->detailRedactor->redact($this->formatJson($json));
    }

    public function formatJson(?string $json): string
    {
        if ($json === null || trim($json) === '') {
            return '';
        }
        $decoded = json_decode($json, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return $json;
        }
        return (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
