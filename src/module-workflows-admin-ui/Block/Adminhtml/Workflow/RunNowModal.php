<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Workflow;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Registry;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\DryRun\RecentEntityProviderPool;

/**
 * Backs the "Run Now" modal on the workflow edit form: the entity picker the
 * toolbar button used to ask for with a window.prompt. Same picker shape as the
 * dry-run page (recent-entity select + free numeric input, both server-rendered
 * from the shared RecentEntityProviderPool) so a type with no registered
 * provider simply falls back to manual id entry.
 *
 * Reads the currently-edited workflow from the registry the Edit controller
 * populates ('mageos_current_workflow', as History does). Renders nothing —
 * canRun() is false — on the new-workflow form or without the manual-run ACL,
 * the same two gates RunNowButton applies, so the button and the modal it opens
 * always appear together. The Run controller re-checks the ACL and the id.
 */
class RunNowModal extends Template
{
    public const ACL_MANUAL_RUN = 'MageOS_Workflows::manual_run';

    /**
     * The container id the toolbar button's on_click triggers its open event on.
     */
    public const CONTAINER_ID = 'mageos-run-now-modal';

    public function __construct(
        Context $context,
        private readonly Registry $coreRegistry,
        private readonly AuthorizationInterface $authorization,
        private readonly RecentEntityProviderPool $recentEntityProviderPool,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getWorkflow(): ?WorkflowInterface
    {
        $workflow = $this->coreRegistry->registry('mageos_current_workflow');
        return $workflow instanceof WorkflowInterface ? $workflow : null;
    }

    /**
     * False on the new-workflow form (nothing to run yet) and without the
     * dedicated manual-run resource.
     */
    public function canRun(): bool
    {
        return $this->getWorkflow()?->getWorkflowId() !== null
            && $this->authorization->isAllowed(self::ACL_MANUAL_RUN);
    }

    public function getEntityType(): string
    {
        $workflow = $this->getWorkflow();
        return $workflow !== null && $this->canRun() ? $workflow->getEntityType() : '';
    }

    /**
     * The Run controller URL WITHOUT the entity id: the URL builder appends the
     * adminhtml secret key, and extra path params after it are still routed, so
     * the JS appends 'entity_id/<n>/' to this — the same contract the prompt had.
     */
    public function getRunUrl(): string
    {
        $workflow = $this->getWorkflow();
        if (!$this->canRun() || $workflow === null) {
            return '';
        }
        return $this->getUrl('mageos_workflows/workflow/run', ['workflow_id' => $workflow->getWorkflowId()]);
    }

    /**
     * Recent entities of this workflow's type, newest first. Rows without a
     * positive id are dropped (a provider must not put an unusable option in
     * front of the operator) and labels fall back to the id.
     *
     * @return array<int, array{id: int, label: string}>
     */
    public function getRecentEntities(): array
    {
        if (!$this->canRun()) {
            return [];
        }

        $recent = [];
        foreach ($this->recentEntityProviderPool->getRecent($this->getEntityType()) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            $recent[] = ['id' => $id, 'label' => $label !== '' ? $label : (string) $id];
        }
        return $recent;
    }
}
