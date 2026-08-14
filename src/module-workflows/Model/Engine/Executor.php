<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Engine;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\ApprovalTaskManagerInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Api\WorkflowExecutionRepositoryInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\Action\ActionPool;
use MageOS\Workflows\Model\Action\ActionResult;
use MageOS\Workflows\Model\Definition\Definition;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Model\Rule\ConditionEvaluator;
use MageOS\Workflows\Model\Variable\VariableResolver;
use Psr\Log\LoggerInterface;

/**
 * The graph walker (docs/08-execution-model.md).
 *
 * Crash safety: execution/step state is persisted BEFORE any side effect, so
 * consumer death mid-step means queue redelivery resumes from current_step.
 * Retryable step failures rethrow so the queue redelivers; terminal failures
 * fail the execution and trip the circuit breaker counter.
 *
 * Persist-before-side-effect makes redelivery SAFE to resume; it does not by
 * itself make it safe to RE-RUN. current_step is only advanced at the top of
 * the next iteration, so an execution whose step already finished still points
 * at that step until the following step's pre-write lands — and every
 * redelivery path (a retryable failure thrown by a later step, a zombie
 * republish from the resume sweeper, a consumer death between the step-row
 * write and the execution-row save) re-enters the walk on it. walk() therefore
 * opens each iteration with the completed-step guard below
 * (@see resumePastCompletedStep) so an already-`complete` step row is resumed
 * PAST — using its recorded outcome — instead of re-executed.
 *
 * Suspension is honored here, not only at dispatch: an execution whose workflow
 * has been suspended (circuit breaker) is failed terminally before it walks
 * (@see abortSuspended), so the queue stops burning on a workflow the breaker
 * already gave up on.
 */
class Executor
{
    private const STEP_TABLE = 'mageos_workflow_execution_step';
    private const EXECUTION_TABLE = 'mageos_workflow_execution';

    /**
     * Hard iteration cap: definitions validate edges but not acyclicity
     */
    private const MAX_STEPS_PER_RUN = 1000;

    public const CONFIG_MAX_DELAY_DAYS = 'mageos_workflows/guards/max_delay_days';
    public const DEFAULT_MAX_DELAY_DAYS = 365;

    public const CONFIG_REDACT_SHADOW_SECRETS = 'mageos_workflows/simulation/redact_shadow_secrets';

    public function __construct(
        private readonly WorkflowExecutionRepositoryInterface $executionRepository,
        private readonly WorkflowRepositoryInterface $workflowRepository,
        private readonly ConditionEvaluator $conditionEvaluator,
        private readonly ActionPool $actionPool,
        private readonly VariableResolver $variableResolver,
        private readonly CircuitBreaker $circuitBreaker,
        private readonly ResourceConnection $resourceConnection,
        private readonly EventManagerInterface $eventManager,
        private readonly LoggerInterface $logger,
        private readonly DelayCalculator $delayCalculator,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ?VariableResolver $redactingVariableResolver = null,
        private readonly ?ApprovalTaskManagerInterface $approvalTaskManager = null
    ) {
    }

    /**
     * Queue consumer entry point.
     *
     * @throws \Throwable retryable step failures escape so the queue redelivers
     */
    public function execute(int $executionId): void
    {
        try {
            $execution = $this->executionRepository->getById($executionId);
        } catch (NoSuchEntityException $e) {
            $this->logger->warning(sprintf('Workflow execution %d not found; message dropped', $executionId));
            return;
        }

        $status = $execution->getStatus();
        if (in_array($status, [
            WorkflowExecutionInterface::STATUS_COMPLETE,
            WorkflowExecutionInterface::STATUS_CANCELLED,
            WorkflowExecutionInterface::STATUS_FAILED,
            WorkflowExecutionInterface::STATUS_SKIPPED,
        ], true)) {
            return;
        }

        // Parked by a delay: only the resume path (which flips the status to
        // running first) may wake it. A redelivered execute message arriving
        // after the delay persisted must NOT walk past the delay early.
        if ($status === WorkflowExecutionInterface::STATUS_WAITING) {
            return;
        }

        try {
            $definition = Definition::fromJson($execution->getDefinitionSnapshot());
        } catch (\InvalidArgumentException $e) {
            $this->failExecution($execution, null, 'Invalid definition snapshot: ' . $e->getMessage());
            return;
        }

        $workflow = $this->loadWorkflow($execution->getWorkflowId());
        if ($workflow !== null && $workflow->getStatus() === WorkflowInterface::STATUS_SUSPENDED) {
            $this->abortSuspended($execution, $workflow);
            return;
        }
        $simulation = $workflow !== null && $workflow->getStatus() === WorkflowInterface::STATUS_SHADOW;
        $ctx = $this->buildContext($execution, $simulation);

        $currentKey = $execution->getCurrentStep();

        // "First run" is the row a dispatch just created: pending AND no step
        // claimed yet. Status alone is not enough — the sweeper's recovery of
        // stranded executions (Cron/ResumeSweeper) re-claims a stalled
        // `running` execution back to `pending` mid-walk, and re-gating THAT on
        // root conditions would skip an execution outright because the entity
        // moved on since it started. An execution that already holds a
        // current_step has passed the gate once; the gate is not re-run.
        $isFirstRun = $status === WorkflowExecutionInterface::STATUS_PENDING
            && ($currentKey === null || $currentKey === '');

        // Aggregated (batch) executions carry entity_id = 0: they have no single
        // entity to re-evaluate root conditions against, and membership was
        // already enforced per item at accumulation time (05). This entity_id=0
        // tolerance is the executor's ONLY batch-awareness — everything else
        // concentrates in save-time validation and the dispatch layer.
        if ($isFirstRun && $workflow !== null && $execution->getEntityId() > 0) {
            if (!$this->conditionEvaluator->evaluate($workflow, $ctx)) {
                $execution->setStatus(WorkflowExecutionInterface::STATUS_SKIPPED);
                $this->persistContext($execution, $ctx);
                $this->executionRepository->save($execution);
                $this->markCompletedAt($execution);
                return;
            }
        }

        if ($currentKey === null || $currentKey === '') {
            if (!$isFirstRun) {
                // Resumed past a trailing delay with no next step: nothing left to do
                $this->completeExecution($execution, $ctx);
                return;
            }
            $currentKey = $definition->getEntryKey();
        }

        if ($currentKey === null) {
            $this->completeExecution($execution, $ctx);
            return;
        }

        $execution->setStatus(WorkflowExecutionInterface::STATUS_RUNNING);
        $execution->setCurrentStep($currentKey);
        $this->executionRepository->save($execution);

        $this->walk($execution, $definition, $ctx, $workflow, $currentKey);
    }

    /**
     * The circuit breaker's second half (docs/07-actions.md §Guards,
     * docs/15-operations.md §Circuit-breaker recovery).
     *
     * Suspension used to stop only NEW dispatches: the Dispatcher refuses to
     * create executions for a suspended workflow, but everything already on the
     * queue kept walking — failing, throwing retryable, being redelivered, and
     * failing again. That is precisely the retry-queue burn the breaker exists
     * to stop, and the breaker itself made it worse: each redelivered failure
     * calls recordFailure() again on a workflow that is already suspended.
     *
     * So an execution that reaches the executor under a suspended workflow ends
     * TERMINALLY, here, before any step runs. Terminal (not retryable) is the
     * whole point — a retryable failure would be redelivered forever, and a
     * suspended workflow does not self-heal (only a human re-enables it, and
     * re-enabling cannot un-fail these rows: their entities have moved on).
     * The error names the suspension so the grid's failure reason reads as
     * "the breaker stopped this", not "the action broke".
     *
     * Deliberately narrow:
     *  - `disabled` is NOT included. Disabling stops new dispatches; letting
     *    in-flight executions finish is the existing (and kinder) behavior, and
     *    the breaker is not involved.
     *  - `shadow` is NOT included — shadow executions run simulated, no side
     *    effects, nothing to protect the retry queue from.
     *  - A workflow DELETED mid-flight (loadWorkflow returns null) still
     *    executes from its pinned snapshot, unchanged.
     *  - The check sits at the top of execute(), which is the single entry
     *    point for BOTH deliveries: the execute topic (ExecuteConsumer) and
     *    resumption (ResumeConsumer::process calls execute() after routing the
     *    park). A delay/wait/approval parked before the breaker tripped is
     *    therefore failed on the way out of the park rather than resumed.
     */
    private function abortSuspended(WorkflowExecutionInterface $execution, WorkflowInterface $workflow): void
    {
        $this->failExecution(
            $execution,
            $execution->getCurrentStep(),
            sprintf(
                'Workflow "%s" (ID %d) is suspended (circuit breaker); execution aborted instead of '
                . 'retrying its steps. Re-enable the workflow once the cause is fixed — '
                . 'already-queued executions are not resumed.',
                $workflow->getName(),
                (int) $workflow->getWorkflowId()
            )
        );
    }

    /**
     * @throws \Throwable
     */
    private function walk(
        WorkflowExecutionInterface $execution,
        Definition $definition,
        ExecutionContext $ctx,
        ?WorkflowInterface $workflow,
        string $currentKey
    ): void {
        $iterations = 0;

        while ($currentKey !== null) {
            if (++$iterations > self::MAX_STEPS_PER_RUN) {
                $this->failExecution(
                    $execution,
                    $currentKey,
                    sprintf('Step iteration limit (%d) exceeded; definition likely cyclic', self::MAX_STEPS_PER_RUN),
                    $ctx
                );
                return;
            }

            if (!$definition->hasStep($currentKey)) {
                $this->failExecution(
                    $execution,
                    $currentKey,
                    sprintf('Unknown step "%s" in definition snapshot', $currentKey),
                    $ctx
                );
                return;
            }

            $step = $definition->getStep($currentKey);

            // Redelivery guard, BEFORE the running claim below so a finished
            // step row is never flipped back to running. Re-checked on every
            // iteration: a redelivery can re-enter the graph anywhere.
            $resume = $this->resumePastCompletedStep($execution, $definition, $ctx, $currentKey, $step);
            if ($resume !== null) {
                if ($resume['stop']) {
                    $this->completeExecution($execution, $ctx);
                    return;
                }
                // Iterations still tick, so a cyclic snapshot of completed
                // steps hits MAX_STEPS_PER_RUN exactly like a live one.
                $currentKey = $resume['next'];
                continue;
            }

            // State in DB before the side effect: crash here = clean redelivery
            $execution->setCurrentStep($currentKey);
            $this->persistContext($execution, $ctx);
            $this->executionRepository->save($execution);
            $this->upsertStepRow($execution, $currentKey, [
                'status' => WorkflowExecutionStepInterface::STATUS_RUNNING,
                'claimed_at' => $this->now(),
                'started_at' => $this->now(),
            ]);

            switch ($step['type']) {
                case Definition::STEP_ACTION:
                    $currentKey = $this->runActionStep($execution, $ctx, $definition, $currentKey, $step);
                    if ($currentKey === false) {
                        return; // execution failed terminally
                    }
                    break;

                case Definition::STEP_DELAY:
                    $this->runDelayStep($execution, $ctx, $definition, $currentKey, $step);
                    return; // message done; resumption is a separate delivery

                case Definition::STEP_WAIT:
                    $this->runWaitStep($execution, $ctx, $currentKey, $step);
                    return; // parked until the event fires or the timeout sweeps

                case Definition::STEP_APPROVAL:
                    $this->runApprovalStep($execution, $ctx, $currentKey, $step);
                    return; // parked until a decision arrives or the timeout sweeps

                case Definition::STEP_BRANCH:
                    $currentKey = $this->runBranchStep($execution, $ctx, $workflow, $definition, $currentKey, $step);
                    break;

                case Definition::STEP_SWITCH:
                    $currentKey = $this->runSwitchStep($execution, $ctx, $workflow, $definition, $currentKey, $step);
                    break;

                case Definition::STEP_STOP:
                default:
                    $this->upsertStepRow($execution, $currentKey, [
                        'status' => WorkflowExecutionStepInterface::STATUS_COMPLETE,
                        'finished_at' => $this->now(),
                    ]);
                    $this->completeExecution($execution, $ctx);
                    return;
            }
        }

        $this->completeExecution($execution, $ctx);
    }

    /**
     * At-least-once delivery means a step can be re-entered after it already
     * finished (docs/08 "Crash safety and delivery semantics"). Re-running a
     * `complete` step row would re-fire its side effect — a second refund, a
     * second email — so a completed step is resumed PAST instead, following the
     * edge its own recorded outcome chose. Rows in any other status
     * (running/pending/failed/waiting) keep at-least-once semantics: the caller
     * re-executes them, which is what those statuses mean.
     *
     * Scope is deliberately action / branch / switch / stop — the step types
     * whose outcome is fully recoverable from the definition plus the step row:
     *
     *  - action: the next edge is static (`next`), and the recorded result
     *    carries the output. The output is normally already in the persisted
     *    context (runActionStep writes the step row, then persists the context
     *    and saves), but the two writes are not one transaction, so a crash
     *    between them leaves a complete row whose output never reached the
     *    context bag. Rehydrating from the row — written atomically WITH the
     *    complete status — closes that window for downstream interpolation.
     *  - branch / switch: the recorded `{"result": bool}` / `{"matched": key}`
     *    reproduces the edge originally taken, so a resumed walk cannot diverge
     *    from the first pass because the entity changed underneath it. A
     *    missing or corrupt result falls back to re-evaluating: conditions are
     *    side-effect free, so re-evaluation is a correctness-preserving (if
     *    less deterministic) fallback, not a hazard.
     *  - stop: nothing to replay; the execution completes.
     *
     * delay / wait / approval are deliberately EXCLUDED. current_step never
     * rests on a completed park step in the normal flow — runDelayStep advances
     * it past the delay at park time, and ResumeConsumer routes wait/approval
     * off the gate onto on_event/on_timeout/on_approved/on_rejected as it flips
     * the row to complete — so the guard would only ever fire in the narrow
     * crash window inside ResumeConsumer::process (row closed, execution not
     * yet saved). Handling that here would mean duplicating the consumer's
     * decision-routing rules in a second place, where they could drift; today's
     * behavior re-parks the gate, keeps the recorded result (upsertStepRow only
     * touches the columns it is given) and re-attaches to the idempotent
     * approval task, so the next resume routes on the original decision. A late
     * resume is a far cheaper failure mode than routing that disagrees with the
     * consumer, so park steps keep the existing behavior.
     *
     * @param array $step definition node for $stepKey
     * @return array{stop: bool, next: string|null}|null null = execute the step normally
     */
    private function resumePastCompletedStep(
        WorkflowExecutionInterface $execution,
        Definition $definition,
        ExecutionContext $ctx,
        string $stepKey,
        array $step
    ): ?array {
        $type = $step['type'] ?? null;
        if (!in_array($type, [
            Definition::STEP_ACTION,
            Definition::STEP_BRANCH,
            Definition::STEP_SWITCH,
            Definition::STEP_STOP,
        ], true)) {
            return null;
        }

        $row = $this->fetchStepRow($execution, $stepKey);
        if ($row === null
            || ($row['status'] ?? null) !== WorkflowExecutionStepInterface::STATUS_COMPLETE
        ) {
            return null;
        }
        $result = $this->decodeStepResult($row['result'] ?? null);

        switch ($type) {
            case Definition::STEP_STOP:
                $this->logResumePast($execution, $stepKey, 'stop', 'completing the execution');
                return ['stop' => true, 'next' => null];

            case Definition::STEP_ACTION:
                $output = $result['output'] ?? null;
                if (is_array($output)) {
                    $ctx->setStepOutput($stepKey, $output);
                }
                $next = $definition->getStepEdges($stepKey)['next'];
                $this->logResumePast($execution, $stepKey, 'action', $this->describeEdge($next));
                return ['stop' => false, 'next' => $next];

            case Definition::STEP_BRANCH:
                $taken = $result['result'] ?? null;
                if (!is_bool($taken)) {
                    // Unreadable outcome: re-evaluating is side-effect free
                    return null;
                }
                $edges = $definition->getStepEdges($stepKey);
                $next = $taken ? $edges['on_true'] : $edges['on_false'];
                $this->logResumePast($execution, $stepKey, 'branch', $this->describeEdge($next));
                return ['stop' => false, 'next' => $next];

            case Definition::STEP_SWITCH:
            default:
                if (!array_key_exists('matched', $result)) {
                    return null;
                }
                $edges = $definition->getStepEdges($stepKey);
                $matched = $result['matched'];
                if ($matched === null) {
                    // No case matched on the first pass: the default edge
                    $next = $edges['default'] ?? null;
                } elseif (is_string($matched) || is_int($matched)) {
                    $edgeKey = 'case:' . $matched;
                    if (!array_key_exists($edgeKey, $edges)) {
                        // The recorded case is gone from the snapshot: re-evaluate
                        return null;
                    }
                    $next = $edges[$edgeKey];
                } else {
                    return null;
                }
                $this->logResumePast($execution, $stepKey, 'switch', $this->describeEdge($next));
                return ['stop' => false, 'next' => $next];
        }
    }

    /**
     * Status + recorded result of this execution's row for $stepKey, or null
     * when the step has never run. Unique-key lookup on (execution_id,
     * step_key): the limit(1) is belt-and-braces, not disambiguation — the
     * constraint guarantees at most one row, so this can no longer route a
     * redelivery off whichever duplicate the storage engine happened to
     * return first.
     *
     * @return array{status: string, result: string|null}|null
     */
    private function fetchStepRow(WorkflowExecutionInterface $execution, string $stepKey): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from(
                    $this->resourceConnection->getTableName(self::STEP_TABLE),
                    [WorkflowExecutionStepInterface::STATUS, WorkflowExecutionStepInterface::RESULT]
                )
                ->where('execution_id = ?', (int) $execution->getExecutionId())
                ->where('step_key = ?', $stepKey)
                ->limit(1)
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Recorded step result as an array; [] when absent or unparseable (the
     * callers treat that as "outcome unknown", never as a valid outcome).
     */
    private function decodeStepResult(mixed $resultJson): array
    {
        if (!is_string($resultJson) || $resultJson === '') {
            return [];
        }
        $decoded = json_decode($resultJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function describeEdge(?string $next): string
    {
        return $next !== null ? sprintf('following the recorded edge to "%s"', $next) : 'ending the walk';
    }

    private function logResumePast(
        WorkflowExecutionInterface $execution,
        string $stepKey,
        string $type,
        string $outcome
    ): void {
        $this->logger->info(sprintf(
            'Workflow execution %d re-entered already-complete %s step "%s" (queue redelivery); '
            . 'skipping re-execution and %s',
            (int) $execution->getExecutionId(),
            $type,
            $stepKey,
            $outcome
        ));
    }

    /**
     * @return string|false|null next step key, null for graph end, false when the execution failed terminally
     * @throws \Throwable retryable failures rethrow for queue redelivery
     */
    private function runActionStep(
        WorkflowExecutionInterface $execution,
        ExecutionContext $ctx,
        Definition $definition,
        string $stepKey,
        array $step
    ): string|false|null {
        $code = (string) $step['action'];
        $workflowId = $execution->getWorkflowId();

        if (!$this->actionPool->has($code)) {
            $this->circuitBreaker->recordFailure($workflowId);
            $this->failStep($execution, $stepKey, sprintf('Unknown workflow action "%s"', $code));
            $this->failExecution($execution, $stepKey, sprintf('Unknown workflow action "%s"', $code), $ctx, false);
            return false;
        }

        $config = is_array($step['config'] ?? null) ? $step['config'] : [];
        $config = $this->resolverFor($ctx)->resolveConfig($config, $ctx);
        $action = $this->actionPool->get($code);

        try {
            if ($ctx->isSimulation()) {
                if ($action instanceof SimulateableActionInterface) {
                    $result = $action->simulate($ctx, $config);
                } else {
                    $result = ActionResult::success([
                        'simulated' => true,
                        'note' => sprintf('would run %s', $code),
                    ]);
                }
            } else {
                $result = $action->execute($ctx, $config);
            }
        } catch (\Throwable $e) {
            // Uncaught action exception = retryable: park the step, redeliver
            $this->circuitBreaker->recordFailure($workflowId);
            $this->upsertStepRow($execution, $stepKey, [
                'status' => WorkflowExecutionStepInterface::STATUS_PENDING,
                'error' => $e->getMessage(),
            ]);
            $this->persistContext($execution, $ctx);
            $this->executionRepository->save($execution);
            throw $e;
        }

        if ($result->isFailure()) {
            $this->circuitBreaker->recordFailure($workflowId);
            $error = $result->getError() ?? 'Action failed';

            if ($result->isRetryable()) {
                $this->upsertStepRow($execution, $stepKey, [
                    'status' => WorkflowExecutionStepInterface::STATUS_PENDING,
                    'error' => $error,
                ]);
                $this->persistContext($execution, $ctx);
                $this->executionRepository->save($execution);
                throw new \RuntimeException(
                    sprintf('Retryable failure in step "%s" of execution %d: %s',
                        $stepKey,
                        (int) $execution->getExecutionId(),
                        $error
                    )
                );
            }

            $this->failStep($execution, $stepKey, $error, $result->getOutput());
            $this->failExecution($execution, $stepKey, $error, $ctx, false);
            return false;
        }

        $this->circuitBreaker->recordSuccess($workflowId);
        $ctx->setStepOutput($stepKey, $result->getOutput());
        $this->upsertStepRow($execution, $stepKey, [
            'status' => WorkflowExecutionStepInterface::STATUS_COMPLETE,
            'result' => $this->encodeJson([
                'status' => $result->getStatus(),
                'output' => $result->getOutput(),
            ]),
            'finished_at' => $this->now(),
        ]);
        $this->persistContext($execution, $ctx);
        $this->executionRepository->save($execution);

        return $definition->getStepEdges($stepKey)['next'];
    }

    /**
     * Delay: plain durations use absolute UTC arithmetic (docs/04, docs/14);
     * the v2 extras (business_days, at) compute in the store timezone via
     * DelayCalculator. Step goes waiting with resume_at; execution goes
     * waiting with current_step = the step AFTER the delay so resumption
     * walks straight into it.
     */
    private function runDelayStep(
        WorkflowExecutionInterface $execution,
        ExecutionContext $ctx,
        Definition $definition,
        string $stepKey,
        array $step
    ): void {
        $resumeAt = $this->computeResumeAt(
            $execution,
            $stepKey,
            is_array($step['config'] ?? null) ? $step['config'] : []
        );

        $this->upsertStepRow($execution, $stepKey, [
            'status' => WorkflowExecutionStepInterface::STATUS_WAITING,
            'resume_at' => $resumeAt,
        ]);

        $execution->setStatus(WorkflowExecutionInterface::STATUS_WAITING);
        $execution->setCurrentStep($definition->getStepEdges($stepKey)['next']);
        $this->persistContext($execution, $ctx);
        $this->executionRepository->save($execution);
    }

    /**
     * Wait (schema v2): park until the configured event fires for this
     * execution's entity, or until the timeout sweeps. Unlike a delay,
     * current_step stays ON the wait step — the resume consumer routes to
     * on_event / on_timeout based on how the park ended (step row result
     * written by Dispatcher::resumeWaiting or the ResumeSweeper).
     */
    private function runWaitStep(
        WorkflowExecutionInterface $execution,
        ExecutionContext $ctx,
        string $stepKey,
        array $step
    ): void {
        $config = is_array($step['config'] ?? null) ? $step['config'] : [];
        $timeoutAt = $this->computeResumeAt($execution, $stepKey, ['duration' => $config['timeout'] ?? 'PT0S']);

        $this->upsertStepRow($execution, $stepKey, [
            'status' => WorkflowExecutionStepInterface::STATUS_WAITING,
            'resume_at' => $timeoutAt,
        ]);

        $execution->setStatus(WorkflowExecutionInterface::STATUS_WAITING);
        $execution->setCurrentStep($stepKey);
        $execution->setWaitingEvent((string) ($config['event'] ?? ''));
        $this->persistContext($execution, $ctx);
        $this->executionRepository->save($execution);
    }

    /**
     * Approval (schema v4): a human-decision gate parked on the wait spine.
     * Parks like runWaitStep — step row waiting with resume_at, execution
     * waiting — with two differences: waiting_event stays null (no event to
     * match; only a decision or the timeout sweeper wakes it) and current_step
     * stays ON the gate so the resume consumer routes on_approved /
     * on_rejected / on_timeout from the decision the addon wrote.
     *
     * Task lifecycle delegates to the ApprovalTaskManagerInterface seam, bound
     * only when the approvals addon is installed. With no binding this is a
     * terminal failure — the runtime backstop (docs/discovery/approval-gate.md
     * §7), reachable only via a data patch that bypassed save-time validation,
     * never a silent skip.
     */
    private function runApprovalStep(
        WorkflowExecutionInterface $execution,
        ExecutionContext $ctx,
        string $stepKey,
        array $step
    ): void {
        if ($this->approvalTaskManager === null) {
            $error = sprintf(
                'Approval step "%s" reached with no approvals module bound; install MageOS_WorkflowsApprovals',
                $stepKey
            );
            $this->failStep($execution, $stepKey, $error);
            $this->failExecution($execution, $stepKey, $error, $ctx, false);
            return;
        }

        $config = is_array($step['config'] ?? null) ? $step['config'] : [];

        // Park-time snapshot: title/instructions render in the task grid and
        // emails, so interpolate them here (docs §3) — late interpolation would
        // leak post-hoc entity changes into an already-issued request.
        $resolved = $this->resolverFor($ctx)->resolveConfig([
            'title' => (string) ($config['title'] ?? ''),
            'instructions' => (string) ($config['instructions'] ?? ''),
        ], $ctx);
        $title = (string) ($resolved['title'] ?? '');
        $instructions = (string) ($resolved['instructions'] ?? '');

        $resumeAt = $this->computeResumeAt($execution, $stepKey, ['duration' => $config['timeout'] ?? 'PT0S']);
        $assigneeRole = isset($config['assignee_role']) && $config['assignee_role'] !== ''
            ? (string) $config['assignee_role']
            : null;

        // Create the task first — idempotent on (execution_id, step_key), so a
        // crash before the execution persists re-parks cleanly on redelivery —
        // then expose its uuid as this step's own output BEFORE persisting so
        // downstream steps can interpolate {{ steps.<key>.task_uuid }} (§2 A3).
        $taskUuid = $this->approvalTaskManager->createTask(
            $execution,
            $stepKey,
            $title,
            $instructions,
            $resumeAt,
            $assigneeRole
        );
        $ctx->setStepOutput($stepKey, ['task_uuid' => $taskUuid]);

        $this->upsertStepRow($execution, $stepKey, [
            'status' => WorkflowExecutionStepInterface::STATUS_WAITING,
            'resume_at' => $resumeAt,
        ]);

        $execution->setStatus(WorkflowExecutionInterface::STATUS_WAITING);
        $execution->setCurrentStep($stepKey);
        $execution->setWaitingEvent(null);
        $this->persistContext($execution, $ctx);
        $this->executionRepository->save($execution);
    }

    /**
     * @return string UTC 'Y-m-d H:i:s' resume time, ceiling-clamped
     */
    private function computeResumeAt(WorkflowExecutionInterface $execution, string $stepKey, array $config): string
    {
        $maxDays = (int) $this->scopeConfig->getValue(self::CONFIG_MAX_DELAY_DAYS);
        if ($maxDays <= 0) {
            $maxDays = self::DEFAULT_MAX_DELAY_DAYS;
        }
        $timezone = (string) $this->scopeConfig->getValue(
            'general/locale/timezone',
            ScopeInterface::SCOPE_STORE,
            (int) $execution->getStoreId()
        );

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        try {
            [$resume, $clamped] = $this->delayCalculator->computeResumeAt($now, $config, $timezone, $maxDays);
        } catch (\InvalidArgumentException $e) {
            $resume = $now;
            $clamped = false;
            $this->logger->error(sprintf(
                'Step "%s" of execution %d has an invalid duration (%s); resuming immediately',
                $stepKey,
                (int) $execution->getExecutionId(),
                $e->getMessage()
            ));
        }
        if ($clamped) {
            $this->logger->warning(sprintf(
                'Step "%s" of execution %d exceeded the max delay ceiling (%d days); clamped',
                $stepKey,
                (int) $execution->getExecutionId(),
                $maxDays
            ));
        }

        return $resume->format('Y-m-d H:i:s');
    }

    /**
     * @return string|null next step key (null edge = graph end)
     */
    private function runBranchStep(
        WorkflowExecutionInterface $execution,
        ExecutionContext $ctx,
        ?WorkflowInterface $workflow,
        Definition $definition,
        string $stepKey,
        array $step
    ): ?string {
        $conditionsSerialized = $step['conditions_serialized'] ?? null;
        $revalidate = (bool) ($step['revalidate_entity'] ?? true);
        $entityType = $this->resolveEntityType($workflow, $ctx);

        if (is_string($conditionsSerialized) && $conditionsSerialized !== '') {
            $result = $this->conditionEvaluator->evaluateSerialized(
                $conditionsSerialized,
                $entityType,
                $ctx,
                $revalidate
            );
        } else {
            $result = true;
        }

        $this->upsertStepRow($execution, $stepKey, [
            'status' => WorkflowExecutionStepInterface::STATUS_COMPLETE,
            'result' => $this->encodeJson(['result' => $result]),
            'finished_at' => $this->now(),
        ]);
        $this->persistContext($execution, $ctx);
        $this->executionRepository->save($execution);

        $edges = $definition->getStepEdges($stepKey);
        return $result ? $edges['on_true'] : $edges['on_false'];
    }

    /**
     * Switch (schema v3): first-match-wins over the case list, `default`
     * fallback, one shared revalidate_entity flag for the whole step. An
     * empty/absent case condition tree always matches (mirrors branch).
     * Identical persistence discipline to runBranchStep: the step row is
     * written before the edge is followed. Repeat per-case hydrations under
     * revalidate_entity=true are cheap — repositories keep per-request
     * identity registries under the HydrationProvider.
     *
     * @return string|null next step key (null edge = graph end)
     */
    private function runSwitchStep(
        WorkflowExecutionInterface $execution,
        ExecutionContext $ctx,
        ?WorkflowInterface $workflow,
        Definition $definition,
        string $stepKey,
        array $step
    ): ?string {
        $revalidate = (bool) ($step['revalidate_entity'] ?? true);
        $entityType = $this->resolveEntityType($workflow, $ctx);

        $matched = null;
        $target = null;
        foreach ((array) ($step['cases'] ?? []) as $case) {
            if (!is_array($case)) {
                continue;
            }
            $conditionsSerialized = $case['conditions_serialized'] ?? null;
            $result = !is_string($conditionsSerialized) || $conditionsSerialized === ''
                || $this->conditionEvaluator->evaluateSerialized(
                    $conditionsSerialized,
                    $entityType,
                    $ctx,
                    $revalidate
                );
            if ($result) {
                $matched = (string) $case['key'];
                $target = isset($case['next']) ? (string) $case['next'] : null;
                break;
            }
        }
        if ($matched === null) {
            $target = $definition->getStepEdges($stepKey)['default'];
        }

        $this->upsertStepRow($execution, $stepKey, [
            'status' => WorkflowExecutionStepInterface::STATUS_COMPLETE,
            'result' => $this->encodeJson(['matched' => $matched]),
            'finished_at' => $this->now(),
        ]);
        $this->persistContext($execution, $ctx);
        $this->executionRepository->save($execution);

        return $target;
    }

    private function resolveEntityType(?WorkflowInterface $workflow, ExecutionContext $ctx): string
    {
        return $workflow !== null
            ? $workflow->getEntityType()
            : (string) ($ctx->getWorkflow()['entity_type'] ?? '');
    }

    /**
     * Simulation substrate (F7): shadow-mode executions optionally resolve
     * {{ secrets.* }} to ***name*** via the redacting resolver, behind
     * mageos_workflows/simulation/redact_shadow_secrets (default off — a
     * behavior change for existing shadow users; flip at the next minor).
     * The production resolver is never swapped, only bypassed per call.
     */
    private function resolverFor(ExecutionContext $ctx): VariableResolver
    {
        if ($ctx->isSimulation()
            && $this->redactingVariableResolver !== null
            && $this->scopeConfig->isSetFlag(self::CONFIG_REDACT_SHADOW_SECRETS)
        ) {
            return $this->redactingVariableResolver;
        }
        return $this->variableResolver;
    }

    private function completeExecution(WorkflowExecutionInterface $execution, ?ExecutionContext $ctx = null): void
    {
        $execution->setStatus(WorkflowExecutionInterface::STATUS_COMPLETE);
        $execution->setCurrentStep(null);
        if ($ctx !== null) {
            $this->persistContext($execution, $ctx);
        }
        $this->executionRepository->save($execution);
        $this->markCompletedAt($execution);
        $this->eventManager->dispatch('workflow_execution_complete', ['execution' => $execution]);
    }

    private function failExecution(
        WorkflowExecutionInterface $execution,
        ?string $stepKey,
        string $error,
        ?ExecutionContext $ctx = null,
        bool $logError = true
    ): void {
        if ($logError) {
            $this->logger->error(sprintf(
                'Workflow execution %d failed%s: %s',
                (int) $execution->getExecutionId(),
                $stepKey !== null ? sprintf(' at step "%s"', $stepKey) : '',
                $error
            ));
        }
        $execution->setStatus(WorkflowExecutionInterface::STATUS_FAILED);
        if ($ctx !== null) {
            $this->persistContext($execution, $ctx);
        }
        $this->executionRepository->save($execution);
        $this->markCompletedAt($execution);
        $this->eventManager->dispatch('workflow_execution_failed', [
            'execution' => $execution,
            'error' => $error,
            'step_key' => $stepKey,
        ]);

        // Orphan any approval tasks this execution left open (docs §4). Best
        // effort: the orphan marking must never mask the original failure, so a
        // seam error is logged and swallowed rather than rethrown.
        if ($this->approvalTaskManager !== null) {
            try {
                $this->approvalTaskManager->orphanTasks((int) $execution->getExecutionId());
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Orphaning approval tasks for failed execution %d itself failed: %s',
                    (int) $execution->getExecutionId(),
                    $e->getMessage()
                ));
            }
        }
    }

    private function failStep(
        WorkflowExecutionInterface $execution,
        string $stepKey,
        string $error,
        array $output = []
    ): void {
        $this->logger->error(sprintf(
            'Workflow execution %d step "%s" failed: %s',
            (int) $execution->getExecutionId(),
            $stepKey,
            $error
        ));
        $this->upsertStepRow($execution, $stepKey, [
            'status' => WorkflowExecutionStepInterface::STATUS_FAILED,
            'error' => $error,
            'result' => $output !== [] ? $this->encodeJson($output) : null,
            'finished_at' => $this->now(),
        ]);
    }

    private function buildContext(WorkflowExecutionInterface $execution, bool $simulation): ExecutionContext
    {
        $raw = $execution->getContext();
        $data = [];
        if ($raw !== null && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            } catch (\JsonException $e) {
                $this->logger->error(sprintf(
                    'Workflow execution %d has corrupt context JSON; starting from an empty bag',
                    (int) $execution->getExecutionId()
                ));
            }
        }
        return new ExecutionContext(
            $execution,
            is_array($data['trigger'] ?? null) ? $data['trigger'] : [],
            is_array($data['steps'] ?? null) ? $data['steps'] : [],
            is_array($data['workflow'] ?? null) ? $data['workflow'] : [],
            $simulation
        );
    }

    private function persistContext(WorkflowExecutionInterface $execution, ExecutionContext $ctx): void
    {
        $data = $ctx->toArray();
        // Preserve {} (not []) for empty maps so the JSON shape stays stable
        foreach (['trigger', 'steps', 'workflow'] as $key) {
            if ($data[$key] === []) {
                $data[$key] = new \stdClass();
            }
        }
        $execution->setContext($this->encodeJson($data));
    }

    private function loadWorkflow(int $workflowId): ?WorkflowInterface
    {
        try {
            return $this->workflowRepository->getById($workflowId);
        } catch (NoSuchEntityException $e) {
            // Workflow deleted mid-flight: the pinned snapshot still executes (non-simulation)
            return null;
        }
    }

    /**
     * Insert-or-update the per-step runtime row keyed on (execution_id,
     * step_key), atomically.
     *
     * This used to be SELECT-then-INSERT — the exact shape the Dispatcher's own
     * debounce comments reject as racy. Two consumers holding the same
     * execution (a queue redelivery racing the sweeper's zombie republish) can
     * both miss the row and both insert, leaving two rows for one step that
     * later readers disambiguate by coin flip. The unique constraint on
     * (execution_id, step_key) (etc/db_schema.xml) plus insertOnDuplicate
     * collapses that into one row and one round trip.
     *
     * $updateColumns is exactly the keys of the PARTIAL $data the caller passed:
     * an upsert must never clobber a column it was not given. Resume routing
     * depends on it — re-parking a wait/approval gate writes only
     * {status, resume_at} and the decision `result` recorded by the addon has
     * to survive that (pinned by ExecutorWalkTest §10's re-park test). The
     * `status => pending` default below is therefore insert-only: it seeds a
     * brand new row and is never in $updateColumns unless the caller asked for
     * a status change itself.
     */
    private function upsertStepRow(WorkflowExecutionInterface $execution, string $stepKey, array $data): void
    {
        $connection = $this->resourceConnection->getConnection();

        // Defensive: an EMPTY update-column list means "update every column I
        // gave you" to the adapter — which here would include the pending
        // status seed, resetting a live row. No caller passes empty $data; if
        // one ever does, degrade to re-writing the key column (a no-op on an
        // existing row) rather than to a reset.
        $updateColumns = array_keys($data);
        if ($updateColumns === []) {
            $updateColumns = ['step_key'];
        }

        $connection->insertOnDuplicate(
            $this->resourceConnection->getTableName(self::STEP_TABLE),
            array_merge([
                'execution_id' => (int) $execution->getExecutionId(),
                'step_key' => $stepKey,
                'status' => WorkflowExecutionStepInterface::STATUS_PENDING,
            ], $data),
            $updateColumns
        );
    }

    /**
     * completed_at is not on the data interface; stamp it directly
     */
    private function markCompletedAt(WorkflowExecutionInterface $execution): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName(self::EXECUTION_TABLE),
            [WorkflowExecutionInterface::COMPLETED_AT => $this->now()],
            [WorkflowExecutionInterface::EXECUTION_ID . ' = ?' => (int) $execution->getExecutionId()]
        );
    }

    private function encodeJson(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
