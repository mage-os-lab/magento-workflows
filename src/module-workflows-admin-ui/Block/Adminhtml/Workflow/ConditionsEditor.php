<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Workflow;

use Magento\Backend\Block\Template;

/**
 * Renders the classic form's "Edit conditions…" slide-out trigger next to the
 * conditions_serialized textarea (which is retained as the "edit as JSON"
 * fallback + power-user path). The shared slide-out widget
 * (MageOS_WorkflowsAdminUi/js/conditions-slideout) is booted declaratively via
 * data-mage-init — no inline script (CSP) — and reads its config from the
 * data-* attributes this block emits.
 *
 * This is the classic-form half of the E1 embedding; the canvas node panel
 * shares the same serialized-tree contract and the same apply endpoint. See
 * docs/11-admin-ui.md for the E1 spike verdict (JSON fallback shipped; rule
 * widget deferred until a full install can host it).
 */
class ConditionsEditor extends Template
{
    /** @var string */
    protected $_template = 'MageOS_WorkflowsAdminUi::workflow/conditions_editor.phtml';

    /** The admin apply endpoint the slide-out posts the serialized tree to. */
    public function getApplyUrl(): string
    {
        return $this->getUrl('mageos_workflows/workflow/conditions');
    }

    /** DOM selector of the ui-component textarea whose value the slide-out edits. */
    public function getFieldSelector(): string
    {
        return '[name="conditions_serialized"]';
    }
}
