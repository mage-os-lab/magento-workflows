<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\MessageQueue\PublisherInterface;
use MageOS\Workflows\Cron\ResumeSweeper;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * DB-queue resumption sweeper (docs/08-execution-model.md "Resumption after
 * delays"): due waiting executions are claimed with an atomic conditional
 * waiting -> pending UPDATE before publishing — a claim reporting 0 affected
 * rows means another sweep/consumer won the race and the execution is NOT
 * published again (no double-publish). A failed publish rolls the claim back
 * so the next sweep retries. Zombie steps (running with a stale claim
 * timestamp) are re-claimed and their executions republished to the execute
 * topic — redelivery is safe because state persists before side effects.
 *
 * The stranded-execution pass (finding 6) covers the other half of that zombie
 * story: an execution left in `running` whose step rows hold nothing running or
 * pending. Those tests pin both directions — a genuinely abandoned execution is
 * claimed (running -> pending) and republished exactly once, and a live
 * consumer, a queue-owned retry, or a step the zombie pass already owns is
 * never stolen.
 */
class ResumeSweeperTest extends TestCase
{
    private const EXECUTION_TABLE = 'mageos_workflow_execution';
    private const STEP_TABLE = 'mageos_workflow_execution_step';

    private SweeperFakeConnection $connection;

    private SweeperRecordingPublisher $publisher;

    public function setUp(): void
    {
        $this->connection = new SweeperFakeConnection();
        $this->publisher = new SweeperRecordingPublisher();
    }

    private function sweeper(): ResumeSweeper
    {
        return new ResumeSweeper(
            new SweeperFakeResourceConnection($this->connection),
            $this->publisher,
            new NullLogger()
        );
    }

    private function past(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() - $seconds);
    }

    private function future(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() + $seconds);
    }

    public function testDueDelayIsClaimedAndPublishedToTheResumeTopic(): void
    {
        $this->connection->steps[] = [
            'step_execution_id' => 1,
            'execution_id' => 11,
            'status' => 'waiting',
            'resume_at' => $this->past(60),
            'claimed_at' => null,
        ];
        $this->connection->executions[11] = ['execution_id' => 11, 'status' => 'waiting'];

        $this->sweeper()->execute();

        $this->assertCount(1, $this->publisher->published);
        $this->assertSame(ResumeSweeper::TOPIC_RESUME, $this->publisher->published[0]['topic']);
        $this->assertSame('11', $this->publisher->published[0]['data']);
        // Claimed atomically: the execution left "waiting" before the publish.
        $this->assertSame('pending', $this->connection->executions[11]['status']);
    }

    public function testDelayNotYetDueIsNotPublished(): void
    {
        $this->connection->steps[] = [
            'step_execution_id' => 1,
            'execution_id' => 11,
            'status' => 'waiting',
            'resume_at' => $this->future(3600),
            'claimed_at' => null,
        ];
        $this->connection->executions[11] = ['execution_id' => 11, 'status' => 'waiting'];

        $this->sweeper()->execute();

        $this->assertCount(0, $this->publisher->published);
        $this->assertSame('waiting', $this->connection->executions[11]['status']);
    }

    public function testExecutionWhoseClaimReportsZeroRowsIsNotPublished(): void
    {
        // The execution already left "waiting" (a concurrent sweep or event
        // resume claimed it): the conditional UPDATE affects 0 rows, and the
        // loser must NOT publish a second resume for the same execution.
        $this->connection->steps[] = [
            'step_execution_id' => 1,
            'execution_id' => 11,
            'status' => 'waiting',
            'resume_at' => $this->past(60),
            'claimed_at' => null,
        ];
        $this->connection->executions[11] = ['execution_id' => 11, 'status' => 'pending'];

        $this->sweeper()->execute();

        $this->assertCount(0, $this->publisher->published);
    }

    public function testTwoDueStepRowsForOneExecutionPublishOnce(): void
    {
        $this->connection->steps[] = [
            'step_execution_id' => 1,
            'execution_id' => 11,
            'status' => 'waiting',
            'resume_at' => $this->past(120),
            'claimed_at' => null,
        ];
        $this->connection->steps[] = [
            'step_execution_id' => 2,
            'execution_id' => 11,
            'status' => 'waiting',
            'resume_at' => $this->past(60),
            'claimed_at' => null,
        ];
        $this->connection->executions[11] = ['execution_id' => 11, 'status' => 'waiting'];

        $this->sweeper()->execute();

        $this->assertCount(1, $this->publisher->published);
    }

    public function testPublishFailureRollsTheClaimBackForTheNextSweep(): void
    {
        $this->connection->steps[] = [
            'step_execution_id' => 1,
            'execution_id' => 11,
            'status' => 'waiting',
            'resume_at' => $this->past(60),
            'claimed_at' => null,
        ];
        $this->connection->executions[11] = ['execution_id' => 11, 'status' => 'waiting'];
        $this->publisher->throwOnTopic = ResumeSweeper::TOPIC_RESUME;

        // Must not escape: a broken broker cannot crash the cron group.
        $this->sweeper()->execute();

        $this->assertCount(0, $this->publisher->published);
        // Claim rolled back so the execution is still owned by the sweeper.
        $this->assertSame('waiting', $this->connection->executions[11]['status']);
    }

    public function testZombieRunningStepIsReclaimedAndRepublished(): void
    {
        // Consumer died mid-step > 30 minutes ago: fail-or-retry contract is
        // "retry" — republish to the execute topic (state persisted before
        // side effects makes redelivery safe).
        $this->connection->steps[] = [
            'step_execution_id' => 5,
            'execution_id' => 21,
            'status' => 'running',
            'resume_at' => null,
            'claimed_at' => $this->past(31 * 60),
        ];

        $this->sweeper()->execute();

        $this->assertCount(1, $this->publisher->published);
        $this->assertSame(ResumeSweeper::TOPIC_EXECUTE, $this->publisher->published[0]['topic']);
        $this->assertSame('21', $this->publisher->published[0]['data']);
        // Re-claimed: claimed_at refreshed so the next sweep skips this zombie.
        $this->assertTrue($this->connection->steps[0]['claimed_at'] > $this->past(60));
    }

    public function testRecentlyClaimedRunningStepIsNotAZombie(): void
    {
        $this->connection->steps[] = [
            'step_execution_id' => 5,
            'execution_id' => 21,
            'status' => 'running',
            'resume_at' => null,
            'claimed_at' => $this->past(5 * 60),
        ];

        $this->sweeper()->execute();

        $this->assertCount(0, $this->publisher->published);
    }

    public function testZombieWhoseReclaimLosesTheRaceIsNotRepublished(): void
    {
        $this->connection->steps[] = [
            'step_execution_id' => 5,
            'execution_id' => 21,
            'status' => 'running',
            'resume_at' => null,
            'claimed_at' => $this->past(31 * 60),
        ];
        // Another overlapping sweep refreshed claimed_at between our SELECT
        // and UPDATE: the conditional re-claim reports 0 rows.
        $this->connection->forcedStepUpdateResult = 0;

        $this->sweeper()->execute();

        $this->assertCount(0, $this->publisher->published);
    }

    // ------------------------------------------------------------------
    // Stranded executions: running, but nothing in flight (finding 6)
    // ------------------------------------------------------------------

    public function testExecutionStrandedRunningWithOnlyCompleteStepsIsClaimedAndRepublished(): void
    {
        // The gap the step sweep cannot see: the consumer died AFTER closing
        // the step row and BEFORE the execution row moved past it. No running
        // step row, no queue message, and pruning skips non-completed rows —
        // this execution was previously immortal.
        $this->connection->executions[31] = [
            'execution_id' => 31,
            'status' => 'running',
            'triggered_at' => $this->past(90 * 60),
        ];
        $this->connection->steps[] = $this->stepRow(9, 31, 'complete', ['finished_at' => $this->past(45 * 60)]);

        $this->sweeper()->execute();

        $this->assertCount(1, $this->publisher->published);
        $this->assertSame(ResumeSweeper::TOPIC_EXECUTE, $this->publisher->published[0]['topic']);
        $this->assertSame('31', $this->publisher->published[0]['data']);
        // Claimed atomically out of `running`, which is also what makes the row
        // visible to HealthCheck's stuck-pending check if nobody consumes it.
        $this->assertSame('pending', $this->connection->executions[31]['status']);
    }

    public function testExecutionStrandedRunningWithNoStepRowsAtAllIsRecoveredOnItsOwnClock(): void
    {
        // Died between the "status running" save and the first step claim: the
        // execution's own triggered_at is the only evidence of when it moved.
        $this->connection->executions[32] = [
            'execution_id' => 32,
            'status' => 'running',
            'triggered_at' => $this->past(40 * 60),
        ];

        $this->sweeper()->execute();

        $this->assertCount(1, $this->publisher->published);
        $this->assertSame('32', $this->publisher->published[0]['data']);
        $this->assertSame('pending', $this->connection->executions[32]['status']);
    }

    public function testLiveExecutionBetweenTwoStepsIsNotStolen(): void
    {
        // A healthy walk shows no running step row for the few milliseconds
        // between closing one step and claiming the next. Recent activity —
        // not the absence of a running row — is what proves the consumer is
        // alive, so the newest step timestamp gates the sweep.
        $this->connection->executions[33] = [
            'execution_id' => 33,
            'status' => 'running',
            'triggered_at' => $this->past(90 * 60),
        ];
        $this->connection->steps[] = $this->stepRow(10, 33, 'complete', ['finished_at' => $this->past(45 * 60)]);
        $this->connection->steps[] = $this->stepRow(11, 33, 'complete', ['finished_at' => $this->past(20)]);

        $this->sweeper()->execute();

        $this->assertCount(0, $this->publisher->published);
        $this->assertSame('running', $this->connection->executions[33]['status']);
    }

    public function testExecutionWithAPendingStepIsLeftToTheQueuesRetryBackoff(): void
    {
        // `pending` = a retryable failure the queue is still redelivering.
        // Somebody else owns this execution; the sweeper must not race it.
        $this->connection->executions[34] = [
            'execution_id' => 34,
            'status' => 'running',
            'triggered_at' => $this->past(90 * 60),
        ];
        $this->connection->steps[] = $this->stepRow(12, 34, 'pending', ['claimed_at' => $this->past(60 * 60)]);

        $this->sweeper()->execute();

        $this->assertCount(0, $this->publisher->published);
        $this->assertSame('running', $this->connection->executions[34]['status']);
    }

    public function testExecutionWithAStaleRunningStepIsRepublishedOnceByTheStepSweepOnly(): void
    {
        // Both passes can see this execution. The step sweep owns it (it has
        // its own re-claim clock), so the stranded pass must skip it — one
        // republish, not two, and the execution row is left in `running` for
        // the redelivered walk.
        $this->connection->executions[35] = [
            'execution_id' => 35,
            'status' => 'running',
            'triggered_at' => $this->past(90 * 60),
        ];
        $this->connection->steps[] = $this->stepRow(13, 35, 'running', ['claimed_at' => $this->past(31 * 60)]);

        $this->sweeper()->execute();

        $this->assertCount(1, $this->publisher->published);
        $this->assertSame(ResumeSweeper::TOPIC_EXECUTE, $this->publisher->published[0]['topic']);
        $this->assertSame('running', $this->connection->executions[35]['status']);
    }

    public function testRecentlyTriggeredRunningExecutionIsNotStranded(): void
    {
        $this->connection->executions[36] = [
            'execution_id' => 36,
            'status' => 'running',
            'triggered_at' => $this->past(5 * 60),
        ];
        $this->connection->steps[] = $this->stepRow(14, 36, 'complete', ['finished_at' => $this->past(4 * 60)]);

        $this->sweeper()->execute();

        $this->assertCount(0, $this->publisher->published);
        $this->assertSame('running', $this->connection->executions[36]['status']);
    }

    public function testWaitingAndTerminalExecutionsAreNeverStrandedCandidates(): void
    {
        // Only `running` strands: a parked execution is `waiting` (owned by the
        // delay sweep) and complete/failed rows are done.
        foreach (['waiting', 'complete', 'failed', 'cancelled', 'skipped', 'pending'] as $i => $status) {
            $this->connection->executions[40 + $i] = [
                'execution_id' => 40 + $i,
                'status' => $status,
                'triggered_at' => $this->past(90 * 60),
            ];
        }

        $this->sweeper()->execute();

        $this->assertCount(0, $this->publisher->published);
    }

    public function testStrandedClaimIsNotRepublishedByTheNextSweep(): void
    {
        // The claim is self-limiting: once the row leaves `running` no later
        // sweep can select it, so a dead consumer gets exactly one message.
        $this->connection->executions[37] = [
            'execution_id' => 37,
            'status' => 'running',
            'triggered_at' => $this->past(90 * 60),
        ];
        $this->connection->steps[] = $this->stepRow(15, 37, 'complete', ['finished_at' => $this->past(45 * 60)]);

        $sweeper = $this->sweeper();
        $sweeper->execute();
        $sweeper->execute();

        $this->assertCount(1, $this->publisher->published);
    }

    public function testStrandedPublishFailureRollsTheClaimBackToRunning(): void
    {
        $this->connection->executions[38] = [
            'execution_id' => 38,
            'status' => 'running',
            'triggered_at' => $this->past(90 * 60),
        ];
        $this->connection->steps[] = $this->stepRow(16, 38, 'complete', ['finished_at' => $this->past(45 * 60)]);
        $this->publisher->throwOnTopic = ResumeSweeper::TOPIC_EXECUTE;

        // Must not escape: a broken broker cannot crash the cron group.
        $this->sweeper()->execute();

        $this->assertCount(0, $this->publisher->published);
        $this->assertSame('running', $this->connection->executions[38]['status']);
    }

    public function testStrandedExecutionHoldingOnlyAWaitingStepRowIsRecovered(): void
    {
        // Crash between the step-row park and the execution-row save: the pair
        // (execution waiting + step waiting) is written in one save, so this
        // mismatch is unreachable in a healthy walk. The delay sweep cannot
        // claim it (that claim demands a `waiting` EXECUTION), so republishing
        // — which re-parks the gate, since the completed-step guard excludes
        // park steps — is the only way back onto the resume spine.
        $this->connection->executions[39] = [
            'execution_id' => 39,
            'status' => 'running',
            'triggered_at' => $this->past(90 * 60),
        ];
        $this->connection->steps[] = $this->stepRow(17, 39, 'waiting', [
            'claimed_at' => $this->past(60 * 60),
            'resume_at' => $this->future(86400),
        ]);

        $this->sweeper()->execute();

        $this->assertCount(1, $this->publisher->published);
        $this->assertSame(ResumeSweeper::TOPIC_EXECUTE, $this->publisher->published[0]['topic']);
        $this->assertSame('39', $this->publisher->published[0]['data']);
    }

    /**
     * @param array<string, string|null> $overrides
     * @return array<string, mixed>
     */
    private function stepRow(int $stepExecutionId, int $executionId, string $status, array $overrides = []): array
    {
        return array_merge([
            'step_execution_id' => $stepExecutionId,
            'execution_id' => $executionId,
            'status' => $status,
            'resume_at' => null,
            'claimed_at' => null,
            'started_at' => null,
            'finished_at' => null,
        ], $overrides);
    }
}

/**
 * Chainable select recorder (from/where/limit), consumed by the fake
 * connection's fetch methods.
 */
class SweeperFakeSelect
{
    public string $table = '';

    /** @var string[] */
    public array $columns = [];

    /** @var array<int, array{0: string, 1: mixed}> */
    public array $wheres = [];

    public ?int $limit = null;

    public function from($table, $columns = '*'): self
    {
        $this->table = (string) $table;
        $this->columns = is_array($columns) ? array_values($columns) : [(string) $columns];
        return $this;
    }

    public function where($condition, $value = null): self
    {
        $this->wheres[] = [(string) $condition, $value];
        return $this;
    }

    public function limit($count, $offset = 0): self
    {
        $this->limit = (int) $count;
        return $this;
    }
}

/**
 * In-memory step/execution tables with faithful affected-row counts for the
 * conditional claim UPDATEs the sweeper's race safety branches on (mirrors
 * the approvals suite's InMemoryConnection approach).
 */
class SweeperFakeConnection
{
    private const EXECUTION_TABLE = 'mageos_workflow_execution';
    private const STEP_TABLE = 'mageos_workflow_execution_step';

    /** @var array<int, array<string, mixed>> */
    public array $steps = [];

    /** @var array<int, array<string, mixed>> execution_id => row */
    public array $executions = [];

    /** @var array<int, array{table: string, bind: array, where: array}> */
    public array $updates = [];

    /** Force the step-table (zombie re-claim) UPDATE result; null = evaluate the row */
    public ?int $forcedStepUpdateResult = null;

    public function select(): SweeperFakeSelect
    {
        return new SweeperFakeSelect();
    }

    /**
     * @return array<int, mixed>
     */
    public function fetchCol($select): array
    {
        $rows = $this->matchedRows($select);
        $column = $select->columns[0] ?? null;
        return array_map(
            static fn (array $r) => $column !== null ? ($r[$column] ?? null) : reset($r),
            $rows
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll($select): array
    {
        return $this->matchedRows($select);
    }

    public function update($table, array $bind, $where = ''): int
    {
        $where = is_array($where) ? $where : [];
        $this->updates[] = ['table' => (string) $table, 'bind' => $bind, 'where' => $where];

        if ((string) $table === self::EXECUTION_TABLE) {
            $count = 0;
            foreach ($this->executions as $id => $row) {
                if ($this->matchesAll($row, $where)) {
                    $this->executions[$id] = array_merge($row, $bind);
                    $count++;
                }
            }
            return $count;
        }

        if ((string) $table === self::STEP_TABLE) {
            if ($this->forcedStepUpdateResult !== null) {
                return $this->forcedStepUpdateResult;
            }
            $count = 0;
            foreach ($this->steps as $i => $row) {
                if ($this->matchesAll($row, $where)) {
                    $this->steps[$i] = array_merge($row, $bind);
                    $count++;
                }
            }
            return $count;
        }

        return 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function matchedRows(SweeperFakeSelect $select): array
    {
        $source = match ($select->table) {
            self::STEP_TABLE => $this->steps,
            self::EXECUTION_TABLE => array_values($this->executions),
            default => [],
        };
        $matched = array_values(array_filter(
            $source,
            fn (array $row) => $this->matchesWheres($row, $select->wheres)
        ));
        if ($select->limit !== null) {
            $matched = array_slice($matched, 0, $select->limit);
        }
        return $matched;
    }

    /**
     * @param array<int, array{0: string, 1: mixed}> $wheres
     */
    private function matchesWheres(array $row, array $wheres): bool
    {
        foreach ($wheres as [$condition, $value]) {
            if (!$this->matchesCondition($row, $condition, $value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed> $where update-style condition => value map
     */
    private function matchesAll(array $row, array $where): bool
    {
        foreach ($where as $condition => $value) {
            if (!$this->matchesCondition($row, (string) $condition, $value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Supports the sweeper's condition shapes: "col = ?", "col < ?",
     * "col <= ?", "col IN (?)", "col IS NOT NULL". Datetime strings compare
     * lexicographically, matching their SQL ordering.
     */
    private function matchesCondition(array $row, string $condition, mixed $value): bool
    {
        if (str_contains($condition, 'IS NOT NULL')) {
            $column = trim(str_replace('IS NOT NULL', '', $condition));
            return ($row[$column] ?? null) !== null;
        }
        if (str_contains($condition, 'IN (?)')) {
            $column = trim(str_replace('IN (?)', '', $condition));
            $haystack = array_map('strval', is_array($value) ? $value : [$value]);
            return in_array((string) ($row[$column] ?? ''), $haystack, true);
        }
        $parts = preg_split('/\s+/', trim($condition));
        $column = $parts[0] ?? '';
        $operator = $parts[1] ?? '=';
        $actual = $row[$column] ?? null;
        return match ($operator) {
            '=' => (string) $actual === (string) $value,
            '<' => $actual !== null && strcmp((string) $actual, (string) $value) < 0,
            '<=' => $actual !== null && strcmp((string) $actual, (string) $value) <= 0,
            default => false,
        };
    }
}

class SweeperFakeResourceConnection extends ResourceConnection
{
    public function __construct(private readonly SweeperFakeConnection $connection)
    {
    }

    public function getConnection($resourceName = self::DEFAULT_CONNECTION)
    {
        return $this->connection;
    }

    public function getTableName($modelEntity, $connectionName = self::DEFAULT_CONNECTION)
    {
        return (string) $modelEntity;
    }
}

/**
 * Records successful publishes; optionally fails a given topic to exercise
 * the claim rollback path.
 */
class SweeperRecordingPublisher implements PublisherInterface
{
    /** @var array<int, array{topic: string, data: mixed}> */
    public array $published = [];

    public ?string $throwOnTopic = null;

    public function publish($topicName, $data)
    {
        if ($this->throwOnTopic !== null && $topicName === $this->throwOnTopic) {
            throw new \RuntimeException('broker unavailable');
        }
        $this->published[] = ['topic' => $topicName, 'data' => $data];
        return null;
    }
}
