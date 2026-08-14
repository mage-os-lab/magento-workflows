<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Ui\Component\MassAction\Filter;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\ResourceModel\Workflow\CollectionFactory;

class MassDelete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Workflows::manage';

    public function __construct(
        Action\Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly WorkflowRepositoryInterface $workflowRepository
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        $collection = $this->filter->getCollection($this->collectionFactory->create());
        $deleted = 0;
        $failed = 0;
        foreach ($collection->getItems() as $item) {
            try {
                $this->workflowRepository->deleteById((int) $item->getId());
                $deleted++;
            } catch (\Exception $e) {
                $failed++;
            }
        }

        if ($deleted) {
            $this->messageManager->addSuccessMessage(__('A total of %1 workflow(s) have been deleted.', $deleted));
        }
        if ($failed) {
            $this->messageManager->addErrorMessage(__('%1 workflow(s) could not be deleted.', $failed));
        }

        return $resultRedirect->setPath('mageos_workflows/workflow/index');
    }
}
