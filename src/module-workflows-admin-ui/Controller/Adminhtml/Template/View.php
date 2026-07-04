<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Controller\Adminhtml\Template;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use MageOS\Workflows\Model\Template\TemplateSourceInterface;

/**
 * Template detail (06): plain-language rendering of the defaults-substituted
 * workflow (via the core PlainLanguageRenderer), the `requires` panel, and the
 * parameter list, with an Install call-to-action.
 */
class View extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Workflows::manage';

    public function __construct(
        Action\Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly TemplateSourceInterface $templateSource
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $code = (string) $this->getRequest()->getParam('code');
        if ($code === '' || !$this->templateSource->has($code)) {
            $this->messageManager->addErrorMessage(__('That workflow template is not available.'));
            /** @var Redirect $redirect */
            $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            return $redirect->setPath('mageos_workflows/template/index');
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('MageOS_Workflows::template_index');
        $resultPage->getConfig()->getTitle()->prepend(__('Workflow Template'));
        return $resultPage;
    }
}
