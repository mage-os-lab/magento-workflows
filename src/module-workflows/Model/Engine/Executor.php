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
        private readonly ?VariableResolver $redactingVariableResolver = null
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
        $simulation = $workflow !== null && $workflow->getStatus() === WorkflowInterface::STATUS_SHADOW;
        $ctx = $this->buildContext($execution, $simulation);

        $isFirstRun = $status === WorkflowExecutionInterface::STATUS_PENDING;

        if ($isFirstRun && $workflow !== null) {
            if (!$this->conditionEvaluator->evaluate($workflow, $ctx)) {
                $execution->setStatus(WorkflowExecutionInterface::STATUS_SKIPPED);
                $this->persistContext($execution, $ctx);
                $this->executionRepository->save($execution);
                $this->markCompletedAt($execution);
                return;
            }
        }

        $currentKey = $execution->getCurrentStep();
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
     * Insert-or-update the per-step runtime row keyed on (execution_id, step_key)
     */
    private function upsertStepRow(WorkflowExecutionInterface $execution, string $stepKey, array $data): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::STEP_TABLE);

        $select = $connection->select()
            ->from($table, [WorkflowExecutionStepInterface::STEP_EXECUTION_ID])
            ->where('execution_id = ?', (int) $execution->getExecutionId())
            ->where('step_key = ?', $stepKey)
            ->limit(1);
        $stepExecutionId = $connection->fetchOne($select);

        if ($stepExecutionId) {
            $connection->update(
                $table,
                $data,
                [WorkflowExecutionStepInterface::STEP_EXECUTION_ID . ' = ?' => (int) $stepExecutionId]
            );
        } else {
            $connection->insert($table, array_merge([
                'execution_id' => (int) $execution->getExecutionId(),
                'step_key' => $stepKey,
                'status' => WorkflowExecutionStepInterface::STATUS_PENDING,
            ], $data));
        }
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
