<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Workflow\Edit;

use Magento\Backend\Block\Widget\Context;

/**
 * Shared request/URL/ACL access for the workflow edit form's toolbar buttons.
 *
 * The buttons are declared in mageos_workflows_form.xml's <settings><buttons>
 * and rendered by Magento\Ui\Component\Control\Container — the uiComponent
 * mechanism, NOT a Widget\Form\Container block. That distinction is the whole
 * point: Magento_Ui/js/form/adapter binds the form's save/reset handlers to the
 * literal selectors '#save' and '#reset' (see Magento_Ui/js/form/adapter/buttons),
 * and the uiComponent button renderer is what emits those ids. A toolbar button
 * contributed by a container block carries no id and is never bound, which is
 * why Save did nothing on this form.
 *
 * Peer convention: Magento\Cms\Block\Adminhtml\Page\Edit\GenericButton.
 */
abstract class GenericButton
{
    public function __construct(
        protected readonly Context $context
    ) {
    }

    /**
     * Null on the new-workflow form.
     */
    public function getWorkflowId(): ?int
    {
        $id = (int) $this->context->getRequest()->getParam('workflow_id');
        return $id ?: null;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function getUrl(string $route = '', array $params = []): string
    {
        return $this->context->getUrlBuilder()->getUrl($route, $params);
    }

    protected function isAllowed(string $resource): bool
    {
        return $this->context->getAuthorization()->isAllowed($resource);
    }
}
