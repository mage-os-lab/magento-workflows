<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use MageOS\Workflows\Cron\PruneExecutions;
use MageOS\Workflows\Test\Unit\Stub\StubScopeConfig;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * Retention / PII pruning (docs/10-security.md "PII containment" #1,
 * docs/15-operations.md "Retention / PII pruning"): executions completed
 * before the configured TTL are deleted together with their step rows; newer
 * ones are retained; rows still in flight (completed_at IS NULL) are never
 * pruned regardless of age; and dry-run audit rows are pruned first on their
 * own, shorter clock (default 7 days vs the general 90).
 *
 * Debounce slots ride the same cron: a slot only guards its own time bucket,
 * so rows older than max(2x window, 1 hour) are swept; fresher rows are kept
 * (they may still be the active bucket's guard).
 */
class PruneExecutionsTest extends TestCase
{
    private PruneFakeConnection $connection;

    public function setUp(): void
    {
        $this->connection = new PruneFakeConnection();
    }

    private function cron(array $config = []): PruneExecutions
    {
        return new PruneExecutions(
            new PruneFakeResourceConnection($this->connection),
            new StubScopeConfig($config),
            new NullLogger()
        );
    }

    private function daysAgo(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - $days * 86400);
    }

    private function seedExecution(int $id, ?string $completedAt, string $mode = 'live'): void
    {
        $this->connection->executions[$id] = [
            'execution_id' => $id,
            'completed_at' => $completedAt,
            'mode' => $mode,
        ];
    }

    public function testExecutionsOlderThanTheRetentionTtlAreDeletedWithTheirSteps(): void
    {
        $this->seedExecution(1, $this->daysAgo(100));

        $this->cron()->execute();

        $this->assertFalse(isset($this->connection->executions[1]));
        // Step rows go with the execution (PII lives in both).
        $this->assertCount(1, $this->connection->stepDeletes);
        $this->assertSame([1], $this->connection->stepDeletes[0]);
    }

    public function testExecutionsNewerThanTheRetentionTtlAreRetained(): void
    {
        $this->seedExecution(1, $this->daysAgo(100));
        $this->seedExecution(2, $this->daysAgo(10));

        $this->cron()->execute();

        $this->assertFalse(isset($this->connection->executions[1]));
        $this->assertTrue(isset($this->connection->executions[2]));
    }

    public function testInFlightExecutionsAreNeverPrunedRegardlessOfAge(): void
    {
        // completed_at IS NULL = still pending/running/waiting; docs/15 says
        // these are never eligible no matter how old the row is.
        $this->seedExecution(3, null);

        $this->cron()->execute();

        $this->assertTrue(isset($this->connection->executions[3]));
        $this->assertCount(0, $this->connection->stepDeletes);
    }

    public function testDryRunRowsArePrunedOnTheirOwnShorterClock(): void
    {
        // Both completed 10 days ago: inside the 90-day live window, but past
        // the 7-day dry-run window — only the dry-run row goes.
        $this->seedExecution(4, $this->daysAgo(10), 'dry_run');
        $this->seedExecution(5, $this->daysAgo(10), 'live');

        $this->cron()->execute();

        $this->assertFalse(isset($this->connection->executions[4]));
        $this->assertTrue(isset($this->connection->executions[5]));
    }

    public function testFreshDryRunRowsSurviveTheDryRunClock(): void
    {
        $this->seedExecution(6, $this->daysAgo(2), 'dry_run');

        $this->cron()->execute();

        $this->assertTrue(isset($this->connection->executions[6]));
    }

    public function testConfiguredRetentionDaysIsHonored(): void
    {
        $this->seedExecution(7, $this->daysAgo(40));
        $this->seedExecution(8, $this->daysAgo(20));

        $this->cron([PruneExecutions::CONFIG_RETENTION_DAYS => 30])->execute();

        $this->assertFalse(isset($this->connection->executions[7]));
        $this->assertTrue(isset($this->connection->executions[8]));
    }

    public function testConfiguredDryRunRetentionDaysIsHonored(): void
    {
        $this->seedExecution(9, $this->daysAgo(2), 'dry_run');

        $this->cron([PruneExecutions::CONFIG_DRY_RUN_RETENTION_DAYS => 1])->execute();

        $this->assertFalse(isset($this->connection->executions[9]));
    }

    private function seedDebounce(int $id, string $createdAt): void
    {
        $this->connection->debounces[$id] = [
            'debounce_id' => $id,
            'created_at' => $createdAt,
        ];
    }

    private function secondsAgo(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() - $seconds);
    }

    public function testExpiredDebounceSlotsArePrunedAndFreshOnesKept(): void
    {
        // Default window 60s -> keep horizon is the one-hour floor.
        $this->seedDebounce(1, $this->secondsAgo(2 * 3600));
        $this->seedDebounce(2, $this->secondsAgo(300));

        $this->cron()->execute();

        $this->assertFalse(isset($this->connection->debounces[1]), 'slot past the keep horizon must be swept');
        $this->assertTrue(isset($this->connection->debounces[2]), 'slot inside the keep horizon must survive');
    }

    public function testDebounceKeepHorizonScalesWithTheConfiguredWindow(): void
    {
        // Window 1 day -> keep horizon 2 days: a day-old slot must survive
        // (it may still guard the active bucket), a 3-day-old one must not.
        $this->seedDebounce(3, $this->secondsAgo(86400));
        $this->seedDebounce(4, $this->secondsAgo(3 * 86400));

        $this->cron(['mageos_workflows/guards/debounce_window_seconds' => 86400])->execute();

        $this->assertTrue(isset($this->connection->debounces[3]));
        $this->assertFalse(isset($this->connection->debounces[4]));
    }

    public function testFlushedBatchesOlderThanRetentionArePrunedAndOpenOnesKept(): void
    {
        $this->connection->batches[100] = [
            'batch_id' => 100,
            'status' => 'flushed',
            'flushed_at' => $this->daysAgo(100),
        ];
        $this->connection->batches[101] = [
            'batch_id' => 101,
            'status' => 'open',
            'flushed_at' => null,
        ];

        $this->cron()->execute();

        $this->assertFalse(isset($this->connection->batches[100]));
        $this->assertTrue(isset($this->connection->batches[101]));
    }
}

/**
 * Chainable select recorder for the prune queries.
 */
class PruneFakeSelect
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
 * In-memory execution/batch tables plus a step-delete recorder, evaluating
 * the prune cron's WHERE shapes ("completed_at IS NOT NULL",
 * "completed_at < ?", "mode = ?", "status = ?", "flushed_at < ?").
 */
class PruneFakeConnection
{
    private const EXECUTION_TABLE = 'mageos_workflow_execution';
    private const STEP_TABLE = 'mageos_workflow_execution_step';
    private const BATCH_TABLE = 'mageos_workflow_batch';
    private const DEBOUNCE_TABLE = 'mageos_workflow_debounce';

    /** @var array<int, array<string, mixed>> execution_id => row */
    public array $executions = [];

    /** @var array<int, array<string, mixed>> batch_id => row */
    public array $batches = [];

    /** @var array<int, array<string, mixed>> debounce_id => row */
    public array $debounces = [];

    /** @var array<int, int[]> every step-table delete's execution-id list */
    public array $stepDeletes = [];

    public function select(): PruneFakeSelect
    {
        return new PruneFakeSelect();
    }

    /**
     * @return array<int, mixed>
     */
    public function fetchCol($select): array
    {
        $source = match ($select->table) {
            self::EXECUTION_TABLE => array_values($this->executions),
            self::BATCH_TABLE => array_values($this->batches),
            self::DEBOUNCE_TABLE => array_values($this->debounces),
            default => [],
        };
        $matched = array_values(array_filter(
            $source,
            fn (array $row) => $this->matchesWheres($row, $select->wheres)
        ));
        if ($select->limit !== null) {
            $matched = array_slice($matched, 0, $select->limit);
        }
        $column = $select->columns[0] ?? null;
        return array_map(
            static fn (array $r) => $column !== null ? ($r[$column] ?? null) : reset($r),
            $matched
        );
    }

    public function delete($table, $where = ''): int
    {
        $ids = $this->idsFromWhere(is_array($where) ? $where : []);

        if ((string) $table === self::STEP_TABLE) {
            $this->stepDeletes[] = $ids;
            return count($ids);
        }
        if ((string) $table === self::EXECUTION_TABLE) {
            $deleted = 0;
            foreach ($ids as $id) {
                if (isset($this->executions[$id])) {
                    unset($this->executions[$id]);
                    $deleted++;
                }
            }
            return $deleted;
        }
        if ((string) $table === self::BATCH_TABLE) {
            $deleted = 0;
            foreach ($ids as $id) {
                if (isset($this->batches[$id])) {
                    unset($this->batches[$id]);
                    $deleted++;
                }
            }
            return $deleted;
        }
        if ((string) $table === self::DEBOUNCE_TABLE) {
            $deleted = 0;
            foreach ($ids as $id) {
                if (isset($this->debounces[$id])) {
                    unset($this->debounces[$id]);
                    $deleted++;
                }
            }
            return $deleted;
        }
        return 0;
    }

    /**
     * @param array<string, mixed> $where e.g. ['execution_id IN (?)' => [1, 2]]
     * @return int[]
     */
    private function idsFromWhere(array $where): array
    {
        foreach ($where as $condition => $value) {
            if (str_contains((string) $condition, ' IN ')) {
                return array_map('intval', is_array($value) ? $value : [$value]);
            }
        }
        return [];
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

    private function matchesCondition(array $row, string $condition, mixed $value): bool
    {
        if (str_contains($condition, 'IS NOT NULL')) {
            $column = trim(str_replace('IS NOT NULL', '', $condition));
            return ($row[$column] ?? null) !== null;
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

class PruneFakeResourceConnection extends ResourceConnection
{
    public function __construct(private readonly PruneFakeConnection $connection)
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
