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
 * Read-only visual viewer (Phase A). Gated by MageOS_Workflows::view — the same
 * read ACL as the grid and execution log. The editor controller (Phase B) will
 * be a sibling gated by ::manage. No menu node: entry is via "Open in visual
 * editor" links on the workflow grid/form and execution view.
 */
class View extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Workflows::view';

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
        $resultPage->getConfig()->getTitle()->prepend(__('Workflow Canvas'));
        return $resultPage;
    }
}
