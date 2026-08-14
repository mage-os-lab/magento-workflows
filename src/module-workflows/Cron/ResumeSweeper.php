<?php
declare(strict_types=1);

namespace MageOS\Workflows\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\MessageQueue\PublisherInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface;
use Psr\Log\LoggerInterface;

/**
 * DB-queue resumption sweeper (docs/08-execution-model.md): every minute,
 * wake delay steps whose resume_at has passed by publishing to the
 * mageos.workflow.resume topic. Uses the (status, resume_at) sweeper index.
 *
 * The execution row is claimed atomically (waiting -> pending) so overlapping
 * sweeps or a slow consumer never double-publish the same resumption.
 *
 * Also recovers zombies, in two shapes — both republished to the execute topic,
 * both safe to republish because the executor persists state before side
 * effects AND resumes past an already-complete step instead of re-running it
 * (Executor::resumePastCompletedStep):
 *
 *  1. STEP zombies: a step row stuck 'running' with a claim older than 30
 *     minutes — the consumer died INSIDE the step.
 *  2. STRANDED executions: an execution row stuck 'running' whose step rows
 *     hold nothing running or pending — the consumer died in the gap AFTER a
 *     step row was closed and BEFORE the execution row moved on. Nothing used
 *     to match those (the step sweep only sees 'running' rows), and pruning
 *     skips non-completed executions, so the row was immortal.
 */
class ResumeSweeper
{
    public const TOPIC_RESUME = 'mageos.workflow.resume';
    public const TOPIC_EXECUTE = 'mageos.workflow.execute';

    private const STEP_TABLE = 'mageos_workflow_execution_step';
    private const EXECUTION_TABLE = 'mageos_workflow_execution';

    private const ZOMBIE_CLAIM_MINUTES = 30;
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly PublisherInterface $publisher,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $this->resumeDueDelays();
        $this->recoverZombies();
        $this->recoverStrandedExecutions();
    }

    private function resumeDueDelays(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $stepTable = $this->resourceConnection->getTableName(self::STEP_TABLE);
        $executionTable = $this->resourceConnection->getTableName(self::EXECUTION_TABLE);
        $now = gmdate('Y-m-d H:i:s');

        $select = $connection->select()
            ->from($stepTable, ['execution_id'])
            ->where('status = ?', WorkflowExecutionStepInterface::STATUS_WAITING)
            ->where('resume_at IS NOT NULL')
            ->where('resume_at <= ?', $now)
            ->limit(self::BATCH_SIZE);

        foreach (array_unique(array_map('intval', $connection->fetchCol($select))) as $executionId) {
            // Atomic claim: only one sweep transitions waiting -> pending
            $claimed = $connection->update(
                $executionTable,
                ['status' => WorkflowExecutionInterface::STATUS_PENDING],
                [
                    'execution_id = ?' => $executionId,
                    'status = ?' => WorkflowExecutionInterface::STATUS_WAITING,
                ]
            );
            if ($claimed !== 1) {
                continue;
            }
            try {
                $this->publisher->publish(self::TOPIC_RESUME, (string) $executionId);
            } catch (\Throwable $e) {
                // Roll the claim back so the next sweep retries
                $connection->update(
                    $executionTable,
                    ['status' => WorkflowExecutionInterface::STATUS_WAITING],
                    [
                        'execution_id = ?' => $executionId,
                        'status = ?' => WorkflowExecutionInterface::STATUS_PENDING,
                    ]
                );
                $this->logger->error(
                    sprintf('Workflow resume sweeper could not publish execution %d: %s', $executionId, $e->getMessage()),
                    ['exception' => $e]
                );
            }
        }
    }

    private function recoverZombies(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $stepTable = $this->resourceConnection->getTableName(self::STEP_TABLE);
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::ZOMBIE_CLAIM_MINUTES * 60);

        $select = $connection->select()
            ->from($stepTable, ['step_execution_id', 'execution_id'])
            ->where('status = ?', WorkflowExecutionStepInterface::STATUS_RUNNING)
            ->where('claimed_at IS NOT NULL')
            ->where('claimed_at < ?', $cutoff)
            ->limit(self::BATCH_SIZE);

        foreach ($connection->fetchAll($select) as $row) {
            $stepExecutionId = (int) $row['step_execution_id'];
            $executionId = (int) $row['execution_id'];

            // Re-claim so the next sweep does not republish the same zombie
            $reclaimed = $connection->update(
                $stepTable,
                ['claimed_at' => gmdate('Y-m-d H:i:s')],
                [
                    'step_execution_id = ?' => $stepExecutionId,
                    'status = ?' => WorkflowExecutionStepInterface::STATUS_RUNNING,
                    'claimed_at < ?' => $cutoff,
                ]
            );
            if ($reclaimed !== 1) {
                continue;
            }

            $this->logger->warning(sprintf(
                'Workflow zombie recovery: republishing execution %d (step row %d claimed > %d min ago)',
                $executionId,
                $stepExecutionId,
                self::ZOMBIE_CLAIM_MINUTES
            ));

            try {
                $this->publisher->publish(self::TOPIC_EXECUTE, (string) $executionId);
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf('Workflow zombie recovery could not publish execution %d: %s', $executionId, $e->getMessage()),
                    ['exception' => $e]
                );
            }
        }
    }

    /**
     * Executions stranded in `running` with nothing in flight.
     *
     * The step sweep above only sees rows still marked `running`, so it misses
     * the OTHER half of the crash window: the consumer dies after the step row
     * was closed (complete/failed) but before the execution row moved past it.
     * The execution then points at a finished step with no worker, no queue
     * message and no waiting row — and PruneExecutions skips non-completed
     * executions, so the row lives forever. This pass ends that.
     *
     * Staleness — deliberately the SAME ZOMBIE_CLAIM_MINUTES clock as the step
     * sweep, so "how long before we assume the consumer is dead" is one number,
     * not two that can drift apart. A candidate must satisfy all of:
     *
     *  - execution status `running` and triggered_at older than the cutoff
     *    (the coarse, index-served pre-filter — triggered_at always precedes
     *    every step timestamp, so it can never exclude a genuine candidate);
     *  - NO step row in `running` or `pending`. Those two states mean somebody
     *    else still owns this execution: `running` is the step sweep's job (it
     *    has its own claim clock), and `pending` is a retryable failure the
     *    queue is still redelivering with backoff. A queue that permanently
     *    gives up on a `pending` step still strands the row — a narrower gap,
     *    left for the dead-letter tooling rather than guessed at here;
     *  - the newest timestamp across its step rows (claimed_at / started_at /
     *    finished_at, whichever is latest) older than the cutoff, or, for an
     *    execution with no step rows at all, triggered_at. This is what keeps
     *    the pass off a LIVE consumer: between two steps a healthy walk shows
     *    no running row for a few milliseconds, but its newest finished_at is
     *    "now", so it is never a candidate.
     *
     * `waiting` step rows do NOT block recovery. A parked execution has status
     * `waiting` (that pairing is written in one save) and is handled by
     * resumeDueDelays; a `running` execution holding a `waiting` step row is
     * only reachable by crashing between those writes, and republishing it
     * re-parks the gate — the completed-step guard deliberately excludes park
     * steps — which hands it back to the normal resume spine.
     *
     * Republishing is safe precisely because of that guard
     * (Executor::resumePastCompletedStep): the walk re-enters on the finished
     * step, sees a `complete` row, and resumes PAST it on its recorded outcome
     * instead of re-firing its side effect. Without that guard this sweep would
     * be a duplicate-side-effect generator; with it, it is a resume.
     *
     * The claim is the same atomic conditional UPDATE the delay path uses, in
     * its own direction: running -> pending, so overlapping sweeps publish at
     * most once and a dead broker rolls back. `pending` (rather than leaving it
     * `running`) is what makes the claim self-limiting, and it is honest: the
     * execution IS queued and unstarted. It is also visible — HealthCheck's
     * stuck_executions check counts `pending` older than 10 minutes, so an
     * execution nobody ever picks up now surfaces as "the execute consumer is
     * not running" instead of hiding in `running` forever. Executor::execute
     * treats `pending` WITH a current_step as a mid-walk resume, not a first
     * run, so the root-condition gate is not re-fired by this claim.
     */
    private function recoverStrandedExecutions(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $executionTable = $this->resourceConnection->getTableName(self::EXECUTION_TABLE);
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::ZOMBIE_CLAIM_MINUTES * 60);

        $select = $connection->select()
            ->from($executionTable, ['execution_id', 'triggered_at'])
            ->where('status = ?', WorkflowExecutionInterface::STATUS_RUNNING)
            ->where('triggered_at < ?', $cutoff)
            ->limit(self::BATCH_SIZE);

        $candidates = [];
        foreach ($connection->fetchAll($select) as $row) {
            $candidates[(int) $row['execution_id']] = (string) ($row['triggered_at'] ?? '');
        }
        if ($candidates === []) {
            return;
        }

        $activity = $this->stepActivity(array_keys($candidates));

        foreach ($candidates as $executionId => $triggeredAt) {
            [$inFlight, $lastActivity] = $activity[$executionId] ?? [false, null];
            if ($inFlight) {
                continue;
            }
            // No step rows at all (died before the first claim): the execution's
            // own clock is the only evidence of when it last moved.
            $lastActivity ??= $triggeredAt;
            if ($lastActivity === '' || strcmp($lastActivity, $cutoff) >= 0) {
                continue;
            }

            $claimed = $connection->update(
                $executionTable,
                ['status' => WorkflowExecutionInterface::STATUS_PENDING],
                [
                    'execution_id = ?' => $executionId,
                    'status = ?' => WorkflowExecutionInterface::STATUS_RUNNING,
                ]
            );
            if ($claimed !== 1) {
                continue;
            }

            $this->logger->warning(sprintf(
                'Workflow stranded-execution recovery: republishing execution %d '
                . '(status running, no step in flight, last activity %s, > %d min ago)',
                $executionId,
                $lastActivity,
                self::ZOMBIE_CLAIM_MINUTES
            ));

            try {
                $this->publisher->publish(self::TOPIC_EXECUTE, (string) $executionId);
            } catch (\Throwable $e) {
                // Roll the claim back so the next sweep retries
                $connection->update(
                    $executionTable,
                    ['status' => WorkflowExecutionInterface::STATUS_RUNNING],
                    [
                        'execution_id = ?' => $executionId,
                        'status = ?' => WorkflowExecutionInterface::STATUS_PENDING,
                    ]
                );
                $this->logger->error(
                    sprintf(
                        'Workflow stranded-execution recovery could not publish execution %d: %s',
                        $executionId,
                        $e->getMessage()
                    ),
                    ['exception' => $e]
                );
            }
        }
    }

    /**
     * Per-execution step-row summary for the candidates above, in ONE query:
     * whether anything is still in flight (a `running` or `pending` row) and
     * the newest activity timestamp on record.
     *
     * @param int[] $executionIds
     * @return array<int, array{0: bool, 1: string|null}> execution_id => [in flight, newest timestamp]
     */
    private function stepActivity(array $executionIds): array
    {
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName(self::STEP_TABLE),
                ['execution_id', 'status', 'claimed_at', 'started_at', 'finished_at']
            )
            ->where('execution_id IN (?)', $executionIds);

        $summary = [];
        foreach ($connection->fetchAll($select) as $row) {
            $executionId = (int) $row['execution_id'];
            [$inFlight, $newest] = $summary[$executionId] ?? [false, null];

            if (in_array((string) $row['status'], [
                WorkflowExecutionStepInterface::STATUS_RUNNING,
                WorkflowExecutionStepInterface::STATUS_PENDING,
            ], true)) {
                $inFlight = true;
            }
            foreach (['claimed_at', 'started_at', 'finished_at'] as $column) {
                $value = $row[$column] ?? null;
                if (is_string($value) && $value !== '' && ($newest === null || strcmp($value, $newest) > 0)) {
                    $newest = $value;
                }
            }

            $summary[$executionId] = [$inFlight, $newest];
        }

        return $summary;
    }
}
