<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Workflow;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\ResourceModel\WorkflowRevision;
use MageOS\WorkflowsAdminUi\Model\Workflow\RevisionHistory;

/**
 * Change-history panel for the workflow edit form (docs/11-admin-ui.md,
 * "Change history with diffs" -- diffs are out of scope, this is the list
 * view: the current plain-language sentence plus one row per archived
 * revision). Gives WorkflowRevision::getRevisions() -- previously without a
 * caller -- and the core PlainLanguageRenderer::render() -- previously
 * without a caller either, despite docs/11 promising it "on the form header"
 * -- their first read-side consumers.
 *
 * Reads the currently-edited workflow from the registry the Edit controller
 * already populates ('mageos_current_workflow', same convention as
 * Execution\View's 'mageos_current_execution'). Renders nothing on the
 * new-workflow form: there is no workflow id yet, so nothing is archived.
 */
class History extends Template
{
    public function __construct(
        Context $context,
        private readonly Registry $coreRegistry,
        private readonly WorkflowRevision $revisionResource,
        private readonly RevisionHistory $revisionHistory,
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
     * The workflow's current plain-language sentence, or '' when there is no
     * workflow being edited (new-workflow form).
     */
    public function getCurrentSentence(): string
    {
        $workflow = $this->getWorkflow();
        return $workflow === null ? '' : $this->revisionHistory->getCurrentSentence($workflow);
    }

    /**
     * Archived revisions, newest first, each with a plain-language rendering
     * of that revision's definition/conditions.
     *
     * @return array<int, array{version: int, created_at: ?string, sentence: string}>
     */
    public function getRevisionEntries(): array
    {
        $workflow = $this->getWorkflow();
        $workflowId = $workflow?->getWorkflowId();
        if ($workflow === null || $workflowId === null) {
            return [];
        }

        $rows = $this->revisionResource->getRevisions($workflowId);
        return $this->revisionHistory->buildEntries($workflow, $rows);
    }
}
