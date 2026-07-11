<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\Engine;

use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Cron\ResumeSweeper;
use MageOS\Workflows\Model\Queue\ExecuteConsumer;
use MageOS\Workflows\Model\Queue\ResumeConsumer;
use MageOS\Workflows\Test\Integration\_files\RecordingPublisher;
use MageOS\Workflows\Test\Integration\_files\ThrowingPublisher;
use MageOS\Workflows\Test\Integration\_files\WorkflowEngineTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Plan #8 (docs/20 §4): delay parking and the ResumeSweeper (docs/08). A delay
 * step parks the execution `waiting` with a step row carrying resume_at;
 * rewinding resume_at and sweeping claims the execution exactly once
 * (waiting -> pending conditioned UPDATE) and publishes exactly one resume; a
 * publish failure rolls the claim back so the next sweep retries; a zombie step
 * (claimed_at rewound past the 30-minute cutoff) is re-claimed exactly once;
 * ResumeConsumer continues the walk from the parked step.
 *
 * @magentoDbIsolation enabled
 */
class DelayResumeTest extends TestCase
{
    use WorkflowEngineTestTrait;

    /**
     * Park a shadow delay workflow and return [executionId].
     */
    private function parkDelayExecution(): int
    {
        $definition = [
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => ['type' => 'action', 'action' => 'order.add_comment', 'config' => ['comment' => 'before'], 'next' => 'd1'],
                'd1' => ['type' => 'delay', 'config' => ['duration' => 'PT1H'], 'next' => 's2'],
                's2' => ['type' => 'action', 'action' => 'order.add_comment', 'config' => ['comment' => 'after'], 'next' => null],
            ],
        ];
        $workflow = $this->createWorkflow([
            'name' => 'delay park ' . uniqid('', true),
            'definition' => $definition,
            'status' => WorkflowInterface::STATUS_SHADOW,
        ]);
        $execution = $this->seedExecution((int) $workflow->getWorkflowId(), $workflow->getDefinition(), 55, 1);

        $this->om()->get(ExecuteConsumer::class)->process((string) $execution->getExecutionId());

        return (int) $execution->getExecutionId();
    }

    public function testDelayParksExecutionWaitingWithResumeAt(): void
    {
        $id = $this->parkDelayExecution();

        $execution = $this->reloadExecution($id);
        $this->assertSame(WorkflowExecutionInterface::STATUS_WAITING, $execution->getStatus());
        $this->assertSame('s2', $execution->getCurrentStep(), 'current_step points AFTER the delay');

        $delayRow = $this->stepRow($id, 'd1');
        $this->assertSame(WorkflowExecutionStepInterface::STATUS_WAITING, $delayRow['status']);
        $this->assertNotNull($delayRow['resume_at'], 'The delay step carries a resume time');
    }

    public function testSweeperClaimsExactlyOnceAndPublishesOnce(): void
    {
        $id = $this->parkDelayExecution();
        $this->rewindTimestamp('mageos_workflow_execution_step', 'resume_at', $this->gmPast(120), 'execution_id', $id);

        $recorder = new RecordingPublisher();
        $sweeper = $this->om()->create(ResumeSweeper::class, ['publisher' => $recorder]);

        $sweeper->execute();
        $sweeper->execute(); // second sweep must claim nothing

        $this->assertSame(
            [(string) $id],
            $recorder->messagesFor(ResumeSweeper::TOPIC_RESUME),
            'Exactly one resume publish for the due execution across two sweeps'
        );
        $this->assertSame(
            WorkflowExecutionInterface::STATUS_PENDING,
            $this->reloadExecution($id)->getStatus(),
            'The execution was claimed waiting -> pending'
        );
    }

    public function testPublishFailureRollsTheClaimBackForRetry(): void
    {
        $id = $this->parkDelayExecution();
        $this->rewindTimestamp('mageos_workflow_execution_step', 'resume_at', $this->gmPast(120), 'execution_id', $id);

        $throwing = new ThrowingPublisher();
        $poisonedSweeper = $this->om()->create(ResumeSweeper::class, ['publisher' => $throwing]);
        $poisonedSweeper->execute();

        $this->assertGreaterThanOrEqual(1, $throwing->attempts);
        $this->assertSame(
            WorkflowExecutionInterface::STATUS_WAITING,
            $this->reloadExecution($id)->getStatus(),
            'A failed publish rolls the claim back to waiting'
        );

        // A subsequent healthy sweep re-claims and publishes once.
        $recorder = new RecordingPublisher();
        $this->om()->create(ResumeSweeper::class, ['publisher' => $recorder])->execute();
        $this->assertSame([(string) $id], $recorder->messagesFor(ResumeSweeper::TOPIC_RESUME));
    }

    public function testResumeConsumerContinuesTheWalkPastTheDelay(): void
    {
        $id = $this->parkDelayExecution();
        $this->rewindTimestamp('mageos_workflow_execution_step', 'resume_at', $this->gmPast(120), 'execution_id', $id);

        $this->om()->get(ResumeConsumer::class)->process((string) $id);

        $execution = $this->reloadExecution($id);
        $this->assertSame(WorkflowExecutionInterface::STATUS_COMPLETE, $execution->getStatus());
        $statuses = $this->stepStatuses($id);
        $this->assertSame('complete', $statuses['d1'], 'The parked delay step is closed');
        $this->assertSame('complete', $statuses['s2'], 'The step after the delay ran');
    }

    public function testZombieRunningStepIsReclaimedExactlyOnce(): void
    {
        // A step stuck 'running' with a stale claim = a consumer that died mid-step.
        $workflow = $this->createWorkflow([
            'name' => 'zombie ' . uniqid('', true),
            'definition' => [
                'schema' => 1,
                'entry' => 's1',
                'steps' => ['s1' => ['type' => 'action', 'action' => 'order.add_comment', 'config' => ['comment' => 'z'], 'next' => null]],
            ],
            'status' => WorkflowInterface::STATUS_SHADOW,
        ]);
        $execution = $this->seedExecution(
            (int) $workflow->getWorkflowId(),
            $workflow->getDefinition(),
            56,
            1,
            [],
            1,
            WorkflowExecutionInterface::STATUS_RUNNING
        );
        $id = (int) $execution->getExecutionId();

        $connection = $this->db();
        $connection->insert($this->table('mageos_workflow_execution_step'), [
            'execution_id' => $id,
            'step_key' => 's1',
            'status' => WorkflowExecutionStepInterface::STATUS_RUNNING,
            'claimed_at' => $this->gmPast(31 * 60 + 5),
            'started_at' => $this->gmPast(31 * 60 + 5),
        ]);

        $recorder = new RecordingPublisher();
        $sweeper = $this->om()->create(ResumeSweeper::class, ['publisher' => $recorder]);
        $sweeper->execute();
        $sweeper->execute(); // claim was refreshed; the second pass must not republish

        $this->assertSame(
            [(string) $id],
            $recorder->messagesFor(ResumeSweeper::TOPIC_EXECUTE),
            'The zombie execution is republished to the execute topic exactly once'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function stepRow(int $executionId, string $stepKey): array
    {
        $connection = $this->db();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->table('mageos_workflow_execution_step'))
                ->where('execution_id = ?', $executionId)
                ->where('step_key = ?', $stepKey)
                ->limit(1)
        );
        $this->assertIsArray($row, sprintf('Expected a step row for "%s"', $stepKey));
        return $row;
    }
}
