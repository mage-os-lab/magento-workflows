<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Engine;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Notification\NotifierInterface;
use MageOS\Workflows\Api\ActionInterface;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\WorkflowExecutionRepositoryInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\Action\ActionPool;
use MageOS\Workflows\Model\Action\ActionResult;
use MageOS\Workflows\Model\Engine\CircuitBreaker;
use MageOS\Workflows\Model\Engine\DelayCalculator;
use MageOS\Workflows\Model\Engine\Executor;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Model\Rule\ConditionEvaluator;
use MageOS\Workflows\Model\Variable\VariableResolver;
use MageOS\Workflows\Test\Unit\Stub\SecretsProviderStub;
use MageOS\Workflows\Test\Unit\Stub\StubScopeConfig;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\Workflows\Test\Unit\Stub\WorkflowStub;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * Behavior-driven pins for the production graph walker's non-approval paths
 * (docs/08-execution-model.md). Drives Executor::execute() end-to-end over
 * in-memory fakes: root-condition gating, persist-before-side-effect crash
 * safety, terminal vs retryable action failures, delay parking, branch and
 * switch routing, and the MAX_STEPS_PER_RUN backstop.
 *
 * Every DB write and every action side effect is appended to one shared
 * WalkRecorder event log so persistence-vs-side-effect ORDERING is a direct
 * assertion, not an inference.
 */
class ExecutorWalkTest extends TestCase
{
    private WalkRecorder $recorder;
    private RecordingConnection $connection;
    private RecordingEventManager $events;
    private WorkflowExecutionStub $execution;

    /** @var array<string, RecordingAction> */
    private array $actions = [];

    public function setUp(): void
    {
        $this->recorder = new WalkRecorder();
        $this->connection = new RecordingConnection($this->recorder);
        $this->events = new RecordingEventManager();
        $this->actions = [];
    }

    // ------------------------------------------------------------------
    // 1. Root conditions false on first run => skipped, no side effects
    // ------------------------------------------------------------------

    public function testRootConditionsFalseSkipsExecutionWithoutRunningAnySteps(): void
    {
        $this->execution = $this->newExecution($this->chainDefinition());
        $conditions = new FakeWalkConditionEvaluator(false);

        $this->buildExecutor($conditions)->execute(101);

        $this->assertSame(WorkflowExecutionInterface::STATUS_SKIPPED, $this->execution->getStatus());
        // Root conditions were consulted exactly once, against this workflow.
        $this->assertSame([42], $conditions->rootCalls);
        // NO step rows were written and NO action side effect ran.
        $this->assertSame([], $this->connection->inserts);
        $this->assertSame(0, $this->actions['act.first']->runs);
        $this->assertSame(0, $this->actions['act.second']->runs);
        // The execution still reached a terminal state: completed_at stamped.
        $this->assertNotNull($this->connection->lastExecutionTableUpdate());
    }

    // ------------------------------------------------------------------
    // 2. Root conditions true => graph order + persist-before-execute
    // ------------------------------------------------------------------

    public function testRootConditionsTrueRunsStepsInGraphOrderToCompletion(): void
    {
        $this->execution = $this->newExecution($this->chainDefinition());

        $this->buildExecutor(new FakeWalkConditionEvaluator(true))->execute(101);

        $this->assertSame(WorkflowExecutionInterface::STATUS_COMPLETE, $this->execution->getStatus());
        $this->assertNull($this->execution->getCurrentStep());
        $this->assertSame(1, $this->actions['act.first']->runs);
        $this->assertSame(1, $this->actions['act.second']->runs);
        // Graph order: a1's side effect strictly before a2's.
        $this->assertTrue(
            $this->recorder->indexOf('action:act.first') < $this->recorder->indexOf('action:act.second')
        );
        // Each action's step row reached complete.
        $this->assertSame(
            WorkflowExecutionStepInterface::STATUS_COMPLETE,
            $this->connection->lastStepRow('a1')['status'] ?? null
        );
        $this->assertSame(
            WorkflowExecutionStepInterface::STATUS_COMPLETE,
            $this->connection->lastStepRow('a2')['status'] ?? null
        );
        // Step output persisted into the execution context bag.
        $context = json_decode((string) $this->execution->getContext(), true);
        $this->assertSame('act.first', $context['steps']['a1']['ran'] ?? null);
        $this->assertSame('act.second', $context['steps']['a2']['ran'] ?? null);
        // Completion event dispatched.
        $this->assertNotNull($this->events->last('workflow_execution_complete'));
    }

    public function testStepRowIsPersistedBeforeTheActionSideEffectRuns(): void
    {
        $this->execution = $this->newExecution($this->chainDefinition());

        $this->buildExecutor(new FakeWalkConditionEvaluator(true))->execute(101);

        // Crash safety (docs/08 "Crash safety and delivery semantics"): state
        // is in the DB BEFORE any side effect. The running claim row for a1
        // must precede a1's side effect, for every action step.
        $this->assertTrue(
            $this->recorder->indexOf('step_row:a1:running') < $this->recorder->indexOf('action:act.first')
        );
        $this->assertTrue(
            $this->recorder->indexOf('step_row:a2:running') < $this->recorder->indexOf('action:act.second')
        );
        // The running claim carries claim/start timestamps for zombie sweeps.
        $runningRow = $this->connection->stepRows('a1')[0];
        $this->assertSame(WorkflowExecutionStepInterface::STATUS_RUNNING, $runningRow['status']);
        $this->assertNotNull($runningRow['claimed_at'] ?? null);
        $this->assertNotNull($runningRow['started_at'] ?? null);
    }

    // ------------------------------------------------------------------
    // 3. Terminal action failure => walk stops, execution failed
    // ------------------------------------------------------------------

    public function testTerminalActionFailureStopsWalkAndFailsExecution(): void
    {
        $this->execution = $this->newExecution($this->chainDefinition(
            static fn (): ActionResultInterface => ActionResult::failure('boom', false)
        ));

        $this->buildExecutor(new FakeWalkConditionEvaluator(true))->execute(101);

        $this->assertSame(WorkflowExecutionInterface::STATUS_FAILED, $this->execution->getStatus());
        // The failing step ran once; NOTHING after it ran.
        $this->assertSame(1, $this->actions['act.first']->runs);
        $this->assertSame(0, $this->actions['act.second']->runs);
        // Step row marked failed with the error.
        $row = $this->connection->lastStepRow('a1');
        $this->assertSame(WorkflowExecutionStepInterface::STATUS_FAILED, $row['status'] ?? null);
        $this->assertSame('boom', $row['error'] ?? null);
        // Failure event carries the error and the step key.
        $failed = $this->events->last('workflow_execution_failed');
        $this->assertNotNull($failed);
        $this->assertSame('boom', $failed['error']);
        $this->assertSame('a1', $failed['step_key']);
    }

    // ------------------------------------------------------------------
    // 4. Retryable failures rethrow for queue redelivery
    // ------------------------------------------------------------------

    public function testRetryableFailureResultRethrowsAndLeavesStepReRunnable(): void
    {
        $this->execution = $this->newExecution($this->chainDefinition(
            static fn (): ActionResultInterface => ActionResult::failure('flaky upstream', true)
        ));

        $caught = null;
        try {
            $this->buildExecutor(new FakeWalkConditionEvaluator(true))->execute(101);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        // The exception escapes so the queue redelivers.
        $this->assertNotNull($caught);
        $this->assertStringContainsString('Retryable failure in step "a1"', $caught->getMessage());
        $this->assertStringContainsString('flaky upstream', $caught->getMessage());
        // Execution is NOT terminally failed — redelivery resumes it.
        $this->assertSame(WorkflowExecutionInterface::STATUS_RUNNING, $this->execution->getStatus());
        $this->assertSame('a1', $this->execution->getCurrentStep());
        $this->assertNull($this->events->last('workflow_execution_failed'));
        // Step row parked back to a re-runnable state with the error recorded.
        $row = $this->connection->lastStepRow('a1');
        $this->assertSame(WorkflowExecutionStepInterface::STATUS_PENDING, $row['status'] ?? null);
        $this->assertSame('flaky upstream', $row['error'] ?? null);
        // Nothing after the failing step ran.
        $this->assertSame(0, $this->actions['act.second']->runs);
    }

    public function testUncaughtActionExceptionIsRetryableAndRethrownVerbatim(): void
    {
        $this->execution = $this->newExecution($this->chainDefinition(
            static function (): ActionResultInterface {
                throw new \DomainException('kaboom');
            }
        ));

        $caught = null;
        try {
            $this->buildExecutor(new FakeWalkConditionEvaluator(true))->execute(101);
        } catch (\DomainException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertSame('kaboom', $caught->getMessage());
        $this->assertSame(WorkflowExecutionInterface::STATUS_RUNNING, $this->execution->getStatus());
        $row = $this->connection->lastStepRow('a1');
        $this->assertSame(WorkflowExecutionStepInterface::STATUS_PENDING, $row['status'] ?? null);
        $this->assertSame('kaboom', $row['error'] ?? null);
        $this->assertSame(0, $this->actions['act.second']->runs);
    }

    // ------------------------------------------------------------------
    // 5. Delay => park waiting with resume_at; nothing past it this pass
    // ------------------------------------------------------------------

    public function testDelayParksExecutionWaitingWithResumeAtAndStopsThePass(): void
    {
        $this->execution = $this->newExecution($this->delayDefinition());
        $before = time();

        $this->buildExecutor(new FakeWalkConditionEvaluator(true))->execute(101);

        // Parked: status waiting, current_step advanced PAST the delay so the
        // resume delivery walks straight into the next step.
        $this->assertSame(WorkflowExecutionInterface::STATUS_WAITING, $this->execution->getStatus());
        $this->assertSame('after', $this->execution->getCurrentStep());
        // The delay's step row went waiting with a resume_at ~now + PT1H.
        $row = $this->connection->lastStepRow('d1');
        $this->assertSame(WorkflowExecutionStepInterface::STATUS_WAITING, $row['status'] ?? null);
        $resumeAt = (string) ($row['resume_at'] ?? '');
        $this->assertTrue($resumeAt >= gmdate('Y-m-d H:i:s', $before + 3500));
        $this->assertTrue($resumeAt <= gmdate('Y-m-d H:i:s', $before + 3700));
        // The step before the delay ran; the step after it did NOT.
        $this->assertSame(1, $this->actions['act.first']->runs);
        $this->assertSame(0, $this->actions['act.after']->runs);
    }

    public function testWaitingExecutionIgnoresARedeliveredExecuteMessage(): void
    {
        $this->execution = $this->newExecution($this->delayDefinition());
        $executor = $this->buildExecutor(new FakeWalkConditionEvaluator(true));
        $executor->execute(101);
        $eventsBefore = count($this->recorder->events);

        // A redelivered execute message arriving after the delay persisted
        // must NOT walk past the delay early — only the resume path may.
        $executor->execute(101);

        $this->assertSame(WorkflowExecutionInterface::STATUS_WAITING, $this->execution->getStatus());
        $this->assertSame('after', $this->execution->getCurrentStep());
        $this->assertCount($eventsBefore, $this->recorder->events);
        $this->assertSame(0, $this->actions['act.after']->runs);
    }

    // ------------------------------------------------------------------
    // 6. Branch routing: on_true / on_false, row before edge
    // ------------------------------------------------------------------

    public function testBranchConditionTrueFollowsOnTrueEdge(): void
    {
        $this->execution = $this->newExecution($this->branchDefinition());
        $conditions = new FakeWalkConditionEvaluator(true, ['BR' => true]);

        $this->buildExecutor($conditions)->execute(101);

        $this->assertSame(WorkflowExecutionInterface::STATUS_COMPLETE, $this->execution->getStatus());
        $this->assertSame(1, $this->actions['act.true']->runs);
        $this->assertSame(0, $this->actions['act.false']->runs);
        // The branch's step row (with its boolean result) is written BEFORE
        // the chosen edge is followed — identical persistence discipline to
        // action steps (docs/08 §Switch: "the step row is written before the
        // edge is followed", which switch inherits from branch).
        $this->assertTrue(
            $this->recorder->indexOf('step_row:gate:complete') < $this->recorder->indexOf('action:act.true')
        );
        $row = $this->connection->lastStepRow('gate');
        $this->assertSame(WorkflowExecutionStepInterface::STATUS_COMPLETE, $row['status'] ?? null);
        $this->assertSame('{"result":true}', $row['result'] ?? null);
        // The branch evaluated ITS OWN serialized tree, revalidating by default.
        $this->assertCount(1, $conditions->serializedCalls);
        $this->assertSame('BR', $conditions->serializedCalls[0]['serialized']);
        $this->assertSame('sales_order', $conditions->serializedCalls[0]['entity_type']);
        $this->assertTrue($conditions->serializedCalls[0]['revalidate']);
    }

    public function testBranchConditionFalseFollowsOnFalseEdge(): void
    {
        $this->execution = $this->newExecution($this->branchDefinition(['revalidate_entity' => false]));
        $conditions = new FakeWalkConditionEvaluator(true, ['BR' => false]);

        $this->buildExecutor($conditions)->execute(101);

        $this->assertSame(WorkflowExecutionInterface::STATUS_COMPLETE, $this->execution->getStatus());
        $this->assertSame(0, $this->actions['act.true']->runs);
        $this->assertSame(1, $this->actions['act.false']->runs);
        $row = $this->connection->lastStepRow('gate');
        $this->assertSame('{"result":false}', $row['result'] ?? null);
        // revalidate_entity: false is honored (frozen-snapshot evaluation).
        $this->assertFalse($conditions->serializedCalls[0]['revalidate']);
    }

    // ------------------------------------------------------------------
    // 7. Switch (schema 3): first match wins, default, null default
    // ------------------------------------------------------------------

    public function testSwitchFirstMatchingCaseWinsTopDown(): void
    {
        $this->execution = $this->newExecution($this->switchDefinition());
        // c1 does not match; c2 and c3 both would — first match must win and
        // later cases must not even be evaluated.
        $conditions = new FakeWalkConditionEvaluator(true, ['C1' => false, 'C2' => true, 'C3' => true]);

        $this->buildExecutor($conditions)->execute(101);

        $this->assertSame(WorkflowExecutionInterface::STATUS_COMPLETE, $this->execution->getStatus());
        $this->assertSame(0, $this->actions['act.one']->runs);
        $this->assertSame(1, $this->actions['act.two']->runs);
        $this->assertSame(0, $this->actions['act.three']->runs);
        $this->assertSame(0, $this->actions['act.default']->runs);
        // Top-down evaluation stopped at the first match: C3 never evaluated.
        $evaluated = array_map(
            static fn (array $call): string => $call['serialized'],
            $conditions->serializedCalls
        );
        $this->assertSame(['C1', 'C2'], $evaluated);
        // Step result records the matched case key.
        $row = $this->connection->lastStepRow('sw');
        $this->assertSame(WorkflowExecutionStepInterface::STATUS_COMPLETE, $row['status'] ?? null);
        $this->assertSame('{"matched":"c2"}', $row['result'] ?? null);
        // Row written before the matched edge was followed.
        $this->assertTrue(
            $this->recorder->indexOf('step_row:sw:complete') < $this->recorder->indexOf('action:act.two')
        );
    }

    public function testSwitchCaseWithoutConditionsAlwaysMatches(): void
    {
        // An empty/absent conditions_serialized on a case always matches, so an
        // unconditional case short-circuits without consulting the evaluator.
        $this->registerAction('act.one');
        $this->registerAction('act.two');
        $definition = (string) json_encode([
            'schema' => 3,
            'entry' => 'sw',
            'steps' => [
                'sw' => [
                    'type' => 'switch',
                    'cases' => [
                        ['key' => 'open', 'next' => 's1'],
                        ['key' => 'other', 'conditions_serialized' => 'C2', 'next' => 's2'],
                    ],
                    'default' => null,
                ],
                's1' => ['type' => 'action', 'action' => 'act.one'],
                's2' => ['type' => 'action', 'action' => 'act.two'],
            ],
        ]);
        $this->execution = $this->newExecution($definition);
        $conditions = new FakeWalkConditionEvaluator(true, ['C2' => true]);

        $this->buildExecutor($conditions)->execute(101);

        $this->assertSame(1, $this->actions['act.one']->runs);
        $this->assertSame(0, $this->actions['act.two']->runs);
        $this->assertSame([], $conditions->serializedCalls);
        $this->assertSame('{"matched":"open"}', $this->connection->lastStepRow('sw')['result'] ?? null);
    }

    public function testSwitchWithNoMatchingCaseFollowsDefaultEdge(): void
    {
        $this->execution = $this->newExecution($this->switchDefinition());
        $conditions = new FakeWalkConditionEvaluator(true, ['C1' => false, 'C2' => false, 'C3' => false]);

        $this->buildExecutor($conditions)->execute(101);

        $this->assertSame(WorkflowExecutionInterface::STATUS_COMPLETE, $this->execution->getStatus());
        $this->assertSame(1, $this->actions['act.default']->runs);
        $this->assertSame(0, $this->actions['act.one']->runs);
        $this->assertSame(0, $this->actions['act.two']->runs);
        $this->assertSame(0, $this->actions['act.three']->runs);
        // No match => matched records null.
        $this->assertSame('{"matched":null}', $this->connection->lastStepRow('sw')['result'] ?? null);
    }

    public function testSwitchWithNoMatchAndNullDefaultEndsTheWalk(): void
    {
        $this->registerAction('act.one');
        $definition = (string) json_encode([
            'schema' => 3,
            'entry' => 'sw',
            'steps' => [
                'sw' => [
                    'type' => 'switch',
                    'cases' => [
                        ['key' => 'c1', 'conditions_serialized' => 'C1', 'next' => 's1'],
                    ],
                ],
                's1' => ['type' => 'action', 'action' => 'act.one'],
            ],
        ]);
        $this->execution = $this->newExecution($definition);
        $conditions = new FakeWalkConditionEvaluator(true, ['C1' => false]);

        $this->buildExecutor($conditions)->execute(101);

        // Null default edge ends the walk cleanly: complete, not failed.
        $this->assertSame(WorkflowExecutionInterface::STATUS_COMPLETE, $this->execution->getStatus());
        $this->assertSame(0, $this->actions['act.one']->runs);
        $this->assertSame('{"matched":null}', $this->connection->lastStepRow('sw')['result'] ?? null);
        $this->assertNotNull($this->events->last('workflow_execution_complete'));
    }

    // ------------------------------------------------------------------
    // 8. MAX_STEPS_PER_RUN backstop
    // ------------------------------------------------------------------

    public function testIterationCapFailsACyclicDefinitionInsteadOfLoopingForever(): void
    {
        // Definitions validate edges but not acyclicity (GraphCheck is save
        // time; the executor re-parses snapshots), so a cyclic snapshot must
        // hit the runtime backstop and fail rather than spin forever.
        $this->registerAction('act.ping');
        $this->registerAction('act.pong');
        $definition = (string) json_encode([
            'schema' => 1,
            'entry' => 'ping',
            'steps' => [
                'ping' => ['type' => 'action', 'action' => 'act.ping', 'next' => 'pong'],
                'pong' => ['type' => 'action', 'action' => 'act.pong', 'next' => 'ping'],
            ],
        ]);
        $this->execution = $this->newExecution($definition);

        $this->buildExecutor(new FakeWalkConditionEvaluator(true))->execute(101);

        $this->assertSame(WorkflowExecutionInterface::STATUS_FAILED, $this->execution->getStatus());
        $failed = $this->events->last('workflow_execution_failed');
        $this->assertNotNull($failed);
        $this->assertStringContainsString('iteration limit', $failed['error']);
        // The walk was bounded by the cap (docs/08: ~1000), not unbounded.
        $this->assertSame(1000, $this->actions['act.ping']->runs + $this->actions['act.pong']->runs);
    }

    // ------------------------------------------------------------------
    // Harness
    // ------------------------------------------------------------------

    /**
     * a1(act.first) -> a2(act.second) -> stop. $firstBehavior overrides
     * act.first's result to drive the failure-path tests.
     */
    private function chainDefinition(?\Closure $firstBehavior = null): string
    {
        $this->registerAction('act.first', $firstBehavior);
        $this->registerAction('act.second');
        return (string) json_encode([
            'schema' => 1,
            'entry' => 'a1',
            'steps' => [
                'a1' => ['type' => 'action', 'action' => 'act.first', 'next' => 'a2'],
                'a2' => ['type' => 'action', 'action' => 'act.second', 'next' => 'end'],
                'end' => ['type' => 'stop'],
            ],
        ]);
    }

    /**
     * a1(act.first) -> d1(delay PT1H) -> after(act.after) -> stop
     */
    private function delayDefinition(): string
    {
        $this->registerAction('act.first');
        $this->registerAction('act.after');
        return (string) json_encode([
            'schema' => 1,
            'entry' => 'a1',
            'steps' => [
                'a1' => ['type' => 'action', 'action' => 'act.first', 'next' => 'd1'],
                'd1' => ['type' => 'delay', 'config' => ['duration' => 'PT1H'], 'next' => 'after'],
                'after' => ['type' => 'action', 'action' => 'act.after', 'next' => 'end'],
                'end' => ['type' => 'stop'],
            ],
        ]);
    }

    /**
     * gate(branch on 'BR') -> yes(act.true) / no(act.false)
     *
     * @param array<string, mixed> $extra merged into the branch step node
     */
    private function branchDefinition(array $extra = []): string
    {
        $this->registerAction('act.true');
        $this->registerAction('act.false');
        return (string) json_encode([
            'schema' => 1,
            'entry' => 'gate',
            'steps' => [
                'gate' => array_merge([
                    'type' => 'branch',
                    'conditions_serialized' => 'BR',
                    'on_true' => 'yes',
                    'on_false' => 'no',
                ], $extra),
                'yes' => ['type' => 'action', 'action' => 'act.true'],
                'no' => ['type' => 'action', 'action' => 'act.false'],
            ],
        ]);
    }

    /**
     * sw(switch C1/C2/C3 -> s1/s2/s3, default sd)
     */
    private function switchDefinition(): string
    {
        $this->registerAction('act.one');
        $this->registerAction('act.two');
        $this->registerAction('act.three');
        $this->registerAction('act.default');
        return (string) json_encode([
            'schema' => 3,
            'entry' => 'sw',
            'steps' => [
                'sw' => [
                    'type' => 'switch',
                    'cases' => [
                        ['key' => 'c1', 'conditions_serialized' => 'C1', 'next' => 's1'],
                        ['key' => 'c2', 'conditions_serialized' => 'C2', 'next' => 's2'],
                        ['key' => 'c3', 'conditions_serialized' => 'C3', 'next' => 's3'],
                    ],
                    'default' => 'sd',
                ],
                's1' => ['type' => 'action', 'action' => 'act.one'],
                's2' => ['type' => 'action', 'action' => 'act.two'],
                's3' => ['type' => 'action', 'action' => 'act.three'],
                'sd' => ['type' => 'action', 'action' => 'act.default'],
            ],
        ]);
    }

    private function registerAction(string $code, ?\Closure $behavior = null): void
    {
        $this->actions[$code] = new RecordingAction($code, $this->recorder, $behavior);
    }

    private function newExecution(string $definition): WorkflowExecutionStub
    {
        $execution = new WorkflowExecutionStub('exec-uuid', 7, 1);
        $execution->setExecutionId(101);
        $execution->setWorkflowId(42);
        $execution->setDefinitionSnapshot($definition);
        $execution->setStatus(WorkflowExecutionInterface::STATUS_PENDING);
        $execution->setContext((string) json_encode([
            'trigger' => ['increment_id' => '000000123'],
            'steps' => [],
            'workflow' => [],
        ]));
        return $execution;
    }

    private function buildExecutor(ConditionEvaluator $conditions): Executor
    {
        $workflow = (new WorkflowStub(42))
            ->setStatus(WorkflowInterface::STATUS_ENABLED)
            ->setEntityType('sales_order')
            ->setConditionsSerialized('{"root":"tree"}');
        $workflowRepository = new FakeWorkflowRepository($workflow);
        $executionRepository = new FakeExecutionRepository($this->recorder, $this->execution);

        return new Executor(
            $executionRepository,
            $workflowRepository,
            $conditions,
            new ActionPool($this->actions),
            new VariableResolver(new SecretsProviderStub()),
            $this->circuitBreaker($workflowRepository),
            $this->connection->asResource(),
            $this->events,
            new NullLogger(),
            new DelayCalculator(),
            new StubScopeConfig(['general/locale/timezone' => 'UTC']),
            null,
            null
        );
    }

    private function circuitBreaker(WorkflowRepositoryInterface $workflowRepository): CircuitBreaker
    {
        // Inert doubles: the breaker never trips in these walks (threshold 10).
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
}

/**
 * Shared ordered event log: DB writes and action side effects append here so
 * tests can assert persist-before-execute ordering directly.
 */
class WalkRecorder
{
    /** @var string[] */
    public array $events = [];

    public function record(string $event): void
    {
        $this->events[] = $event;
    }

    public function indexOf(string $event): int
    {
        $index = array_search($event, $this->events, true);
        if ($index === false) {
            throw new \RuntimeException(sprintf(
                'Event "%s" was never recorded; log: %s',
                $event,
                implode(', ', $this->events)
            ));
        }
        return (int) $index;
    }
}

/**
 * Action double that records each side effect in the shared log. Default
 * behavior succeeds with ['ran' => code]; a behavior closure may return any
 * ActionResultInterface or throw.
 */
class RecordingAction implements ActionInterface
{
    public int $runs = 0;

    public function __construct(
        private readonly string $code,
        private readonly WalkRecorder $recorder,
        private readonly ?\Closure $behavior = null
    ) {
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $this->runs++;
        $this->recorder->record('action:' . $this->code);
        if ($this->behavior !== null) {
            return ($this->behavior)($ctx, $config);
        }
        return ActionResult::success(['ran' => $this->code]);
    }
}

/**
 * Controllable stand-in for the production ConditionEvaluator (parent
 * constructor deliberately not invoked, mirroring StubConditionEvaluator):
 * a canned root-condition result plus per-tree results for branch/switch,
 * recording every call.
 */
class FakeWalkConditionEvaluator extends ConditionEvaluator
{
    /** @var int[] workflow ids evaluate() was asked about */
    public array $rootCalls = [];

    /** @var array<int, array{serialized: string, entity_type: string, revalidate: bool}> */
    public array $serializedCalls = [];

    /**
     * @param array<string, bool> $serializedResults serialized tree => result
     */
    public function __construct(
        private readonly bool $rootResult = true,
        private readonly array $serializedResults = []
    ) {
    }

    public function evaluate(WorkflowInterface $workflow, ExecutionContext $ctx): bool
    {
        $this->rootCalls[] = (int) $workflow->getWorkflowId();
        return $this->rootResult;
    }

    public function evaluateSerialized(
        string $conditionsSerialized,
        string $entityType,
        ExecutionContext $ctx,
        bool $revalidateEntity
    ): bool {
        $this->serializedCalls[] = [
            'serialized' => $conditionsSerialized,
            'entity_type' => $entityType,
            'revalidate' => $revalidateEntity,
        ];
        return $this->serializedResults[$conditionsSerialized] ?? true;
    }
}

/**
 * In-memory execution repository over a single execution row; every save is
 * appended to the shared log with the status it persisted.
 */
class FakeExecutionRepository implements WorkflowExecutionRepositoryInterface
{
    public function __construct(
        private readonly WalkRecorder $recorder,
        private readonly WorkflowExecutionInterface $execution
    ) {
    }

    public function save(WorkflowExecutionInterface $execution): WorkflowExecutionInterface
    {
        $this->recorder->record('execution_save:' . $execution->getStatus());
        return $execution;
    }

    public function getById(int $executionId): WorkflowExecutionInterface
    {
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
}

/**
 * Workflow repository holding one workflow (or none: NoSuchEntityException,
 * the deleted-mid-flight path).
 */
class FakeWorkflowRepository implements WorkflowRepositoryInterface
{
    public function __construct(private readonly ?WorkflowInterface $workflow)
    {
    }

    public function save(WorkflowInterface $workflow): WorkflowInterface
    {
        return $workflow;
    }

    public function getById(int $workflowId): WorkflowInterface
    {
        if ($this->workflow === null) {
            throw new NoSuchEntityException();
        }
        return $this->workflow;
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
}

/**
 * Captures dispatched events; last('name') returns the latest payload.
 */
class RecordingEventManager implements EventManagerInterface
{
    /** @var array<int, array{name: string, data: array}> */
    public array $dispatched = [];

    public function dispatch($eventName, array $data = [])
    {
        $this->dispatched[] = ['name' => (string) $eventName, 'data' => $data];
    }

    /**
     * @return array|null the latest payload dispatched under $eventName
     */
    public function last(string $eventName): ?array
    {
        $found = null;
        foreach ($this->dispatched as $event) {
            if ($event['name'] === $eventName) {
                $found = $event['data'];
            }
        }
        return $found;
    }
}

/**
 * In-memory stand-in for the DB adapter (pattern shared with the approval
 * suite's CapturingConnection): the step-row upsert always inserts (fetchOne
 * returns false), inserts/updates are captured for assertions, and step-table
 * inserts are appended to the shared ordering log as
 * "step_row:<step_key>:<status>".
 */
class RecordingConnection
{
    /** @var array<int, array{table: string, bind: array}> */
    public array $inserts = [];

    /** @var array<int, array{table: string, bind: array, where: mixed}> */
    public array $updates = [];

    public function __construct(private readonly WalkRecorder $recorder)
    {
    }

    public function asResource(): ResourceConnection
    {
        $self = $this;
        return new class ($self) extends ResourceConnection {
            public function __construct(private readonly RecordingConnection $capture)
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
            public function __construct(private readonly RecordingConnection $capture)
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

            public function insert($table, array $bind)
            {
                $this->capture->recordInsert((string) $table, $bind);
                return 1;
            }

            public function update($table, array $bind, $where = '')
            {
                $this->capture->updates[] = ['table' => (string) $table, 'bind' => $bind, 'where' => $where];
                return 1;
            }
        };
    }

    public function recordInsert(string $table, array $bind): void
    {
        $this->inserts[] = ['table' => $table, 'bind' => $bind];
        if (isset($bind['step_key'], $bind['status'])) {
            $this->recorder->record(sprintf('step_row:%s:%s', $bind['step_key'], $bind['status']));
        }
    }

    /**
     * @return array<int, array<string, mixed>> all step-row insert binds for a key, oldest first
     */
    public function stepRows(string $stepKey): array
    {
        $rows = [];
        foreach ($this->inserts as $row) {
            if (($row['bind']['step_key'] ?? null) === $stepKey) {
                $rows[] = $row['bind'];
            }
        }
        return $rows;
    }

    /**
     * @return array<string, mixed>|null the LAST step-row insert bind for a key
     */
    public function lastStepRow(string $stepKey): ?array
    {
        $rows = $this->stepRows($stepKey);
        return $rows === [] ? null : $rows[count($rows) - 1];
    }

    /**
     * @return array<string, mixed>|null the last UPDATE bind against the execution table
     */
    public function lastExecutionTableUpdate(): ?array
    {
        $found = null;
        foreach ($this->updates as $update) {
            if ($update['table'] === 'mageos_workflow_execution') {
                $found = $update['bind'];
            }
        }
        return $found;
    }
}
