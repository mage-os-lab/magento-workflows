<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Engine;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Notification\NotifierInterface;
use MageOS\Workflows\Api\ApprovalTaskManagerInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowExecutionRepositoryInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\Action\ActionPool;
use MageOS\Workflows\Model\Engine\CircuitBreaker;
use MageOS\Workflows\Model\Engine\DelayCalculator;
use MageOS\Workflows\Model\Engine\Executor;
use MageOS\Workflows\Model\Variable\VariableResolver;
use MageOS\Workflows\Test\Unit\Stub\SecretsProviderStub;
use MageOS\Workflows\Test\Unit\Stub\StubConditionEvaluator;
use MageOS\Workflows\Test\Unit\Stub\StubScopeConfig;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * Executor park behavior for the schema-4 approval gate. Drives execute()
 * end-to-end over an in-memory execution + capturing connection double, so the
 * private runApprovalStep is exercised through the same walk production takes.
 * Parks like a wait (step row waiting + resume_at, execution waiting) but with
 * current_step ON the gate and waiting_event null, delegating the task record
 * through the ApprovalTaskManagerInterface seam.
 */
class ExecutorApprovalTest extends TestCase
{
    private FakeApprovalTaskManager $tasks;
    private CapturingConnection $connection;
    private WorkflowExecutionStub $execution;

    public function setUp(): void
    {
        $this->tasks = new FakeApprovalTaskManager();
        $this->connection = new CapturingConnection();
    }

    private function approvalDefinition(): string
    {
        return (string) json_encode([
            'schema' => 4,
            'entry' => 'gate',
            'steps' => [
                'gate' => [
                    'type' => 'approval',
                    'config' => [
                        'title' => 'Approve goodwill credit for order {{ trigger.increment_id }}',
                        'instructions' => 'Total is {{ trigger.grand_total }}.',
                        'timeout' => 'P3D',
                        'assignee_role' => 'sales_managers',
                        'payload_fields' => [
                            ['key' => 'approved_amount', 'label' => 'Amount', 'type' => 'number'],
                        ],
                    ],
                    'on_approved' => 'done',
                    'on_rejected' => 'done',
                    'on_timeout' => 'done',
                ],
                'done' => ['type' => 'stop'],
            ],
        ]);
    }

    private function buildExecutor(?ApprovalTaskManagerInterface $tasks): Executor
    {
        $repository = new class ($this) implements WorkflowExecutionRepositoryInterface {
            public function __construct(private readonly ExecutorApprovalTest $test)
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

        // Workflow deleted mid-flight path: loadWorkflow() swallows the miss and
        // returns null, so the root-condition gate is skipped (no ConditionEvaluator
        // call). The pinned snapshot still executes.
        $workflowRepository = new class implements WorkflowRepositoryInterface {
            public function save(WorkflowInterface $workflow): WorkflowInterface
            {
                return $workflow;
            }

            public function getById(int $workflowId): WorkflowInterface
            {
                throw new NoSuchEntityException();
            }

            public function getList(
                \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
            ): \Magento\Framework\Api\SearchResultsInterface {
                throw new \RuntimeException('not used');
            }

            public function delete(WorkflowInterface $workflow): bool
            {
                return true;
            }

            public function deleteById(int $workflowId): bool
            {
                return true;
            }
        };

        return new Executor(
            $repository,
            $workflowRepository,
            new StubConditionEvaluator([]),
            new ActionPool([]),
            new VariableResolver(new SecretsProviderStub()),
            $this->circuitBreaker($workflowRepository),
            $this->connection->asResource(),
            $this->eventManager(),
            new NullLogger(),
            new DelayCalculator(),
            new StubScopeConfig(['general/locale/timezone' => 'UTC']),
            null,
            $tasks
        );
    }

    public function currentExecution(): WorkflowExecutionStub
    {
        return $this->execution;
    }

    private function circuitBreaker(WorkflowRepositoryInterface $workflowRepository): CircuitBreaker
    {
        // Never tripped by the approval paths under test; construct with inert doubles.
        $cache = new class implements CacheInterface {
            public function load($identifier)
            {
                return false;
            }

            public function save($data, $identifier, $tags = [], $lifeTime = null)
            {
                return true;
            }

            public function remove($identifier)
            {
                return true;
            }

            public function getFrontend()
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function clean($tags = [])
            {
                throw new \BadMethodCallException(__METHOD__);
            }
        };
        $notifier = new class implements NotifierInterface {
            public function add($severity, $title, $description, $url = '', $isInternal = true)
            {
            }

            public function addCritical($title, $description, $url = '', $isInternal = true)
            {
            }

            public function addMajor($title, $description, $url = '', $isInternal = true)
            {
            }

            public function addMinor($title, $description, $url = '', $isInternal = true)
            {
            }

            public function addNotice($title, $description, $url = '', $isInternal = true)
            {
            }

            public function remove($notificationId)
            {
            }

            public function markAsRead($notificationId)
            {
            }
        };

        return new CircuitBreaker($cache, new StubScopeConfig(), $workflowRepository, $notifier, new NullLogger());
    }

    private function eventManager(): EventManagerInterface
    {
        return new class implements EventManagerInterface {
            public function dispatch($eventName, array $data = [])
            {
            }
        };
    }

    private function newExecution(string $definition, ?string $context = null): WorkflowExecutionStub
    {
        $execution = new WorkflowExecutionStub('exec-uuid', 7, 1);
        $execution->setExecutionId(101);
        $execution->setWorkflowId(42);
        $execution->setDefinitionSnapshot($definition);
        $execution->setStatus(WorkflowExecutionInterface::STATUS_PENDING);
        $execution->setContext($context ?? (string) json_encode([
            'trigger' => ['increment_id' => '000000123', 'grand_total' => '49.99'],
            'steps' => [],
            'workflow' => [],
        ]));
        return $execution;
    }

    public function testApprovalStepParksLikeWaitWithTaskCreated(): void
    {
        $this->execution = $this->newExecution($this->approvalDefinition());
        $this->buildExecutor($this->tasks)->execute(101);

        // Execution parked ON the gate, not past it, and with no waiting_event.
        $this->assertSame(WorkflowExecutionInterface::STATUS_WAITING, $this->execution->getStatus());
        $this->assertSame('gate', $this->execution->getCurrentStep());
        $this->assertNull($this->execution->getWaitingEvent());

        // The step row went waiting with a resume_at.
        $stepRow = $this->connection->insertedStepRow('gate');
        $this->assertNotNull($stepRow);
        $this->assertSame(WorkflowExecutionStepInterface::STATUS_WAITING, $stepRow['status']);
        $this->assertTrue((string) ($stepRow['resume_at'] ?? '') !== '');

        // Exactly one task, title interpolated at park time, due_at == resume_at.
        $this->assertCount(1, $this->tasks->created);
        $task = $this->tasks->created[0];
        $this->assertSame('Approve goodwill credit for order 000000123', $task['title']);
        $this->assertSame('Total is 49.99.', $task['instructions']);
        $this->assertSame('sales_managers', $task['assigneeRole']);
        $this->assertSame($stepRow['resume_at'], $task['dueAt']);
    }

    public function testTaskUuidInjectedIntoStepOutput(): void
    {
        $this->execution = $this->newExecution($this->approvalDefinition());
        $this->buildExecutor($this->tasks)->execute(101);

        $context = json_decode((string) $this->execution->getContext(), true);
        $this->assertSame(
            $this->tasks->created[0]['uuid'],
            $context['steps']['gate']['task_uuid'] ?? null
        );
    }

    public function testRedeliveryBeforePersistReattachesToSameTask(): void
    {
        $this->execution = $this->newExecution($this->approvalDefinition());
        $this->buildExecutor($this->tasks)->execute(101);
        $firstUuid = $this->tasks->created[0]['uuid'];

        // Simulate a crash before the waiting state persisted: the execution is
        // still RUNNING at current_step 'gate' when the message redelivers.
        $this->execution->setStatus(WorkflowExecutionInterface::STATUS_RUNNING);
        $this->execution->setCurrentStep('gate');
        $this->buildExecutor($this->tasks)->execute(101);

        // Idempotent on (execution_id, step_key): still one task, same uuid.
        $this->assertCount(1, $this->tasks->created);
        $context = json_decode((string) $this->execution->getContext(), true);
        $this->assertSame($firstUuid, $context['steps']['gate']['task_uuid']);
    }

    public function testNoManagerBoundFailsTerminally(): void
    {
        $this->execution = $this->newExecution($this->approvalDefinition());
        $this->buildExecutor(null)->execute(101);

        $this->assertSame(WorkflowExecutionInterface::STATUS_FAILED, $this->execution->getStatus());
        $this->assertSame([], $this->tasks->created);
        $stepRow = $this->connection->updatedOrInsertedStepRow('gate');
        $this->assertSame(WorkflowExecutionStepInterface::STATUS_FAILED, $stepRow['status'] ?? null);
    }

    public function testFailExecutionOrphansOpenTasks(): void
    {
        // An invalid snapshot fails the execution before any step runs; with a
        // manager bound, failExecution must orphan its tasks.
        $this->execution = $this->newExecution('{not valid json');
        $this->buildExecutor($this->tasks)->execute(101);

        $this->assertSame(WorkflowExecutionInterface::STATUS_FAILED, $this->execution->getStatus());
        $this->assertSame([101], $this->tasks->orphaned);
    }
}

/**
 * Records task lifecycle calls; createTask is idempotent on (execution_id,
 * step_key) so a re-park after redelivery returns the existing task's uuid.
 */
class FakeApprovalTaskManager implements ApprovalTaskManagerInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $created = [];
    /** @var array<int, array{0: int, 1: string}> */
    public array $expired = [];
    /** @var int[] */
    public array $orphaned = [];

    /** @var array<string, string> */
    private array $openByPair = [];
    private int $seq = 0;

    public function createTask(
        WorkflowExecutionInterface $execution,
        string $stepKey,
        string $title,
        string $instructions,
        string $dueAt,
        ?string $assigneeRole
    ): string {
        $pair = ((int) $execution->getExecutionId()) . ':' . $stepKey;
        if (isset($this->openByPair[$pair])) {
            return $this->openByPair[$pair];
        }
        $uuid = 'task-' . (++$this->seq);
        $this->openByPair[$pair] = $uuid;
        $this->created[] = [
            'executionId' => (int) $execution->getExecutionId(),
            'stepKey' => $stepKey,
            'title' => $title,
            'instructions' => $instructions,
            'dueAt' => $dueAt,
            'assigneeRole' => $assigneeRole,
            'uuid' => $uuid,
        ];
        return $uuid;
    }

    public function expireTask(int $executionId, string $stepKey): void
    {
        $this->expired[] = [$executionId, $stepKey];
    }

    public function orphanTasks(int $executionId): void
    {
        $this->orphaned[] = $executionId;
    }
}

/**
 * In-memory stand-in for the DB adapter: step-row writes arrive as the
 * executor's insertOnDuplicate upsert (unique key on (execution_id, step_key))
 * and are captured — bind and update-column list — alongside plain updates.
 */
class CapturingConnection
{
    /** @var array<int, array{table: string, bind: array, update_columns: string[]}> */
    public array $inserts = [];
    /** @var array<int, array{table: string, bind: array, where: mixed}> */
    public array $updates = [];

    public function asResource(): ResourceConnection
    {
        $self = $this;
        return new class ($self) extends ResourceConnection {
            public function __construct(private readonly CapturingConnection $capture)
            {
            }

            public function getConnection($resourceName = self::DEFAULT_CONNECTION)
            {
                return $this->capture->adapter();
            }

            public function getTableName($modelEntity, $connectionName = self::DEFAULT_CONNECTION)
            {
                return (string) $modelEntity;
            }
        };
    }

    public function adapter(): object
    {
        $capture = $this;
        return new class ($capture) {
            public function __construct(private readonly CapturingConnection $capture)
            {
            }

            public function select(): object
            {
                return new class {
                    public function from($table, $cols = '*'): self
                    {
                        return $this;
                    }

                    public function where($cond, $value = null): self
                    {
                        return $this;
                    }

                    public function limit($count, $offset = 0): self
                    {
                        return $this;
                    }
                };
            }

            public function fetchOne($select)
            {
                return false;
            }

            public function fetchRow($select)
            {
                return false;
            }

            public function insert($table, array $bind)
            {
                $this->capture->inserts[] = ['table' => $table, 'bind' => $bind, 'update_columns' => []];
                return 1;
            }

            /**
             * The step-row upsert against the (execution_id, step_key) unique
             * key; $fields is the exact column set it may overwrite.
             */
            public function insertOnDuplicate($table, array $bind, array $fields = [])
            {
                $this->capture->inserts[] = ['table' => $table, 'bind' => $bind, 'update_columns' => $fields];
                return 1;
            }

            public function update($table, array $bind, $where = '')
            {
                $this->capture->updates[] = ['table' => $table, 'bind' => $bind, 'where' => $where];
                return 1;
            }
        };
    }

    /**
     * @return array<string, mixed>|null the last INSERT bind for a step row
     */
    public function insertedStepRow(string $stepKey): ?array
    {
        $found = null;
        foreach ($this->inserts as $row) {
            if (($row['bind']['step_key'] ?? null) === $stepKey) {
                $found = $row['bind'];
            }
        }
        return $found;
    }

    /**
     * @return array<string, mixed> merged view of the last insert then updates
     *         for a step row (the fake never updates step rows since fetchOne
     *         returns false, but failStep/upsert may re-insert)
     */
    public function updatedOrInsertedStepRow(string $stepKey): array
    {
        $merged = [];
        foreach ($this->inserts as $row) {
            if (($row['bind']['step_key'] ?? null) === $stepKey) {
                $merged = array_merge($merged, $row['bind']);
            }
        }
        return $merged;
    }
}
