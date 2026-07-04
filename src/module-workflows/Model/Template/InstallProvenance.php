<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Template;

use Magento\Framework\App\ResourceConnection;

/**
 * Reads/writes mageos_workflow_template_install (F8): "installed from template X
 * v1.2" on the grid, and nothing else in v1 — fork-on-install, no upgrade path
 * (an updated template is a new install, not an upgrade; documented).
 *
 * The parameters snapshot never carries secret values: a `secret`-typed
 * parameter's value is the secret's key name, and secret values never travel in
 * a template or its provenance.
 */
class InstallProvenance
{
    private const TABLE = 'mageos_workflow_template_install';

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @param array<string, mixed> $parameters resolved parameter snapshot (no secret values)
     * @return int the new install_id
     */
    public function record(
        int $workflowId,
        string $templateCode,
        string $templateVersion,
        array $parameters,
        string $installedBy
    ): int {
        $connection = $this->resourceConnection->getConnection();
        $connection->insert($this->getTable(), [
            'workflow_id' => $workflowId,
            'template_code' => $templateCode,
            'template_version' => $templateVersion,
            'parameters' => (string) json_encode($parameters, JSON_UNESCAPED_SLASHES),
            'installed_by' => $installedBy,
        ]);

        return (int) $connection->lastInsertId($this->getTable());
    }

    /**
     * The provenance row for a workflow (most recent if re-installed onto the
     * same id, which v1 does not do), or null when it was not template-installed.
     *
     * @return array<string, mixed>|null
     */
    public function getByWorkflowId(int $workflowId): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable())
            ->where('workflow_id = ?', $workflowId)
            ->order('install_id DESC')
            ->limit(1);

        $row = $connection->fetchRow($select);
        return is_array($row) && $row !== [] ? $row : null;
    }

    private function getTable(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE);
    }
}
