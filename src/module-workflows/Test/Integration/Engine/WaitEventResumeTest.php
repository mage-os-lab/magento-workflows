<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\Engine;

use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\DispatcherInterface;
use MageOS\Workflows\Model\Engine\Dispatcher;
use MageOS\Workflows\Model\Queue\ExecuteConsumer;
use MageOS\Workflows\Model\Queue\ResumeConsumer;
use MageOS\Workflows\Test\Integration\_files\ThrowingPublisher;
use MageOS\Workflows\Test\Integration\_files\WorkflowEngineTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Plan #9 (docs/20 §4): Dispatcher::resumeWaiting (docs/08). A matching
 * (workflow, event, entity) claims waiting -> pending exactly once and writes
 * the event payload into the wait step's result BEFORE the resume publish;
 * poisoning the publisher proves that ordering — the payload is present while
 * the status is rolled back to waiting. Non-matching entity/event resumes
 * nothing. The ResumeConsumer routes on_event and exposes the payload as the
 * wait step's output.
 *
 * @magentoDbIsolation enabled
 * @magentoAppIsolation enabled
 */
class WaitEventResumeTest extends TestCase
{
    use WorkflowEngineTestTrait;

    private const EVENT = 'sales.order.created';
    private const ENTITY_ID = 55;

    /**
     * @return array{0: int, 1: int} [workflowId, executionId] parked on the wait event
     */
    private function parkWaitExecution(): array
    {
        $definition = [
            'schema' => 2,
            'entry' => 'w1',
            'steps' => [
                'w1' => [
                    'type' => 'wait',
                    'config' => ['event' => self::EVENT, 'timeout' => 'PT4H'],
                    'on_event' => 'e1',
                    'on_timeout' => 't1',
                ],
                'e1' => ['type' => 'action', 'action' => 'order.add_comment', 'config' => ['comment' => 'event'], 'next' => null],
                't1' => ['type' => 'action', 'action' => 'order.add_comment', 'config' => ['comment' => 'timeout'], 'next' => null],
            ],
        ];
        $workflow = $this->createWorkflow([
            'name' => 'wait resume ' . uniqid('', true),
            'definition' => $definition,
            'status' => WorkflowInterface::STATUS_SHADOW,
        ]);
        $execution = $this->seedExecution((int) $workflow->getWorkflowId(), $workflow->getDefinition(), self::ENTITY_ID, 1);
        $this->om()->get(ExecuteConsumer::class)->process((string) $execution->getExecutionId());

        return [(int) $workflow->getWorkflowId(), (int) $execution->getExecutionId()];
    }

    public function testResumeWaitingClaimsOnceAndWritesPayloadBeforePublish(): void
    {
        [$workflowId, $executionId] = $this->parkWaitExecution();

        $this->assertSame(WorkflowExecutionInterface::STATUS_WAITING, $this->reloadExecution($executionId)->getStatus());

        $payload = ['entity_id' => self::ENTITY_ID, 'increment_id' => '100000001', 'note' => 'fired'];
        $dispatcher = $this->om()->get(DispatcherInterface::class);
        $resumed = $dispatcher->resumeWaiting($workflowId, self::EVENT, $payload);
        $this->assertSame(1, $resumed, 'Exactly one waiting execution claimed');

        // A second call claims nothing (already pending, not waiting).
        $this->assertSame(0, $dispatcher->resumeWaiting($workflowId, self::EVENT, $payload));

        $this->assertSame(WorkflowExecutionInterface::STATUS_PENDING, $this->reloadExecution($executionId)->getStatus());

        $result = json_decode((string) $this->stepResult($executionId, 'w1'), true);
        $this->assertSame('event', $result['resolution'] ?? null);
        // The step `result` is a MySQL json column, which normalizes object key
        // order (by key length, then value), so compare decoded semantically.
        $this->assertEquals($payload, $result['event'] ?? null, 'The event payload is written into the wait step result');
    }

    public function testPublishFailureRollsStatusBackButLeavesPayloadWritten(): void
    {
        [$workflowId, $executionId] = $this->parkWaitExecution();

        $throwing = new ThrowingPublisher();
        /** @var Dispatcher $poisoned */
        $poisoned = $this->om()->create(Dispatcher::class, ['publisher' => $throwing]);

        $payload = ['entity_id' => self::ENTITY_ID, 'note' => 'poisoned'];
        $resumed = $poisoned->resumeWaiting($workflowId, self::EVENT, $payload);

        $this->assertSame(0, $resumed, 'A failed publish is not counted as a resume');
        $this->assertGreaterThanOrEqual(1, $throwing->attempts);
        // Ordering proof: payload was written BEFORE the publish attempt...
        $result = json_decode((string) $this->stepResult($executionId, 'w1'), true);
        // MySQL json column: normalized key order, so compare decoded semantically.
        $this->assertEquals($payload, $result['event'] ?? null, 'Payload persisted before the publish attempt');
        // ...and the claim rolled back so the timeout sweeper still owns it.
        $this->assertSame(
            WorkflowExecutionInterface::STATUS_WAITING,
            $this->reloadExecution($executionId)->getStatus()
        );
    }

    public function testNonMatchingEntityOrEventResumesNothing(): void
    {
        [$workflowId, $executionId] = $this->parkWaitExecution();
        $dispatcher = $this->om()->get(DispatcherInterface::class);

        $this->assertSame(0, $dispatcher->resumeWaiting($workflowId, self::EVENT, ['entity_id' => 999999]));
        $this->assertSame(0, $dispatcher->resumeWaiting($workflowId, 'some.other.event', ['entity_id' => self::ENTITY_ID]));
        $this->assertSame(
            WorkflowExecutionInterface::STATUS_WAITING,
            $this->reloadExecution($executionId)->getStatus(),
            'No non-matching resume disturbed the parked execution'
        );
    }

    public function testResumeConsumerRoutesOnEventAndExposesPayloadAsStepOutput(): void
    {
        [$workflowId, $executionId] = $this->parkWaitExecution();

        $payload = ['entity_id' => self::ENTITY_ID, 'increment_id' => '100000001'];
        $this->om()->get(DispatcherInterface::class)->resumeWaiting($workflowId, self::EVENT, $payload);

        $this->om()->get(ResumeConsumer::class)->process((string) $executionId);

        $execution = $this->reloadExecution($executionId);
        $this->assertSame(WorkflowExecutionInterface::STATUS_COMPLETE, $execution->getStatus());
        // on_event routed to e1, which ran (simulated) and completed.
        $this->assertSame('complete', $this->stepStatuses($executionId)['e1'] ?? null);

        $context = json_decode((string) $execution->getContext(), true);
        $this->assertSame('event', $context['steps']['w1']['resolution'] ?? null);
        $this->assertSame($payload, $context['steps']['w1']['event'] ?? null);
    }

    /**
     * The wait-resume batch cap constant is stable (bounds one delivery's fan-out).
     */
    public function testWaitResumeConstantsAreStable(): void
    {
        $this->assertSame('mageos.workflow.resume', Dispatcher::TOPIC_RESUME);
    }

    private function stepResult(int $executionId, string $stepKey): ?string
    {
        $connection = $this->db();
        $value = $connection->fetchOne(
            $connection->select()
                ->from($this->table('mageos_workflow_execution_step'), 'result')
                ->where('execution_id = ?', $executionId)
                ->where('step_key = ?', $stepKey)
                ->limit(1)
        );
        return $value === false ? null : (string) $value;
    }
}
