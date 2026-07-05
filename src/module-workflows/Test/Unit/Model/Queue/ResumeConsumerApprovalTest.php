<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Queue;

use Magento\Framework\App\ResourceConnection;
use MageOS\Workflows\Api\ApprovalTaskManagerInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\WorkflowExecutionRepositoryInterface;
use MageOS\Workflows\Model\Engine\Executor;
use MageOS\Workflows\Model\Queue\ResumeConsumer;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * ResumeConsumer routing for the schema-4 approval gate. Routing keys off the
 * parked step's TYPE from the definition snapshot: approval decisions route
 * on_approved / on_rejected / on_timeout (and expire the task on timeout),
 * while wait steps keep routing exactly as before.
 */
class ResumeConsumerApprovalTest extends TestCase
{
    private FakeExpiringTaskManager $tasks;
    private WorkflowExecutionStub $execution;

    public function setUp(): void
    {
        $this->tasks = new FakeExpiringTaskManager();
    }

    private function definition(): string
    {
        return (string) json_encode([
            'schema' => 4,
            'entry' => 'gate',
            'steps' => [
                'gate' => [
                    'type' => 'approval',
                    'config' => ['title' => 'Approve', 'timeout' => 'P1D'],
                    'on_approved' => 'issue_credit',
                    'on_rejected' => 'policy_email',
                    'on_timeout' => 'escalate',
                ],
                'issue_credit' => ['type' => 'stop'],
                'policy_email' => ['type' => 'stop'],
                'escalate' => ['type' => 'stop'],
                'waitstep' => [
                    'type' => 'wait',
                    'config' => ['event' => 'sales.order.updated', 'timeout' => 'PT1H'],
                    'on_event' => 'issue_credit',
                    'on_timeout' => 'escalate',
                ],
            ],
        ]);
    }

    private function consumer(string $parkedStepKey, ?string $parkedResult): ResumeConsumer
    {
        $this->execution = new WorkflowExecutionStub('exec-uuid', 7, 1);
        $this->execution->setExecutionId(202);
        $this->execution->setDefinitionSnapshot($this->definition());
        $this->execution->setStatus(WorkflowExecutionInterface::STATUS_WAITING);
        $this->execution->setCurrentStep($parkedStepKey);
        $this->execution->setContext((string) json_encode(['trigger' => [], 'steps' => [], 'workflow' => []]));

        $repository = new class ($this) implements WorkflowExecutionRepositoryInterface {
            public function __construct(private readonly ResumeConsumerApprovalTest $test)
            {
            }

            public function save(WorkflowExecutionInterface $execution): WorkflowExecutionInterface
            {
                return $execution;
            }

            public function getById(int $executionId): WorkflowExecutionInterface
            {
                return $this->test->currentExecution();
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

        // Executor subclass that skips construction and does nothing on resume;
        // this test isolates the routing that runs before the walk continues.
        $executor = new class extends Executor {
            public function __construct()
            {
            }

            public function execute(int $executionId): void
            {
            }
        };

        return new ResumeConsumer(
            $repository,
            $executor,
            $this->resource($parkedStepKey, $parkedResult),
            new NullLogger(),
            $this->tasks
        );
    }

    public function currentExecution(): WorkflowExecutionStub
    {
        return $this->execution;
    }

    private function resource(string $stepKey, ?string $result): ResourceConnection
    {
        $row = ['step_key' => $stepKey, 'result' => $result];
        return new class ($row) extends ResourceConnection {
            public function __construct(private readonly array $row)
            {
            }

            public function getConnection(string $resourceName = self::DEFAULT_CONNECTION)
            {
                return new class ($this->row) {
                    public function __construct(private readonly array $row)
                    {
                    }

                    public function select(): object
                    {
                        return new class {
                            public function from($t, $c = '*'): self
                            {
                                return $this;
                            }

                            public function where($c, $v = null): self
                            {
                                return $this;
                            }

                            public function limit($n, $o = 0): self
                            {
                                return $this;
                            }
                        };
                    }

                    public function fetchRow($select)
                    {
                        return $this->row;
                    }

                    public function update($table, array $bind, $where = '')
                    {
                        return 1;
                    }
                };
            }

            public function getTableName($modelEntity, string $connectionName = self::DEFAULT_CONNECTION)
            {
                return (string) $modelEntity;
            }
        };
    }

    private function stepOutput(): array
    {
        $context = json_decode((string) $this->execution->getContext(), true);
        return $context['steps']['gate'] ?? [];
    }

    public function testApprovedRoutesToOnApprovedWithFullOutput(): void
    {
        $result = (string) json_encode([
            'resolution' => 'approved',
            'note' => 'Looks good',
            'payload' => ['approved_amount' => 100],
            'decided_by' => 'admin:5',
        ]);
        $this->consumer('gate', $result)->process('202');

        $this->assertSame('issue_credit', $this->execution->getCurrentStep());
        $this->assertNull($this->execution->getWaitingEvent());
        $this->assertSame([
            'resolution' => 'approved',
            'note' => 'Looks good',
            'payload' => ['approved_amount' => 100],
            'decided_by' => 'admin:5',
        ], $this->stepOutput());
        $this->assertSame([], $this->tasks->expired);
    }

    public function testRejectedRoutesToOnRejected(): void
    {
        $result = (string) json_encode(['resolution' => 'rejected', 'decided_by' => 'admin:5']);
        $this->consumer('gate', $result)->process('202');

        $this->assertSame('policy_email', $this->execution->getCurrentStep());
        $this->assertSame(['resolution' => 'rejected', 'decided_by' => 'admin:5'], $this->stepOutput());
        $this->assertSame([], $this->tasks->expired);
    }

    public function testTimeoutRoutesToOnTimeoutAndExpiresTask(): void
    {
        // No result written: the sweeper woke it, the timeout won.
        $this->consumer('gate', null)->process('202');

        $this->assertSame('escalate', $this->execution->getCurrentStep());
        $this->assertSame(['resolution' => 'timeout'], $this->stepOutput());
        $this->assertSame([[202, 'gate']], $this->tasks->expired);
    }

    public function testUnrecognizedResolutionFallsToTimeout(): void
    {
        $result = (string) json_encode(['resolution' => 'maybe']);
        $this->consumer('gate', $result)->process('202');

        $this->assertSame('escalate', $this->execution->getCurrentStep());
        $this->assertSame(['resolution' => 'timeout'], $this->stepOutput());
        $this->assertSame([[202, 'gate']], $this->tasks->expired);
    }

    public function testThrowingExpireTaskStillRoutesToOnTimeout(): void
    {
        // Expiry marking is best-effort: the parked step row is already closed
        // when expireTask runs, so an escaping exception would redeliver into a
        // routing loop (the gate re-parks). The routing must complete anyway;
        // the addon's reconciliation sweep catches the still-open task.
        $this->tasks->throwOnExpire = true;
        $this->consumer('gate', null)->process('202');

        $this->assertSame('escalate', $this->execution->getCurrentStep());
        $this->assertSame(['resolution' => 'timeout'], $this->stepOutput());
    }

    public function testWaitRoutingIsUntouchedAndNeverExpiresTasks(): void
    {
        $result = (string) json_encode(['resolution' => 'event', 'event' => ['order_id' => 9]]);
        $this->consumer('waitstep', $result)->process('202');

        $this->assertSame('issue_credit', $this->execution->getCurrentStep());
        $this->assertNull($this->execution->getWaitingEvent());
        // No approval-task side effects for a wait park.
        $this->assertSame([], $this->tasks->expired);
    }
}

/**
 * Records expireTask calls; create/orphan are unused by these routing tests.
 */
class FakeExpiringTaskManager implements ApprovalTaskManagerInterface
{
    /** @var array<int, array{0: int, 1: string}> */
    public array $expired = [];

    public bool $throwOnExpire = false;

    public function createTask(
        WorkflowExecutionInterface $execution,
        string $stepKey,
        string $title,
        string $instructions,
        string $dueAt,
        ?string $assigneeRole
    ): string {
        return 'unused';
    }

    public function expireTask(int $executionId, string $stepKey): void
    {
        if ($this->throwOnExpire) {
            throw new \RuntimeException('DB gone away');
        }
        $this->expired[] = [$executionId, $stepKey];
    }

    public function orphanTasks(int $executionId): void
    {
    }
}
