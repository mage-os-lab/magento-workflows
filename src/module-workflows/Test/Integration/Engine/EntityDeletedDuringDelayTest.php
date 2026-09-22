<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\Engine;

use Magento\Framework\Registry;
use Magento\Sales\Api\OrderRepositoryInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\Queue\ExecuteConsumer;
use MageOS\Workflows\Model\Queue\ResumeConsumer;
use MageOS\Workflows\Test\Integration\_files\WorkflowEngineTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Plan #14 (docs/20 §4): executable pin for the top open finding
 * (docs/08:99 / docs/19 findings registry — "Entity deleted during a delay is
 * NOT resumed as skipped"). An order deleted while its execution is parked in a
 * delay must, per docs/08, resume to execution status `skipped` with an
 * explicit log — never a per-action failure.
 *
 * EXPECTED TO FAIL TODAY: ResumeConsumer::process never re-checks the entity
 * (root conditions are first-run only), so the missing order surfaces as a
 * terminal action failure and the execution ends `failed`, not `skipped`.
 * Quarantined so that IMPLEMENTING the finding un-gates this test rather than
 * requiring a new one.
 *
 * Quarantined as a docs/08:99 / docs/19 findings-registry OPEN entry. The
 * docblock @group is retained for humans, but PHPUnit 12 no longer reads
 * metadata from docblocks, so the CI gate's `--exclude-group known-divergence`
 * only sees the #[Group] attribute below — it is what actually excludes this
 * executable pin from the blocking lane.
 *
 * @group known-divergence
 * @magentoDbIsolation enabled
 * @magentoDataFixture Magento/Sales/_files/order.php
 */
#[Group('known-divergence')]
class EntityDeletedDuringDelayTest extends TestCase
{
    use WorkflowEngineTestTrait;

    public function testEntityDeletedDuringDelayResumesAsSkipped(): void
    {
        $order = $this->seededOrder();
        $orderId = (int) $order->getId();

        $workflow = $this->createWorkflow([
            'name' => 'delete during delay',
            'status' => WorkflowInterface::STATUS_ENABLED,
            'definition' => [
                'schema' => 1,
                'entry' => 'd1',
                'steps' => [
                    'd1' => ['type' => 'delay', 'config' => ['duration' => 'PT1H'], 'next' => 's2'],
                    's2' => ['type' => 'action', 'action' => 'order.add_comment', 'config' => ['comment' => 'post-delay'], 'next' => null],
                ],
            ],
        ]);
        $execution = $this->seedExecution(
            (int) $workflow->getWorkflowId(),
            $workflow->getDefinition(),
            $orderId,
            (int) $order->getStoreId(),
            ['entity_id' => $orderId]
        );
        $id = (int) $execution->getExecutionId();

        // Park the delay.
        $this->om()->get(ExecuteConsumer::class)->process((string) $id);
        $this->assertSame(WorkflowExecutionInterface::STATUS_WAITING, $this->reloadExecution($id)->getStatus());

        // Delete the order out from under the parked execution.
        $registry = $this->om()->get(Registry::class);
        $registry->register('isSecureArea', true, true);
        $orderRepository = $this->om()->get(OrderRepositoryInterface::class);
        $orderRepository->delete($orderRepository->get($orderId));

        // Resume: docs/08 promises a skipped execution.
        $this->rewindTimestamp('mageos_workflow_execution_step', 'resume_at', $this->gmPast(120), 'execution_id', $id);
        try {
            $this->om()->get(ResumeConsumer::class)->process((string) $id);
        } catch (\Throwable $e) {
            // A retryable rethrow is itself the divergence (never the promised skip).
        }

        $this->assertSame(
            WorkflowExecutionInterface::STATUS_SKIPPED,
            $this->reloadExecution($id)->getStatus(),
            'docs/08:99 — a vanished entity during a delay must resume as skipped'
        );
    }
}
