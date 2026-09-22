<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\ResourceModel;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use MageOS\Workflows\Model\Workflow as WorkflowModel;

class Workflow extends AbstractDb
{
    public const LINK_TABLE = 'mageos_workflow_website';

    protected function _construct(): void
    {
        $this->_init('mageos_workflow', 'workflow_id');
    }

    /**
     * Load linked website IDs into the model
     *
     * @param AbstractModel $object
     * @return $this
     */
    protected function _afterLoad(AbstractModel $object)
    {
        if ($object->getId()) {
            $object->setData(WorkflowModel::WEBSITE_IDS, $this->lookupWebsiteIds((int)$object->getId()));
            $object->setOrigData(WorkflowModel::WEBSITE_IDS, $object->getData(WorkflowModel::WEBSITE_IDS));
        }
        return parent::_afterLoad($object);
    }

    /**
     * Persist linked website IDs to the link table
     *
     * @param AbstractModel $object
     * @return $this
     */
    protected function _afterSave(AbstractModel $object)
    {
        if ($object->hasData(WorkflowModel::WEBSITE_IDS)) {
            $websiteIds = $object->getData(WorkflowModel::WEBSITE_IDS);
            $this->saveWebsiteIds(
                (int)$object->getId(),
                is_array($websiteIds) ? $websiteIds : explode(',', (string)$websiteIds)
            );
        }
        return parent::_afterSave($object);
    }

    /**
     * @return int[]
     */
    public function lookupWebsiteIds(int $workflowId): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable(self::LINK_TABLE), 'website_id')
            ->where('workflow_id = ?', $workflowId);

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * @param int[] $websiteIds
     */
    public function saveWebsiteIds(int $workflowId, array $websiteIds): void
    {
        $connection = $this->getConnection();
        $table = $this->getTable(self::LINK_TABLE);
        $websiteIds = array_values(array_unique(array_map('intval', $websiteIds)));

        if ($websiteIds === []) {
            $connection->delete($table, ['workflow_id = ?' => $workflowId]);
            return;
        }

        $connection->delete(
            $table,
            [
                'workflow_id = ?' => $workflowId,
                'website_id NOT IN (?)' => $websiteIds,
            ]
        );

        $rows = [];
        foreach ($websiteIds as $websiteId) {
            $rows[] = ['workflow_id' => $workflowId, 'website_id' => $websiteId];
        }
        $connection->insertOnDuplicate($table, $rows, ['website_id']);
    }
}
