<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\Engine;

use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\DispatcherInterface;
use MageOS\Workflows\Model\Queue\ExecuteConsumer;
use MageOS\Workflows\Test\Integration\_files\WorkflowEngineTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Plan #5 (docs/20 §4): Dispatcher guard gates against the real DB and the
 * real merged store/website config (docs/08). Disabled/suspended workflows
 * never dispatch; the loop-guard chain-depth cap holds; a shadow-status
 * workflow dispatches and walks but performs NO live side effect (the
 * order.add_comment step writes no status-history row); the website-scope gate
 * refuses an entity on a website the workflow is not scoped to.
 *
 * @magentoDbIsolation enabled
 */
class DispatcherGuardsTest extends TestCase
{
    use WorkflowEngineTestTrait;

    private DispatcherInterface $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = $this->om()->get(DispatcherInterface::class);
    }

    public function testDisabledWorkflowNeverDispatches(): void
    {
        $workflow = $this->createWorkflow([
            'name' => 'disabled guard',
            'definition' => $this->linear(),
            'status' => WorkflowInterface::STATUS_DISABLED,
        ]);

        $result = $this->dispatcher->dispatch((int) $workflow->getWorkflowId(), ['entity_id' => 401, 'store_id' => 1]);
        $this->assertNull($result);
        $this->assertSame(0, $this->executionCount((int) $workflow->getWorkflowId()));
    }

    public function testSuspendedWorkflowNeverDispatches(): void
    {
        // Suspended is status 3 (circuit breaker); stage it directly so the
        // status gate is read at dispatch time from the workflow row.
        $workflowId = $this->insertWorkflowRow([
            'name' => 'suspended guard',
            'status' => WorkflowInterface::STATUS_SUSPENDED,
            'definition' => $this->linear(),
        ]);

        $this->assertNull($this->dispatcher->dispatch($workflowId, ['entity_id' => 402, 'store_id' => 1]));
        $this->assertSame(0, $this->executionCount($workflowId));
    }

    public function testLoopGuardCapsChainDepth(): void
    {
        $workflow = $this->createWorkflow([
            'name' => 'loop guard',
            'definition' => $this->linear(),
            'status' => WorkflowInterface::STATUS_ENABLED,
            'loopGuardDepth' => 1,
        ]);
        $id = (int) $workflow->getWorkflowId();

        // chainDepth 2 > loopGuardDepth 1 => suppressed
        $this->assertNull($this->dispatcher->dispatch($id, ['entity_id' => 403, 'store_id' => 1], 'event', 2));
        // chainDepth 1 is within the cap => dispatched
        $passed = $this->dispatcher->dispatch($id, ['entity_id' => 404, 'store_id' => 1], 'event', 1);
        $this->assertNotNull($passed);
        $this->assertSame(1, $passed->getChainDepth());
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testShadowDispatchesButProducesNoLiveSideEffect(): void
    {
        $order = $this->seededOrder();
        $before = $this->markerCommentCount($order);

        $workflow = $this->createWorkflow([
            'name' => 'shadow no side effect',
            'definition' => $this->linear(),
            'status' => WorkflowInterface::STATUS_SHADOW,
        ]);

        $execution = $this->dispatcher->dispatch((int) $workflow->getWorkflowId(), [
            'entity_id' => (int) $order->getId(),
            'store_id' => (int) $order->getStoreId(),
        ], 'event');
        $this->assertNotNull($execution, 'Shadow-status workflows still dispatch (they simulate the walk)');

        $this->om()->get(ExecuteConsumer::class)->process((string) $execution->getExecutionId());

        $reloaded = $this->reloadExecution((int) $execution->getExecutionId());
        $this->assertSame('complete', $reloaded->getStatus(), 'The shadow execution walks to completion');

        // The action step ran, but simulated: no status-history comment written.
        $freshOrder = $this->om()->create(Order::class)->loadByIncrementId('100000001');
        $this->assertSame(
            $before,
            $this->markerCommentCount($freshOrder),
            'A shadow order.add_comment must not write a real status-history row'
        );
    }

    /**
     * @magentoDataFixture Magento/Store/_files/second_website_with_two_stores.php
     */
    public function testWebsiteScopeGateRefusesOffScopeEntities(): void
    {
        $storeManager = $this->om()->get(StoreManagerInterface::class);
        $inScopeStore = null;
        $offScopeStore = null;
        foreach ($storeManager->getStores() as $store) {
            if ((int) $store->getWebsiteId() === 1 && $inScopeStore === null) {
                $inScopeStore = (int) $store->getId();
            } elseif ((int) $store->getWebsiteId() !== 1 && $offScopeStore === null) {
                $offScopeStore = (int) $store->getId();
            }
        }
        if ($inScopeStore === null || $offScopeStore === null) {
            $this->markTestSkipped('Need a store in website 1 and a store in another website');
        }

        $workflow = $this->createWorkflow([
            'name' => 'website scoped',
            'definition' => $this->linear(),
            'status' => WorkflowInterface::STATUS_ENABLED,
            'websiteIds' => [1],
        ]);
        $id = (int) $workflow->getWorkflowId();

        // Off-scope store (a different website): refused before any execution/debounce.
        $this->assertNull(
            $this->dispatcher->dispatch($id, ['entity_id' => 601, 'store_id' => $offScopeStore]),
            'A workflow scoped to website 1 must not fire for an entity on another website'
        );
        // In-scope store: dispatched.
        $this->assertNotNull(
            $this->dispatcher->dispatch($id, ['entity_id' => 602, 'store_id' => $inScopeStore])
        );
    }

    private function executionCount(int $workflowId): int
    {
        $connection = $this->db();
        return (int) $connection->fetchOne(
            $connection->select()
                ->from($this->table('mageos_workflow_execution'), 'COUNT(*)')
                ->where('workflow_id = ?', $workflowId)
        );
    }

    private function markerCommentCount(Order $order): int
    {
        $count = 0;
        foreach ($order->getStatusHistories() ?: [] as $history) {
            $comment = $history->getComment();
            if (is_string($comment) && str_contains($comment, '<!-- mageos-workflows:')) {
                $count++;
            }
        }
        return $count;
    }

    private function linear(): array
    {
        return [
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => 'action',
                    'action' => 'order.add_comment',
                    'config' => ['comment' => 'guard probe'],
                    'next' => null,
                ],
            ],
        ];
    }
}
