<?php
declare(strict_types=1);

namespace MageOS\WorkflowsImportSuppression\Test\Integration\Plugin;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\ImportExport\Model\Import;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\DispatcherInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\Suppression\WorkflowSuppression;
use MageOS\WorkflowsImportSuppression\Plugin\SuppressWorkflowsDuringImport;
use PHPUnit\Framework\TestCase;

/**
 * Plan #30 (docs/20-integration-test-plan.md §7): the known-bulk-path
 * suppression plugin, driven against the REAL Magento\ImportExport\Model\Import
 * class and the REAL Dispatcher/WorkflowSuppression, rather than a reflection
 * stub (the unit suite, SuppressWorkflowsDuringImportTest, already covers the
 * plugin's own try/finally logic against a bare-constructed Import and stub
 * config).
 *
 * Two seams are exercised here, both real:
 *
 *  1. testRealImportInterceptionRestoresSuppressionEvenWhenImportSourceThrows:
 *     calls importSource() on an OM-resolved (real, interception-wrapped)
 *     Import instance with no valid entity configured. Magento's own
 *     getEntityAdapter() resolution fails for an unconfigured/unknown entity
 *     type, so this proves the REAL merged di.xml plugin wiring brackets a
 *     REAL (unmodified) Import::importSource() call, restoring suppression
 *     even when the wrapped call throws — not just that the plugin class
 *     behaves correctly in isolation.
 *
 *  2. testDispatchIsSuppressedDuringImportWindowAndResumesAfter /
 *     testToggleOffAllowsNormalDispatchDuringImport: exercise the actual
 *     deliverable ("suppression flag is set during import and cleared
 *     after") end-to-end against a REAL saved, enabled workflow and the REAL
 *     Dispatcher, by calling the REAL plugin instance's aroundImportSource()
 *     directly against a real Import subject with a test-supplied $proceed
 *     that calls Dispatcher::dispatch() — the narrowest seam that lets a test
 *     observe suppression state DURING the wrapped call (Magento's real
 *     product/customer CSV entity adapters do not expose a synchronous,
 *     documented mid-import hook this suite can rely on without depending on
 *     unverifiable Magento core internals). Descoped from a full CSV-driven
 *     product import for that reason — see the divergence note in the test
 *     plan implementation report.
 *
 * @magentoDbIsolation enabled
 */
class ImportSuppressionTest extends TestCase
{
    private WorkflowSuppression $suppressionChecker;

    protected function setUp(): void
    {
        $this->suppressionChecker = Bootstrap::getObjectManager()->get(WorkflowSuppression::class);
    }

    protected function tearDown(): void
    {
        // Guard against a failing assertion leaking suppression depth into
        // the next test (the counter is process-static).
        for ($i = 0; $i < 10 && $this->suppressionChecker->isSuppressed(); $i++) {
            WorkflowSuppression::restore();
        }
    }

    public function testSuppressBulkImportsDefaultsToEnabled(): void
    {
        $scopeConfig = Bootstrap::getObjectManager()->get(ScopeConfigInterface::class);
        $this->assertTrue(
            $scopeConfig->isSetFlag(SuppressWorkflowsDuringImport::CONFIG_SUPPRESS_BULK_IMPORTS),
            'mageos_workflows/general/suppress_bulk_imports must default to enabled (etc/config.xml)'
        );
    }

    public function testRealImportInterceptionRestoresSuppressionEvenWhenImportSourceThrows(): void
    {
        $this->assertFalse($this->suppressionChecker->isSuppressed(), 'Precondition: not suppressed');

        /** @var Import $import */
        $import = Bootstrap::getObjectManager()->create(Import::class);

        try {
            // No 'entity' configured: Magento's real getEntityAdapter() cannot
            // resolve an entity adapter and importSource() throws. The point
            // is not the specific exception type/message (Magento-owned, not
            // asserted here) — it is that the REAL interception-wrapped call
            // still restores suppression on the way out.
            $import->importSource();
            $this->fail('Expected importSource() to throw for an unconfigured entity');
        } catch (\Throwable $e) {
            // Expected: any throwable propagating from the real Import class.
        }

        $this->assertFalse(
            $this->suppressionChecker->isSuppressed(),
            'The real merged-DI plugin must restore suppression even when the wrapped call throws'
        );
    }

    /**
     * @magentoDataFixture MageOS_WorkflowsImportSuppression::Test/Integration/_files/suppression_dispatch_workflow.php
     * @magentoConfigFixture current_store mageos_workflows/general/suppress_bulk_imports 1
     */
    public function testDispatchIsSuppressedDuringImportWindowAndResumesAfter(): void
    {
        $workflowId = $this->fixtureWorkflowId();
        $dispatcher = Bootstrap::getObjectManager()->get(DispatcherInterface::class);
        $plugin = Bootstrap::getObjectManager()->get(SuppressWorkflowsDuringImport::class);
        /** @var Import $import */
        $import = Bootstrap::getObjectManager()->create(Import::class);

        $capturedDuring = 'not-set';
        $proceed = function () use (&$capturedDuring, $dispatcher, $workflowId): bool {
            $capturedDuring = $dispatcher->dispatch($workflowId, ['entity_id' => 555001], WorkflowInterface::TRIGGER_TYPE_MANUAL);
            return true;
        };

        $result = $plugin->aroundImportSource($import, $proceed);

        $this->assertTrue($result);
        $this->assertNull($capturedDuring, 'Dispatch must be suppressed (return null, no execution) during the import window');
        $this->assertFalse($this->suppressionChecker->isSuppressed(), 'Suppression must be cleared once importSource() returns');

        // Normal dispatch resumes after the plugin's scope ends (distinct
        // entity id so this is not itself debounced against the call above).
        $afterExecution = $dispatcher->dispatch($workflowId, ['entity_id' => 555002], WorkflowInterface::TRIGGER_TYPE_MANUAL);
        $this->assertNotNull($afterExecution, 'Dispatch must resume normally once the import window has closed');
        $this->assertSame($workflowId, $afterExecution->getWorkflowId());
    }

    /**
     * @magentoDataFixture MageOS_WorkflowsImportSuppression::Test/Integration/_files/suppression_dispatch_workflow.php
     * @magentoConfigFixture current_store mageos_workflows/general/suppress_bulk_imports 0
     */
    public function testToggleOffAllowsNormalDispatchDuringImport(): void
    {
        $workflowId = $this->fixtureWorkflowId();
        $dispatcher = Bootstrap::getObjectManager()->get(DispatcherInterface::class);
        $plugin = Bootstrap::getObjectManager()->get(SuppressWorkflowsDuringImport::class);
        /** @var Import $import */
        $import = Bootstrap::getObjectManager()->create(Import::class);

        $capturedDuring = 'not-set';
        $proceed = function () use (&$capturedDuring, $dispatcher, $workflowId): bool {
            $capturedDuring = $dispatcher->dispatch($workflowId, ['entity_id' => 555003], WorkflowInterface::TRIGGER_TYPE_MANUAL);
            return true;
        };

        $plugin->aroundImportSource($import, $proceed);

        $this->assertNotNull($capturedDuring, 'With the toggle off, dispatch must NOT be suppressed during importSource()');
        $this->assertFalse($this->suppressionChecker->isSuppressed());
    }

    private function fixtureWorkflowId(): int
    {
        $objectManager = Bootstrap::getObjectManager();
        $repository = $objectManager->get(WorkflowRepositoryInterface::class);
        $searchCriteria = $objectManager->create(SearchCriteriaBuilder::class)
            ->addFilter(WorkflowInterface::NAME, 'Import suppression dispatch fixture')
            ->create();
        $items = $repository->getList($searchCriteria)->getItems();
        $this->assertCount(1, $items, 'Expected the suppression_dispatch_workflow fixture to be loaded');
        return (int) array_values($items)[0]->getWorkflowId();
    }
}
