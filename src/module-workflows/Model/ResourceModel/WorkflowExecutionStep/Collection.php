<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\ResourceModel\WorkflowExecutionStep;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use MageOS\Workflows\Model\ResourceModel\WorkflowExecutionStep as WorkflowExecutionStepResource;
use MageOS\Workflows\Model\WorkflowExecutionStep;

class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'step_execution_id';

    /**
     * @var string
     */
    protected $_eventPrefix = 'mageos_workflow_execution_step_collection';

    /**
     * @var string
     */
    protected $_eventObject = 'execution_step_collection';

    protected function _construct(): void
    {
        $this->_init(WorkflowExecutionStep::class, WorkflowExecutionStepResource::class);
    }
}
