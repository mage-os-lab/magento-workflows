<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsCanvas\Test\Integration\Controller\Adminhtml;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\TestFramework\TestCase\AbstractBackendController;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\Definition\Definition;
use MageOS\WorkflowsCanvas\Block\Adminhtml\Canvas\Mount;

/**
 * Plan #27 (docs/20-integration-test-plan.md §6): the canvas "Data" surface —
 * the bootstrap payload the React editor mounts from. The read-only viewer page
 * (mageos_workflows_canvas/canvas/view, gated ::view) renders the mount block,
 * which serializes the definition + metadata (actions, triggers, secrets,
 * grants, endpoints, approvalsAvailable, the loaded workflow) into a single
 * data-config attribute. This asserts the payload SHAPE (keys, not exact
 * strings) and that the loaded workflow's definition round-trips decoded-equal.
 * ACL has-access / no-access come from AbstractBackendController via
 * $uri/$resource.
 *
 * @magentoAppArea adminhtml
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class DataControllerTest extends AbstractBackendController
{
    /**
     * @var string
     */
    protected $uri = 'backend/mageos_workflows_canvas/canvas/view';

    /**
     * @var string
     */
    protected $resource = 'MageOS_Workflows::view';

    public function testViewerPageRendersTheCanvasMountRoot(): void
    {
        $this->dispatch($this->uri);

        $this->assertSame(200, $this->getResponse()->getHttpResponseCode());
        $this->assertStringContainsString(
            'mageos-workflows-canvas-root',
            (string) $this->getResponse()->getBody(),
            'The viewer page must mount the canvas root (layout handle wired)'
        );
    }

    public function testMountPayloadExposesEditorContract(): void
    {
        $config = $this->buildMountConfig(0);

        // Metadata every mount needs, regardless of whether a workflow loaded.
        foreach (['knownSchemaVersion', 'grants', 'endpoints', 'formKey', 'actions', 'actionsMeta', 'triggers', 'secrets', 'approvalsAvailable'] as $key) {
            $this->assertArrayHasKey($key, $config, "The mount payload must expose '$key'");
        }
        $this->assertSame(Definition::SCHEMA_VERSION, $config['knownSchemaVersion']);
        $this->assertArrayHasKey('manage', $config['grants']);
        $this->assertArrayHasKey('dryRun', $config['grants']);
        foreach (['executionSteps', 'dryRun', 'validate', 'options', 'save'] as $endpoint) {
            $this->assertArrayHasKey($endpoint, $config['endpoints'], "The mount payload must expose the '$endpoint' endpoint");
        }
        // The canvas has no save path of its own — it posts through the existing
        // admin Save controller.
        $this->assertStringContainsString('mageos_workflows/workflow/save', (string) $config['endpoints']['save']);
        $this->assertIsBool($config['approvalsAvailable']);
        // With no workflow_id, a manage-granted admin gets the blank-workflow
        // bootstrap (canvas-first creation): id 0, empty definition, and the
        // workflowOptions catalogue the settings panel renders from.
        $this->assertIsArray($config['workflow']);
        $this->assertSame(0, $config['workflow']['id']);
        $this->assertSame(Definition::SCHEMA_VERSION, $config['workflow']['definition']['schema']);
        $this->assertSame([], $config['workflow']['definition']['steps']);
        $this->assertNull($config['workflow']['definition']['entry']);
        $this->assertArrayHasKey('workflowOptions', $config);
        foreach (['entityTypes', 'triggerTypes', 'statuses', 'websites'] as $optionList) {
            $this->assertArrayHasKey($optionList, $config['workflowOptions']);
        }
        $this->assertNotEmpty($config['workflowOptions']['statuses']);
    }

    /**
     * @magentoDataFixture MageOS_WorkflowsCanvas::Test/Integration/_files/workflow_canvas.php
     */
    public function testMountPayloadLoadsWorkflowDefinitionDecodedEqual(): void
    {
        $workflow = $this->fixtureWorkflow();
        $config = $this->buildMountConfig((int) $workflow->getWorkflowId());

        $this->assertIsArray($config['workflow'], 'A workflow_id loads the workflow into the mount payload');
        $this->assertSame((int) $workflow->getWorkflowId(), $config['workflow']['id']);
        $this->assertSame('Canvas mount fixture', $config['workflow']['name']);
        $this->assertSame('sales_order', $config['workflow']['entityType']);

        // The definition round-trips decoded-equal (MySQL json / normalization:
        // decoded equality, not byte equality).
        $stored = json_decode((string) $workflow->getDefinition(), true);
        $this->assertSame($stored, $config['workflow']['definition']);
    }

    /**
     * Build and decode the mount block's config JSON for a given workflow_id.
     *
     * @return array<string, mixed>
     */
    private function buildMountConfig(int $workflowId): array
    {
        $this->getRequest()->setParams(['workflow_id' => (string) $workflowId]);
        /** @var Mount $block */
        $block = $this->_objectManager->create(Mount::class);
        $decoded = json_decode($block->getConfigJson(), true);
        $this->assertIsArray($decoded, 'The mount config must be valid JSON');
        return $decoded;
    }

    private function fixtureWorkflow(): WorkflowInterface
    {
        $searchCriteria = $this->_objectManager->create(SearchCriteriaBuilder::class)
            ->addFilter('name', 'Canvas mount fixture')
            ->create();
        $items = $this->_objectManager->get(WorkflowRepositoryInterface::class)
            ->getList($searchCriteria)
            ->getItems();
        $this->assertNotEmpty($items, 'The canvas fixture workflow must be present');
        return array_values($items)[0];
    }
}
