<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Workflow;

use Magento\Backend\Block\Template;
use MageOS\Workflows\Api\Data\DefinitionValidationResultInterface;
use MageOS\Workflows\Api\Data\ValidationMessageInterface;
use MageOS\Workflows\Api\DefinitionValidationInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;

/**
 * Plain-language preview + validation-findings panel embedded after the
 * "Definition (JSON)" field on the workflow edit form (implementation plan 01,
 * stage 3). On load it server-renders the currently saved definition through
 * the F2 pipeline; the "Refresh preview" button re-runs the pipeline over the
 * unsaved textarea content via the Preview controller (no live-keystroke JS).
 *
 * Errors already block save (the F2 before-plugin) -- this panel is a
 * "did I build what I meant?" aid, so it surfaces both severities, warnings
 * anchored by their target step_key.
 */
class DefinitionPreview extends Template
{
    /**
     * @var string
     */
    protected $_template = 'MageOS_WorkflowsAdminUi::workflow/definition_preview.phtml';

    public function __construct(
        Template\Context $context,
        private readonly WorkflowRepositoryInterface $workflowRepository,
        private readonly DefinitionValidationInterface $definitionValidation,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * The saved workflow's validation result for the initial (server) render,
     * or null on the new-workflow form / when the record cannot be loaded.
     */
    public function getInitialResult(): ?DefinitionValidationResultInterface
    {
        $workflowId = (int) $this->getRequest()->getParam('workflow_id');
        if ($workflowId === 0) {
            return null;
        }
        try {
            $workflow = $this->workflowRepository->getById($workflowId);
            $definition = (string) $workflow->getDefinition();
            if (trim($definition) === '') {
                return null;
            }
            return $this->definitionValidation->validate(
                $definition,
                $workflow->getConditionsSerialized(),
                $workflow->getTriggerType(),
                $workflow->getTriggerRef(),
                $workflow->getEntityType()
            );
        } catch (\Exception $e) {
            // Never let a preview failure break the edit form; the merchant can
            // still edit and save (which runs its own validation).
            return null;
        }
    }

    public function getRefreshUrl(): string
    {
        return $this->getUrl('mageos_workflows/workflow/preview');
    }

    /**
     * Display label for a finding's severity.
     */
    public function getSeverityLabel(string $severity): \Magento\Framework\Phrase
    {
        return $severity === ValidationMessageInterface::SEVERITY_ERROR ? __('Error') : __('Warning');
    }
}
