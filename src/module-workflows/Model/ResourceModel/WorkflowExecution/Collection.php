<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\ResourceModel\WorkflowExecution;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use MageOS\Workflows\Model\ResourceModel\WorkflowExecution as WorkflowExecutionResource;
use MageOS\Workflows\Model\WorkflowExecution;

class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'execution_id';

    /**
     * @var string
     */
    protected $_eventPrefix = 'mageos_workflow_execution_collection';

    /**
     * @var string
     */
    protected $_eventObject = 'execution_collection';

    protected function _construct(): void
    {
        $this->_init(WorkflowExecution::class, WorkflowExecutionResource::class);
    }
}
