<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\Console;

use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Console\Command\ExportCommand;
use MageOS\Workflows\Console\Command\ImportCommand;
use MageOS\Workflows\Model\Definition\Definition;
use MageOS\Workflows\Model\WorkflowFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Plan #29 (docs/20-integration-test-plan.md §7): `workflow:export` of a saved
 * workflow re-imports (via `workflow:import`) to a deep-equal definition, and
 * every published `spec/fixtures/*.json` envelope imports cleanly. Both
 * commands are exercised via Symfony\Component\Console\Tester\CommandTester
 * with the command objects built from the real object manager, so the real
 * WorkflowImporter -> WorkflowValidator -> WorkflowRepositoryInterface::save
 * pipeline runs underneath (ValidateWorkflowOnSave applies to every import).
 *
 * "Deep-equal" is decoded-JSON equality throughout (assertEquals over decoded
 * arrays), never byte/string equality — MySQL's `json` column type and
 * re-encoding both reorder/renormalize whitespace.
 *
 * @magentoDbIsolation enabled
 */
class ImportExportRoundTripTest extends TestCase
{
    private WorkflowRepositoryInterface $workflowRepository;
    private WorkflowFactory $workflowFactory;
    private ResourceConnection $resourceConnection;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->workflowRepository = $objectManager->get(WorkflowRepositoryInterface::class);
        $this->workflowFactory = $objectManager->get(WorkflowFactory::class);
        $this->resourceConnection = $objectManager->get(ResourceConnection::class);
    }

    public function testExportThenImportRoundTripsToADeepEqualDefinition(): void
    {
        $definition = [
            // Current schema: export/import normalizes legacy versions upward,
            // so only a current-schema fixture round-trips decoded-equal.
            'schema' => Definition::SCHEMA_VERSION,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => 'branch',
                    'conditions_serialized' => null,
                    'revalidate_entity' => true,
                    'on_true' => 's2',
                    'on_false' => null,
                ],
                's2' => [
                    'type' => 'action',
                    'action' => 'order.add_comment',
                    'config' => ['comment' => 'round trip 検証 ✅'],
                    'next' => null,
                ],
            ],
        ];
        $conditions = '{"type":"combine","aggregator":"all","value":"1","conditions":'
            . '[{"type":"order_attribute","attribute":"grand_total","operator":">","value":"0"}]}';

        $workflow = $this->workflowFactory->create();
        $workflow->setName('export round trip fixture');
        $workflow->setStatus(WorkflowInterface::STATUS_ENABLED);
        $workflow->setTriggerType(WorkflowInterface::TRIGGER_TYPE_EVENT);
        $workflow->setTriggerRef('sales.order.created');
        $workflow->setEntityType('sales_order');
        $workflow->setConditionsSerialized($conditions);
        $workflow->setLoopGuardDepth(2);
        $workflow->setDefinition(json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $saved = $this->workflowRepository->save($workflow);
        $workflowId = (int) $saved->getWorkflowId();

        $exportTester = new CommandTester(Bootstrap::getObjectManager()->get(ExportCommand::class));
        $exportExit = $exportTester->execute(['workflow_id' => (string) $workflowId]);
        $this->assertSame(0, $exportExit, $exportTester->getDisplay());

        $envelope = json_decode($exportTester->getDisplay(), true);
        $this->assertIsArray($envelope, 'workflow:export must print a JSON envelope');
        $this->assertExportEnvelopeShape($envelope);
        $this->assertSame('mageos-workflow-export/1', $envelope['format']);
        $this->assertSame('export round trip fixture', $envelope['name']);
        $this->assertSame('sales_order', $envelope['entity_type']);
        $this->assertSame('event', $envelope['trigger_type']);
        $this->assertSame('sales.order.created', $envelope['trigger_ref']);
        $this->assertSame(2, $envelope['loop_guard_depth']);
        $this->assertEquals($definition, $envelope['definition'], 'Exported definition must decoded-JSON equal the saved one');
        $this->assertEquals(
            json_decode($conditions, true),
            json_decode((string) $envelope['conditions_serialized'], true),
            'Exported conditions must decoded-JSON equal the saved ones'
        );

        $exportFile = sys_get_temp_dir() . '/mageos_workflow_export_' . uniqid('', true) . '.json';
        file_put_contents($exportFile, json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        try {
            $importTester = new CommandTester(Bootstrap::getObjectManager()->get(ImportCommand::class));
            $importExit = $importTester->execute(['file' => $exportFile]);
            $this->assertSame(0, $importExit, $importTester->getDisplay());

            $importedId = (int) $this->lastNonEmptyLine($importTester->getDisplay());
            $this->assertGreaterThan(0, $importedId);
            $this->assertNotSame($workflowId, $importedId, 'Import must create a NEW workflow, not overwrite the original');

            $imported = $this->workflowRepository->getById($importedId);
            $this->assertSame(WorkflowInterface::STATUS_DISABLED, $imported->getStatus(), 'workflow:import defaults to disabled');
            $this->assertSame('export round trip fixture', $imported->getName());
            $this->assertSame('sales_order', $imported->getEntityType());
            $this->assertSame('event', $imported->getTriggerType());
            $this->assertSame('sales.order.created', $imported->getTriggerRef());
            $this->assertSame(2, $imported->getLoopGuardDepth());
            $this->assertEquals(
                $definition,
                json_decode($imported->getDefinition(), true),
                'Round-tripped definition must decoded-JSON equal the original'
            );
            $this->assertEquals(
                json_decode($conditions, true),
                json_decode((string) $imported->getConditionsSerialized(), true),
                'Round-tripped conditions must decoded-JSON equal the original'
            );
        } finally {
            @unlink($exportFile);
        }
    }

    /**
     * Every published spec/fixtures/*.json envelope imports cleanly through
     * the real pipeline (structural checks + the full WorkflowValidator pool).
     */
    public function testEveryPublishedSpecFixtureImportsSuccessfully(): void
    {
        $fixtureFiles = glob($this->specPath('fixtures') . '/*.json') ?: [];
        $this->assertNotEmpty($fixtureFiles, 'Expected at least one published spec/fixtures/*.json');

        $envelopesTested = 0;
        foreach ($fixtureFiles as $file) {
            $raw = json_decode((string) file_get_contents($file), true);
            $this->assertIsArray($raw, basename($file) . ': fixture must decode to a JSON object');

            // spec/fixtures also ships raw definition samples (schema-format
            // showcases, e.g. abandoned-cart-wait-recovery.json) that are NOT
            // `workflow:export` envelopes and are not importable by design —
            // the importer requires the envelope format tag (see
            // WorkflowImporter::assertEnvelope / testImportRejectsAnEnvelope-
            // WithTheWrongFormatTag). Only exercise real export envelopes here.
            if (($raw['format'] ?? null) !== \MageOS\Workflows\Model\Import\WorkflowImporter::FORMAT) {
                continue;
            }
            $envelopesTested++;

            $tester = new CommandTester(Bootstrap::getObjectManager()->get(ImportCommand::class));
            $exitCode = $tester->execute(['file' => $file]);
            $this->assertSame(0, $exitCode, basename($file) . ': ' . $tester->getDisplay());

            $workflowId = (int) $this->lastNonEmptyLine($tester->getDisplay());
            $this->assertGreaterThan(0, $workflowId, basename($file) . ': must print a workflow id');

            $imported = $this->workflowRepository->getById($workflowId);
            $this->assertSame($raw['name'], $imported->getName(), basename($file));
            $this->assertSame($raw['entity_type'], $imported->getEntityType(), basename($file));
            $this->assertEquals(
                $raw['definition'],
                json_decode($imported->getDefinition(), true),
                basename($file) . ': imported definition must decoded-JSON equal the fixture'
            );
        }

        $this->assertGreaterThan(0, $envelopesTested, 'Expected at least one published export-envelope fixture');
    }

    public function testImportRejectsAnEnvelopeWithTheWrongFormatTag(): void
    {
        $countBefore = $this->countWorkflows();
        $file = sys_get_temp_dir() . '/mageos_workflow_bad_envelope_' . uniqid('', true) . '.json';
        file_put_contents($file, json_encode([
            'format' => 'mageos-workflow-export/999',
            'name' => 'bad envelope',
            'entity_type' => 'sales_order',
            'trigger_type' => 'event',
            'trigger_ref' => 'sales.order.created',
            'definition' => ['schema' => 1, 'entry' => 's1', 'steps' => []],
        ]));

        try {
            $tester = new CommandTester(Bootstrap::getObjectManager()->get(ImportCommand::class));
            $exitCode = $tester->execute(['file' => $file]);

            $this->assertSame(1, $exitCode);
            $this->assertSame($countBefore, $this->countWorkflows(), 'A rejected import must not write a workflow row');
        } finally {
            @unlink($file);
        }
    }

    public function testExportOfAnUnknownWorkflowIdFails(): void
    {
        $tester = new CommandTester(Bootstrap::getObjectManager()->get(ExportCommand::class));
        $exitCode = $tester->execute(['workflow_id' => '999999999']);
        $this->assertSame(1, $exitCode);
    }

    public function testActivateAndShadowAreMutuallyExclusiveOnImport(): void
    {
        $fixtureFiles = glob($this->specPath('fixtures') . '/*.json') ?: [];
        $this->assertNotEmpty($fixtureFiles);
        $countBefore = $this->countWorkflows();

        $tester = new CommandTester(Bootstrap::getObjectManager()->get(ImportCommand::class));
        $exitCode = $tester->execute([
            'file' => $fixtureFiles[0],
            '--activate' => true,
            '--shadow' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('mutually exclusive', $tester->getDisplay());
        $this->assertSame($countBefore, $this->countWorkflows());
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function assertExportEnvelopeShape(array $envelope): void
    {
        // spec/workflow-export.schema.json: required + additionalProperties:false.
        $required = ['format', 'name', 'entity_type', 'trigger_type', 'trigger_ref', 'definition'];
        foreach ($required as $field) {
            $this->assertArrayHasKey($field, $envelope, "export envelope missing required field \"$field\"");
        }
        $allowed = array_merge($required, ['conditions_serialized', 'loop_guard_depth']);
        foreach (array_keys($envelope) as $key) {
            $this->assertContains($key, $allowed, "export envelope has an undeclared field \"$key\"");
        }
        $this->assertContains($envelope['trigger_type'], ['event', 'schedule', 'manual']);
    }

    private function lastNonEmptyLine(string $display): string
    {
        $lines = array_values(array_filter(explode("\n", trim($display)), static fn (string $l): bool => trim($l) !== ''));
        return trim((string) end($lines));
    }

    private function countWorkflows(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mageos_workflow');
        return (int) $connection->fetchOne($connection->select()->from($table, 'COUNT(*)'));
    }

    /**
     * Resolves the monorepo's published spec/ directory from either layout:
     * the local dev checkout (<repo>/spec) or a composer-installed Magento
     * where CI stages it to <magento>/vendor/spec (docs/20 §2.2). Both
     * layouts place this file 5 directories below the sibling that contains
     * spec/, so a single relative walk covers both; a couple of neighboring
     * depths are tried defensively since the exact vendor path depends on the
     * installed package layout.
     */
    private function specPath(string $relative): string
    {
        foreach ([5, 4, 6] as $depth) {
            $candidate = dirname(__DIR__, $depth) . '/spec';
            if (is_dir($candidate)) {
                return $candidate . '/' . ltrim($relative, '/');
            }
        }
        throw new \RuntimeException('Could not locate the spec/ directory from ' . __DIR__);
    }
}
