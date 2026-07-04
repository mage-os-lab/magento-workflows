<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\ResourceModel\Workflow;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use MageOS\Workflows\Model\ResourceModel\Workflow as WorkflowResource;
use MageOS\Workflows\Model\Workflow;

class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'workflow_id';

    /**
     * @var string
     */
    protected $_eventPrefix = 'mageos_workflow_collection';

    /**
     * @var string
     */
    protected $_eventObject = 'workflow_collection';

    protected function _construct(): void
    {
        $this->_init(Workflow::class, WorkflowResource::class);
    }

    /**
     * Attach linked website IDs to all loaded items in a single query
     *
     * @return $this
     */
    protected function _afterLoad()
    {
        parent::_afterLoad();

        $workflowIds = array_map('intval', array_filter($this->getColumnValues('workflow_id')));
        if ($workflowIds !== []) {
            $connection = $this->getConnection();
            $select = $connection->select()
                ->from($this->getTable(WorkflowResource::LINK_TABLE), ['workflow_id', 'website_id'])
                ->where('workflow_id IN (?)', $workflowIds);

            $websiteIdsByWorkflow = [];
            foreach ($connection->fetchAll($select) as $row) {
                $websiteIdsByWorkflow[(int)$row['workflow_id']][] = (int)$row['website_id'];
            }
            /** @var Workflow $item */
            foreach ($this->getItems() as $item) {
                $item->setData(
                    Workflow::WEBSITE_IDS,
                    $websiteIdsByWorkflow[(int)$item->getData('workflow_id')] ?? []
                );
            }
        }

        return $this;
    }

}
