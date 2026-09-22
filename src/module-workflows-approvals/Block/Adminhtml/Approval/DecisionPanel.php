<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Block\Adminhtml\Approval;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Registry;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;
use MageOS\WorkflowsApprovals\Model\GateConfigReader;
use MageOS\WorkflowsApprovals\Model\OpenTaskLookup;

/**
 * The decision-taking panel (docs/discovery/approval-gate.md §6): note field +
 * a generated form for declared payload_fields (input type per declared type,
 * required markers; no free-form JSON entry) + Approve/Reject buttons posting
 * to Controller\Adminhtml\Approval\Decide. The SAME block/template is used on
 * the standalone decision view (Approval\View sets 'approval' data directly)
 * and embedded inline on the admin-ui execution-view page (the admin-extension
 * layout-handle pattern, view/adminhtml/layout/mageos_workflows_execution_view.xml)
 * where no 'approval' data is pre-set — it resolves the parked task itself from
 * the execution already registered by admin-ui's own controller
 * ('mageos_current_execution'), and renders nothing when the parked step is
 * not an open approval gate.
 */
class DecisionPanel extends Template
{
    private const ACL_DECIDE = 'MageOS_WorkflowsApprovals::approvals_decide';

    public function __construct(
        Context $context,
        private readonly Registry $coreRegistry,
        private readonly OpenTaskLookup $openTaskLookup,
        private readonly GateConfigReader $gateConfigReader,
        private readonly AuthorizationInterface $authorization,
        private readonly FormKey $formKeyProvider,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Embedded mode only renders when the current execution is genuinely
     * parked on an open approval gate; standalone mode (an 'approval' already
     * set via block data) always renders — the page exists to show it.
     */
    public function shouldRender(): bool
    {
        return $this->getApproval() !== null;
    }

    public function getApproval(): ?ApprovalInterface
    {
        $approval = $this->getData('approval');
        if ($approval instanceof ApprovalInterface) {
            return $approval;
        }
        // Standalone decision view: Controller\Adminhtml\Approval\View registers
        // the loaded task under this key (mirrors admin-ui's own
        // 'mageos_current_execution' registry convention).
        $registered = $this->coreRegistry->registry('mageos_current_approval');
        if ($registered instanceof ApprovalInterface) {
            return $registered;
        }
        return $this->resolveEmbeddedApproval();
    }

    public function isOpen(): bool
    {
        $approval = $this->getApproval();
        return $approval !== null && $approval->getStatus() === ApprovalInterface::STATUS_OPEN;
    }

    public function canDecide(): bool
    {
        return $this->authorization->isAllowed(self::ACL_DECIDE);
    }

    /**
     * @return array<int, array{key: string, label: string, type: string, required: bool}>
     */
    public function getPayloadFields(): array
    {
        $approval = $this->getApproval();
        if ($approval === null) {
            return [];
        }
        $fields = $this->gateConfigReader->getPayloadFields($approval->getExecutionId(), $approval->getStepKey());
        if ($fields === null) {
            return [];
        }
        $result = [];
        foreach ($fields as $field) {
            if (!is_array($field) || !isset($field['key'], $field['label'], $field['type'])) {
                continue;
            }
            $result[] = [
                'key' => (string) $field['key'],
                'label' => (string) $field['label'],
                'type' => (string) $field['type'],
                'required' => ($field['required'] ?? false) === true,
            ];
        }
        return $result;
    }

    public function getDecideUrl(): string
    {
        return $this->getUrl('mageos_workflows_approvals/approval/decide');
    }

    public function getFormKey(): string
    {
        return $this->formKeyProvider->getFormKey();
    }

    /**
     * Embedded mode's return-to-execution hint (Decide controller redirects
     * back to the execution view when this is present).
     */
    public function getExecutionId(): ?int
    {
        $approval = $this->getApproval();
        return $approval !== null ? $approval->getExecutionId() : null;
    }

    private function resolveEmbeddedApproval(): ?ApprovalInterface
    {
        $execution = $this->coreRegistry->registry('mageos_current_execution');
        if (!$execution instanceof WorkflowExecutionInterface) {
            return null;
        }
        if ($execution->getStatus() !== WorkflowExecutionInterface::STATUS_WAITING) {
            return null;
        }
        $stepKey = $execution->getCurrentStep();
        if ($stepKey === null || $stepKey === '') {
            return null;
        }
        $executionId = (int) $execution->getExecutionId();
        if (!$this->gateConfigReader->isApprovalStep($executionId, $stepKey)) {
            return null;
        }
        return $this->openTaskLookup->findOpenTask($executionId, $stepKey);
    }
}
