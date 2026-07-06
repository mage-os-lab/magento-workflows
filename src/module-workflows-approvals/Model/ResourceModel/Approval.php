<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Approval extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('mageos_workflow_approval', 'approval_id');
    }
}
