<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Queue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\WorkflowExecutionRepositoryInterface;
use MageOS\Workflows\Model\Engine\Executor;
use MageOS\Workflows\Model\Queue\ResumeConsumer;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * ResumeConsumer's delay-resume path (docs/08-execution-model.md): a delay
 * park stores current_step = the step AFTER the delay, so waking it needs no
 * routing — the parked step row is closed, the execution flips to running
 * (root conditions are first-run-only and must NOT re-fire), and the walk
 * continues from current_step via the Executor. Terminal executions and
 * missing executions drop the message; retryable executor failures rethrow
 * for queue redelivery.
 */
class ResumeConsumerDelayTest extends TestCase
{
    private const EXECUTION_ID = 202;

    private WorkflowExecutionStub $execution;

    private DelayFakeConnection $connection;

    private DelayRecordingExecutor $executor;

    /** @var object repository fake exposing $saved */
    private object $repository;

    public function setUp(): void
    {
        $this->connection = new DelayFakeConnection();
        $this->executor = new DelayRecordingExecutor();
    }

    /**
     * Snapshot with a delay parked between entry and a final step: the
     * Executor already advanced current_step past the delay when it parked.
     */
    private function definition(): string
    {
        return (string) json_encode([
            'schema' => 1,
            'entry' => 'pause',
            'steps' => [
                'pause' => [
                    'type' => 'delay',
                    'config' => ['duration' => 'PT1H'],
                    'next' => 'finish',
                ],
                'finish' => ['type' => 'stop'],
            ],
        ]);
    }

    private function consumer(?WorkflowExecutionStub $execution = null): ResumeConsumer
    {
        if ($execution === null) {
            $execution = new WorkflowExecutionStub('delay-uuid', 7, 1);
            $execution->setExecutionId(self::EXECUTION_ID);
            $execution->setDefinitionSnapshot($this->definition());
            $execution->setStatus(WorkflowExecutionInterface::STATUS_WAITING);
            $execution->setCurrentStep('finish'); // set past the delay at park time
            $execution->setContext((string) json_encode(['trigger' => [], 'steps' => [], 'workflow' => []]));
        }
        $this->execution = $execution;

        // The parked step row the consumer snapshots before closing it: a
        // delay park carries no result payload.
        $this->connection->parkedRow = ['step_key' => 'pause', 'result' => null];

        $this->repository = new class ($this->execution) implements WorkflowExecutionRepositoryInterface {
            /** @var WorkflowExecutionInterface[] */
            public array $saved = [];

            public bool $throwNoSuchEntity = false;

            public function __construct(private readonly WorkflowExecutionInterface $execution)
            {
            }

            public function save(WorkflowExecutionInterface $execution): WorkflowExecutionInterface
            {
                $this->saved[] = $execution;
                return $execution;
            }

            public function getById(int $executionId): WorkflowExecutionInterface
            {
                if ($this->throwNoSuchEntity) {
                    throw new NoSuchEntityException();
                }
                return $this->execution;
            }

            public function getByUuid(string $uuid): WorkflowExecutionInterface
            {
                throw new \RuntimeException('not used');
            }

            public function getList(
                \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
            ): \Magento\Framework\Api\SearchResultsInterface {
                throw new \RuntimeException('not used');
            }
        };

        return new ResumeConsumer(
            $this->repository,
            $this->executor,
            new DelayFakeResourceConnection($this->connection),
            new NullLogger()
        );
    }

    public function testDelayResumeContinuesTheWalkFromTheStepAfterTheDelay(): void
    {
        $this->consumer()->process((string) self::EXECUTION_ID);

        // No routing for a delay park: current_step (already past the delay)
        // is untouched and the walk continues exactly there.
        $this->assertSame('finish', $this->execution->getCurrentStep());
        $this->assertSame([self::EXECUTION_ID], $this->executor->executed);
    }

    public function testDelayResumeFlipsTheExecutionToRunningBeforeTheWalk(): void
    {
        // running (not pending): the root-condition gate is first-run-only
        // and must not re-fire after a delay (docs/08).
        $this->consumer()->process((string) self::EXECUTION_ID);

        $this->assertSame(WorkflowExecutionInterface::STATUS_RUNNING, $this->execution->getStatus());
        $this->assertCount(1, $this->repository->saved);
    }

    public function testDelayResumeClosesTheParkedStepRow(): void
    {
        $this->consumer()->process((string) self::EXECUTION_ID);

        $this->assertCount(1, $this->connection->updates);
        $update = $this->connection->updates[0];
        $this->assertSame('complete', $update['bind']['status']);
        $this->assertArrayHasKey('finished_at', $update['bind']);
        // Guarded on the waiting status so a redelivered resume is a no-op.
        $this->assertSame('waiting', $update['where']['status = ?'] ?? null);
    }

    public function testDelayResumeInjectsNoStepOutputForTheDelay(): void
    {
        // Only wait/approval parks carry a resolution; a delay park must not
        // grow a steps.<key> output.
        $this->consumer()->process((string) self::EXECUTION_ID);

        $context = json_decode((string) $this->execution->getContext(), true);
        $steps = is_array($context) ? ($context['steps'] ?? []) : [];
        $this->assertFalse(isset($steps['pause']));
    }

    public function testTerminalExecutionDropsTheMessageWithoutExecuting(): void
    {
        $execution = new WorkflowExecutionStub('delay-uuid', 7, 1);
        $execution->setExecutionId(self::EXECUTION_ID);
        $execution->setDefinitionSnapshot($this->definition());
        $execution->setStatus(WorkflowExecutionInterface::STATUS_COMPLETE);

        $this->consumer($execution)->process((string) self::EXECUTION_ID);

        $this->assertCount(0, $this->executor->executed);
        $this->assertCount(0, $this->connection->updates);
    }

    public function testMissingExecutionDropsTheMessageInsteadOfRetrying(): void
    {
        $consumer = $this->consumer();
        $this->repository->throwNoSuchEntity = true;

        // Must not throw: a vanished execution is a warning + ack, not an
        // endless redelivery loop.
        $consumer->process((string) self::EXECUTION_ID);

        $this->assertCount(0, $this->executor->executed);
    }

    public function testRetryableExecutorFailureRethrowsForRedelivery(): void
    {
        $this->executor->throwOnExecute = new \RuntimeException('SMTP connection refused');
        $consumer = $this->consumer();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SMTP connection refused');
        $consumer->process((string) self::EXECUTION_ID);
    }
}

/**
 * Executor stand-in that records execute() calls (and optionally fails) —
 * these tests isolate the consumer's park handling from the walk itself.
 */
class DelayRecordingExecutor extends Executor
{
    /** @var int[] */
    public array $executed = [];

    public ?\Throwable $throwOnExecute = null;

    public function __construct()
    {
    }

    public function execute(int $executionId): void
    {
        if ($this->throwOnExecute !== null) {
            throw $this->throwOnExecute;
        }
        $this->executed[] = $executionId;
    }
}

/**
 * Step-table fake: fetchRow returns the parked row snapshot; update calls
 * (the close-out of the waiting row) are recorded.
 */
class DelayFakeConnection
{
    /** @var array<string, mixed>|false */
    public array|false $parkedRow = false;

    /** @var array<int, array{table: string, bind: array, where: array}> */
    public array $updates = [];

    public function select(): object
    {
        return new class {
            public function from($table, $columns = '*'): self
            {
                return $this;
            }

            public function where($condition, $value = null): self
            {
                return $this;
            }

            public function order($spec): self
            {
                return $this;
            }

            public function limit($count, $offset = 0): self
            {
                return $this;
            }
        };
    }

    public function fetchRow($select)
    {
        return $this->parkedRow;
    }

    public function update($table, array $bind, $where = ''): int
    {
        $this->updates[] = [
            'table' => (string) $table,
            'bind' => $bind,
            'where' => is_array($where) ? $where : [],
        ];
        return 1;
    }
}

class DelayFakeResourceConnection extends ResourceConnection
{
    public function __construct(private readonly DelayFakeConnection $connection)
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
