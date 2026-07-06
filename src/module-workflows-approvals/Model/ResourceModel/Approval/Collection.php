<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model\ResourceModel\Approval;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use MageOS\WorkflowsApprovals\Model\Approval;
use MageOS\WorkflowsApprovals\Model\ResourceModel\Approval as ApprovalResource;

class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'approval_id';

    /**
     * @var string
     */
    protected $_eventPrefix = 'mageos_workflow_approval_collection';

    /**
     * @var string
     */
    protected $_eventObject = 'approval_collection';

    protected function _construct(): void
    {
        $this->_init(Approval::class, ApprovalResource::class);
    }
}
