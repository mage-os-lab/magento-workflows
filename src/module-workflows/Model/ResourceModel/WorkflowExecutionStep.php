<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class WorkflowExecutionStep extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('mageos_workflow_execution_step', 'step_execution_id');
    }
}
