<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsCanvas\Controller\Adminhtml\Canvas;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * Visual editor page (Phase B). Sibling of the read-only View controller, gated
 * by MageOS_Workflows::manage — the same write ACL as the classic Save
 * controller. It renders the identical mount page; the React app enables
 * editing affordances from grants.manage (true here) and the write controllers
 * (Data/Validate, the admin Save controller) re-check ACL server-side, so the
 * ACL is enforced at every layer, not just by which page loaded.
 *
 * Entry links: "Edit in visual editor" on the workflow grid/form for managers;
 * ::view-only admins get the viewer URL instead.
 *
 * Also the canvas-first CREATE surface: with no workflow_id the mount block
 * bootstraps a blank workflow for managers and the page authors a brand-new
 * one, which the admin Save controller persists (back=canvas returns here).
 */
class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Workflows::manage';

    public function __construct(
        Action\Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        /** @var Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('MageOS_Workflows::workflows');
        $resultPage->getConfig()->getTitle()->prepend(
            (int) $this->getRequest()->getParam('workflow_id') > 0
                ? __('Workflow Visual Editor')
                : __('New Workflow (Visual Editor)')
        );
        return $resultPage;
    }
}
