<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminExtension\Test\Integration\Plugin;

use Magento\Framework\App\CacheInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\WorkflowFactory;
use MageOS\WorkflowsAdminExtension\Model\WorkflowCountProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plan #33 (docs/20-integration-test-plan.md §7): InvalidateCountCache is an
 * after-plugin registered on WorkflowRepositoryInterface (etc/di.xml) —
 * proves the REAL merged DI wires it, by observing WorkflowCountProvider's
 * cached counts change immediately after a real repository save/delete/
 * deleteById, with no explicit cache-clean call from the test.
 *
 * @magentoDbIsolation enabled
 */
class InvalidateCountCacheTest extends TestCase
{
    private WorkflowRepositoryInterface $workflowRepository;
    private WorkflowFactory $workflowFactory;
    private WorkflowCountProvider $countProvider;
    private CacheInterface $cache;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->workflowRepository = $objectManager->get(WorkflowRepositoryInterface::class);
        $this->workflowFactory = $objectManager->get(WorkflowFactory::class);
        $this->countProvider = $objectManager->get(WorkflowCountProvider::class);
        $this->cache = $objectManager->get(CacheInterface::class);

        $this->cache->clean([WorkflowCountProvider::CACHE_TAG]);
    }

    public function testSaveInvalidatesTheCachedCount(): void
    {
        $this->assertSame(['enabled' => 0, 'total' => 0], $this->countProvider->getCounts('shipment'));

        $workflow = $this->newWorkflow('invalidate on save fixture', 'shipment');
        $this->workflowRepository->save($workflow);

        $this->assertSame(
            ['enabled' => 1, 'total' => 1],
            $this->countProvider->getCounts('shipment'),
            'save() must invalidate the cached count, not serve the pre-save 0/0 snapshot'
        );
    }

    public function testDeleteInvalidatesTheCachedCount(): void
    {
        $workflow = $this->newWorkflow('invalidate on delete fixture', 'creditmemo');
        $saved = $this->workflowRepository->save($workflow);
        $this->assertSame(['enabled' => 1, 'total' => 1], $this->countProvider->getCounts('creditmemo'));

        $this->workflowRepository->delete($saved);

        $this->assertSame(
            ['enabled' => 0, 'total' => 0],
            $this->countProvider->getCounts('creditmemo'),
            'delete() must invalidate the cached count'
        );
    }

    public function testDeleteByIdInvalidatesTheCachedCount(): void
    {
        $workflow = $this->newWorkflow('invalidate on deleteById fixture', 'invoice');
        $saved = $this->workflowRepository->save($workflow);
        $workflowId = (int) $saved->getWorkflowId();
        $this->assertSame(['enabled' => 1, 'total' => 1], $this->countProvider->getCounts('invoice'));

        $this->workflowRepository->deleteById($workflowId);

        $this->assertSame(
            ['enabled' => 0, 'total' => 0],
            $this->countProvider->getCounts('invoice'),
            'deleteById() must invalidate the cached count'
        );
    }

    /**
     * A save that changes entity_type must invalidate BOTH the old and new
     * type's cached counts (the plugin cleans by tag, not by key, exactly to
     * cover this case per InvalidateCountCache's class docblock).
     */
    public function testSaveThatChangesEntityTypeInvalidatesBothOldAndNewTypeCounts(): void
    {
        $workflow = $this->newWorkflow('invalidate on entity type change fixture', 'customer');
        $saved = $this->workflowRepository->save($workflow);
        $this->assertSame(['enabled' => 1, 'total' => 1], $this->countProvider->getCounts('customer'));
        $this->assertSame(['enabled' => 0, 'total' => 0], $this->countProvider->getCounts('quote'));

        $saved->setEntityType('quote');
        $saved->setTriggerRef('quote.abandoned');
        $this->workflowRepository->save($saved);

        $this->assertSame(
            ['enabled' => 0, 'total' => 0],
            $this->countProvider->getCounts('customer'),
            'The old entity type must no longer show a stale count'
        );
        $this->assertSame(
            ['enabled' => 1, 'total' => 1],
            $this->countProvider->getCounts('quote'),
            'The new entity type must reflect the moved workflow'
        );
    }

    private function newWorkflow(string $name, string $entityType): WorkflowInterface
    {
        $workflow = $this->workflowFactory->create();
        $workflow->setName($name);
        $workflow->setStatus(WorkflowInterface::STATUS_ENABLED);
        $workflow->setTriggerType(WorkflowInterface::TRIGGER_TYPE_EVENT);
        $workflow->setTriggerRef('sales.order.created');
        $workflow->setEntityType($entityType);
        $workflow->setDefinition(json_encode([
            'schema' => 1,
            'entry' => 's1',
            'steps' => ['s1' => ['type' => 'action', 'action' => 'order.add_comment', 'config' => ['comment' => 'x'], 'next' => null]],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $workflow;
    }
}
