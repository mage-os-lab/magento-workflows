<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminExtension\Test\Integration\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\WorkflowFactory;
use MageOS\WorkflowsAdminExtension\Model\WorkflowCountProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plan #33 (docs/20-integration-test-plan.md §7). DIVERGENCE from the plan's
 * literal "Plugin\GridVisibilityTest ... against real grid collections":
 * module-workflows-admin-extension does not modify any native grid
 * collection. It renders a small strip ABOVE existing entity grids via a
 * ViewModel (GridStrip) backed by this count provider, invalidated by a
 * repository plugin (InvalidateCountCache) — there is no grid-visibility
 * plugin to test. This suite pins the real seam that exists: counts computed
 * from the real WorkflowRepositoryInterface::getList and cached via the real
 * CacheInterface.
 *
 * @magentoDbIsolation enabled
 */
class WorkflowCountProviderTest extends TestCase
{
    private WorkflowCountProvider $countProvider;
    private WorkflowRepositoryInterface $workflowRepository;
    private WorkflowFactory $workflowFactory;
    private CacheInterface $cache;
    private ResourceConnection $resourceConnection;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->countProvider = $objectManager->get(WorkflowCountProvider::class);
        $this->workflowRepository = $objectManager->get(WorkflowRepositoryInterface::class);
        $this->workflowFactory = $objectManager->get(WorkflowFactory::class);
        $this->cache = $objectManager->get(CacheInterface::class);
        $this->resourceConnection = $objectManager->get(ResourceConnection::class);

        // Every test in this suite reads counts scoped to entity types it
        // seeds itself, but the cache is process-wide keyed by entity type —
        // clear this tag first so a prior test's cached value never leaks in.
        $this->cache->clean([WorkflowCountProvider::CACHE_TAG]);
    }

    public function testCountsAreZeroForAnEntityTypeWithNoWorkflows(): void
    {
        $counts = $this->countProvider->getCounts('quote');
        $this->assertSame(['enabled' => 0, 'total' => 0], $counts);
    }

    public function testCountsDistinguishEnabledFromTotalPerEntityType(): void
    {
        $this->saveWorkflow('count fixture enabled', 'sales_order', WorkflowInterface::STATUS_ENABLED);
        $this->saveWorkflow('count fixture disabled', 'sales_order', WorkflowInterface::STATUS_DISABLED);
        $this->saveWorkflow('count fixture shadow', 'sales_order', WorkflowInterface::STATUS_SHADOW);
        $this->saveWorkflow('count fixture other type', 'catalog_product', WorkflowInterface::STATUS_ENABLED);

        $this->assertSame(['enabled' => 1, 'total' => 3], $this->countProvider->getCounts('sales_order'));
        $this->assertSame(['enabled' => 1, 'total' => 1], $this->countProvider->getCounts('catalog_product'));
    }

    public function testResultIsCachedUntilTheTagIsInvalidated(): void
    {
        $this->saveWorkflow('count cache fixture', 'review', WorkflowInterface::STATUS_ENABLED);
        $first = $this->countProvider->getCounts('review');
        $this->assertSame(['enabled' => 1, 'total' => 1], $first);

        // Delete the row directly via SQL, bypassing the repository (and
        // therefore bypassing InvalidateCountCache) so the cache entry is
        // NOT invalidated by this write.
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mageos_workflow');
        $connection->delete($table, ['entity_type = ?' => 'review']);

        $stillCached = $this->countProvider->getCounts('review');
        $this->assertSame($first, $stillCached, 'Uncached invalidation path must not change the served counts');

        $this->cache->clean([WorkflowCountProvider::CACHE_TAG]);
        $fresh = $this->countProvider->getCounts('review');
        $this->assertSame(['enabled' => 0, 'total' => 0], $fresh, 'After explicit cache invalidation, counts must reflect the real DB state');
    }

    private function saveWorkflow(string $name, string $entityType, int $status): void
    {
        $workflow = $this->workflowFactory->create();
        $workflow->setName($name);
        $workflow->setStatus($status);
        $workflow->setTriggerType(WorkflowInterface::TRIGGER_TYPE_EVENT);
        $workflow->setTriggerRef('sales.order.created');
        $workflow->setEntityType($entityType);
        $workflow->setDefinition(json_encode([
            'schema' => 1,
            'entry' => 's1',
            'steps' => ['s1' => ['type' => 'action', 'action' => 'order.add_comment', 'config' => ['comment' => 'x'], 'next' => null]],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->workflowRepository->save($workflow);
    }
}
