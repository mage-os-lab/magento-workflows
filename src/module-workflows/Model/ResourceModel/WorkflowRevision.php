<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Insert-only archive of prior workflow definitions; powers the change-history UI.
 * No model class — rows are written on version bump and read via plain selects.
 */
class WorkflowRevision extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('mageos_workflow_revision', 'revision_id');
    }

    /**
     * Archive a prior definition revision
     */
    public function archive(int $workflowId, int $version, string $definition, ?string $conditionsSerialized): void
    {
        $this->getConnection()->insertOnDuplicate(
            $this->getMainTable(),
            [
                'workflow_id' => $workflowId,
                'version' => $version,
                'definition' => $definition,
                'conditions_serialized' => $conditionsSerialized,
            ],
            ['definition', 'conditions_serialized']
        );
    }

    /**
     * Archived revisions for one workflow, newest first
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRevisions(int $workflowId): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable())
            ->where('workflow_id = ?', $workflowId)
            ->order('version DESC');

        return $connection->fetchAll($select);
    }
}
