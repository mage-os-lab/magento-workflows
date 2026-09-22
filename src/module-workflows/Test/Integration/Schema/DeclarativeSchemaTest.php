<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\Schema;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Plan #1 (docs/20-integration-test-plan.md §4): the declarative schema as
 * installed by the test framework. Pins that every table in db_schema.xml
 * exists, that the whitelist is in sync with the declared schema, and the
 * FK/cascade semantics the runtime relies on (website links and execution
 * steps die with their parent; executions die with their workflow — the
 * retention clock in PruneExecutions is the only other row reaper).
 */
class DeclarativeSchemaTest extends TestCase
{
    private const TABLES = [
        'mageos_workflow',
        'mageos_workflow_website',
        'mageos_workflow_revision',
        'mageos_workflow_execution',
        'mageos_workflow_execution_step',
        'mageos_workflow_debounce',
        'mageos_workflow_stock_flag',
        'mageos_workflow_batch',
        'mageos_workflow_batch_item',
        'mageos_workflow_secret',
        'mageos_workflow_template_install',
    ];

    private ResourceConnection $resource;

    protected function setUp(): void
    {
        $this->resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
    }

    public function testAllDeclaredTablesAreInstalled(): void
    {
        $connection = $this->resource->getConnection();
        foreach (self::TABLES as $table) {
            $this->assertTrue(
                $connection->isTableExists($this->resource->getTableName($table)),
                sprintf('Declared table "%s" was not installed', $table)
            );
        }
    }

    public function testWhitelistCoversEveryDeclaredTable(): void
    {
        $moduleDir = Bootstrap::getObjectManager()
            ->get(ComponentRegistrar::class)
            ->getPath(ComponentRegistrar::MODULE, 'MageOS_Workflows');
        $this->assertNotNull($moduleDir, 'MageOS_Workflows module dir not registered');

        $schemaXml = file_get_contents($moduleDir . '/etc/db_schema.xml');
        $this->assertNotFalse($schemaXml);
        preg_match_all('/<table name="([^"]+)"/', $schemaXml, $matches);
        $declared = $matches[1];
        sort($declared);

        $whitelist = json_decode(
            (string)file_get_contents($moduleDir . '/etc/db_schema_whitelist.json'),
            true
        );
        $this->assertIsArray($whitelist, 'db_schema_whitelist.json must decode');
        $whitelisted = array_keys($whitelist);
        sort($whitelisted);

        $this->assertSame(
            $declared,
            $whitelisted,
            'db_schema_whitelist.json is out of sync with db_schema.xml — regenerate it'
        );
    }

    /**
     * JSON payloads are declared mediumtext, never xsi:type="json".
     *
     * MariaDB implements JSON as an alias for LONGTEXT, so the declarative-schema
     * differ builds the declared column as Dto\Columns\Blob and the introspected one
     * as Dto\Columns\Text; Comparator::compare() compares get_class() first, so a
     * json column reports a modify_column that setup:upgrade can never clear. See
     * docs/15-operations.md "Declarative schema and JSON columns".
     */
    public function testNoColumnIsDeclaredWithTheJsonType(): void
    {
        $moduleDir = Bootstrap::getObjectManager()
            ->get(ComponentRegistrar::class)
            ->getPath(ComponentRegistrar::MODULE, 'MageOS_Workflows');
        $this->assertNotNull($moduleDir, 'MageOS_Workflows module dir not registered');

        $schemaXml = (string)file_get_contents($moduleDir . '/etc/db_schema.xml');
        preg_match_all('/<column[^>]*xsi:type="json"[^>]*name="([^"]+)"/', $schemaXml, $matches);

        $this->assertSame(
            [],
            $matches[1],
            'db_schema.xml declares xsi:type="json" column(s) — use mediumtext instead, '
            . 'json never compares equal on MariaDB and leaves setup:db:status permanently dirty'
        );
    }

    /**
     * @magentoDbIsolation enabled
     */
    public function testDeletingWorkflowCascadesWebsiteLinksRevisionsAndExecutions(): void
    {
        $connection = $this->resource->getConnection();
        $workflowTable = $this->resource->getTableName('mageos_workflow');
        $websiteTable = $this->resource->getTableName('mageos_workflow_website');
        $revisionTable = $this->resource->getTableName('mageos_workflow_revision');
        $executionTable = $this->resource->getTableName('mageos_workflow_execution');
        $stepTable = $this->resource->getTableName('mageos_workflow_execution_step');

        $connection->insert($workflowTable, [
            'name' => 'cascade probe',
            'status' => 0,
            'trigger_type' => 'event',
            'trigger_ref' => 'sales.order.created',
            'entity_type' => 'sales_order',
            'definition' => '{"schema":1,"entry":"s1","steps":{"s1":{"type":"action",'
                . '"action":"order.add_comment","config":{},"next":null}}}',
        ]);
        $workflowId = (int)$connection->lastInsertId($workflowTable);

        $connection->insert($websiteTable, ['workflow_id' => $workflowId, 'website_id' => 1]);
        $connection->insert($revisionTable, [
            'workflow_id' => $workflowId,
            'version' => 1,
            'definition' => '{}',
        ]);
        $connection->insert($executionTable, [
            'uuid' => '00000000-0000-0000-0000-00000000cade',
            'workflow_id' => $workflowId,
            'workflow_version' => 1,
            'definition_snapshot' => '{}',
            'entity_id' => 1,
            'store_id' => 1,
            'status' => 'complete',
        ]);
        $executionId = (int)$connection->lastInsertId($executionTable);
        $connection->insert($stepTable, [
            'execution_id' => $executionId,
            'step_key' => 's1',
            'status' => 'complete',
        ]);

        $connection->delete($workflowTable, ['workflow_id = ?' => $workflowId]);

        foreach (
            [
                'website links' => [$websiteTable, 'workflow_id', $workflowId],
                'revisions' => [$revisionTable, 'workflow_id', $workflowId],
                'executions' => [$executionTable, 'workflow_id', $workflowId],
                'execution steps' => [$stepTable, 'execution_id', $executionId],
            ] as $label => [$table, $column, $id]
        ) {
            $count = (int)$connection->fetchOne(
                $connection->select()->from($table, 'COUNT(*)')->where($column . ' = ?', $id)
            );
            $this->assertSame(0, $count, sprintf('Expected %s to cascade on workflow delete', $label));
        }
    }

    public function testApprovalsTableInstalledWhenModuleEnabled(): void
    {
        $moduleDir = Bootstrap::getObjectManager()
            ->get(ComponentRegistrar::class)
            ->getPath(ComponentRegistrar::MODULE, 'MageOS_WorkflowsApprovals');
        if ($moduleDir === null) {
            $this->markTestSkipped('MageOS_WorkflowsApprovals is not installed');
        }
        $this->assertTrue(
            $this->resource->getConnection()->isTableExists(
                $this->resource->getTableName('mageos_workflow_approval')
            )
        );
    }
}
