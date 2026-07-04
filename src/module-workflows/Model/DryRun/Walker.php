<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\DryRun;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\ActionPool;
use MageOS\Workflows\Model\Definition\Definition;
use MageOS\Workflows\Model\Engine\DelayCalculator;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Model\Rule\ConditionEvaluator;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;
use MageOS\Workflows\Model\Variable\VariableResolver;

/**
 * The semantic half of the dry-run walk (discovery §4). It mirrors — never
 * calls — the production {@see \MageOS\Workflows\Model\Engine\Executor} run*Step
 * methods, reusing every shared component (ConditionEvaluator, VariableResolver,
 * DelayCalculator, ActionPool.simulate) and routing exclusively over the F1 edge
 * helper {@see Definition::getStepEdges()}. Only three things are novel here and
 * are the sole divergence surface: walk order (delegated to {@see PathExplorer}),
 * time compression (delays/waits annotate instead of parking), and trace
 * assembly.
 *
 * Deviations from production, all deliberate (discovery §4):
 *  - delays/waits never park — they annotate the resolved resume time and continue;
 *  - waits fan out to BOTH edges (no event can arrive in-process), and so do
 *    branch/switch steps whose condition tree cannot be evaluated;
 *  - a failed simulate() does not stop the walk — the step is flagged and, where
 *    an edge exists, the walk continues so every problem surfaces in one pass;
 *    everything reached only past that terminal failure is flagged
 *    production_stops_here.
 *
 * The resolver injected here is the redacting VariableResolverForDryRun
 * virtualType (F7) — the one sanctioned deviation from share-everything, because
 * config interpolation runs ahead of the simulation branch and traces render in
 * the browser.
 */
class Walker
{
    private const CONFIG_MAX_DELAY_DAYS = 'mageos_workflows/guards/max_delay_days';
    private const DEFAULT_MAX_DELAY_DAYS = 365;

    public function __construct(
        private readonly ConditionEvaluator $conditionEvaluator,
        private readonly HydrationProviderInterface $hydrationProvider,
        private readonly VariableResolver $variableResolver,
        private readonly ActionPool $actionPool,
        private readonly DelayCalculator $delayCalculator,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Walk the definition from $entryKey and assemble the flat trace.
     *
     * @return array{steps: TraceStep[], truncated: bool}
     */
    public function walk(
        Definition $definition,
        ExecutionContext $ctx,
        string $entityType,
        string $entryKey,
        int $maxDistinctSteps
    ): array {
        $explorer = new PathExplorer($maxDistinctSteps);
        $explorer->seed($entryKey);

        while (($visit = $explorer->next()) !== null) {
            $step = $definition->getStep($visit->getStepKey());
            $evaluation = $this->evaluate($visit, $definition, $ctx, $entityType, $step);
            $explorer->place(
                $visit,
                $evaluation['step'],
                $evaluation['edges'],
                $evaluation['production_stops']
            );
        }

        return ['steps' => $explorer->getSteps(), 'truncated' => $explorer->isTruncated()];
    }

    /**
     * @param array $step raw step node
     * @return array{step: TraceStep, edges: array<int, array{label: string, target: ?string}>, production_stops: bool}
     */
    private function evaluate(
        Visit $visit,
        Definition $definition,
        ExecutionContext $ctx,
        string $entityType,
        array $step
    ): array {
        $edges = $definition->getStepEdges($visit->getStepKey());

        $evaluation = match ($step['type'] ?? null) {
            Definition::STEP_ACTION => $this->evaluateAction($visit, $ctx, $step, $edges),
            Definition::STEP_DELAY => $this->evaluateDelay($visit, $ctx, $step, $edges),
            Definition::STEP_WAIT => $this->evaluateWait($visit, $ctx, $step, $edges),
            Definition::STEP_BRANCH => $this->evaluateBranch($visit, $ctx, $entityType, $step, $edges),
            Definition::STEP_SWITCH => $this->evaluateSwitch($visit, $ctx, $entityType, $step, $edges),
            default => $this->evaluateStop($visit, $step),
        };

        // Steps reached only because the walk continued past a terminal failure
        // production would have stopped on: flag them, but keep a fresh failure
        // visible (surfacing every problem is the point).
        if ($visit->isPastProductionStop()
            && in_array($evaluation['step']->getStatus(), [TraceStepStatus::WOULD_RUN, TraceStepStatus::SKIPPED], true)
        ) {
            $evaluation['step']->setStatus(TraceStepStatus::PRODUCTION_STOPS_HERE);
            $evaluation['step']->addNote((string) __('Production would have stopped before reaching this step.'));
        }

        return $evaluation;
    }

    /**
     * @param array $step
     * @param array<string, ?string> $edges
     * @return array{step: TraceStep, edges: array<int, array{label: string, target: ?string}>, production_stops: bool}
     */
    private function evaluateAction(Visit $visit, ExecutionContext $ctx, array $step, array $edges): array
    {
        $code = (string) ($step['action'] ?? '');
        $follow = [['label' => 'next', 'target' => $edges['next'] ?? null]];

        if (!$this->actionPool->has($code)) {
            return [
                'step' => new TraceStep(
                    $visit->getStepKey(),
                    Definition::STEP_ACTION,
                    TraceStepStatus::WOULD_FAIL,
                    (string) __('Unknown workflow action "%1"', $code),
                    is_array($step['config'] ?? null) ? $step['config'] : [],
                    null,
                    null,
                    'next',
                    [(string) __('Production would stop here (unknown action).')]
                ),
                'edges' => $follow,
                'production_stops' => true,
            ];
        }

        $config = $this->variableResolver->resolveConfig(
            is_array($step['config'] ?? null) ? $step['config'] : [],
            $ctx
        );
        $action = $this->actionPool->get($code);

        if (!$action instanceof SimulateableActionInterface) {
            return [
                'step' => new TraceStep(
                    $visit->getStepKey(),
                    Definition::STEP_ACTION,
                    TraceStepStatus::WOULD_RUN,
                    (string) __('Would run %1', $code),
                    $config,
                    null,
                    null,
                    'next',
                    [(string) __('This action does not support simulation; its effect cannot be previewed.')]
                ),
                'edges' => $follow,
                'production_stops' => false,
            ];
        }

        try {
            $result = $action->simulate($ctx, $config);
        } catch (\Throwable $e) {
            return [
                'step' => new TraceStep(
                    $visit->getStepKey(),
                    Definition::STEP_ACTION,
                    TraceStepStatus::WOULD_FAIL,
                    (string) __('Would fail: %1', $e->getMessage()),
                    $config,
                    null,
                    null,
                    'next',
                    [(string) __('Production would stop here.')]
                ),
                'edges' => $follow,
                'production_stops' => true,
            ];
        }

        if ($result->isFailure()) {
            return [
                'step' => new TraceStep(
                    $visit->getStepKey(),
                    Definition::STEP_ACTION,
                    TraceStepStatus::WOULD_FAIL,
                    (string) __('Would fail: %1', (string) ($result->getError() ?? __('Action failed'))),
                    $config,
                    null,
                    null,
                    'next',
                    [(string) __('Production would stop here.')]
                ),
                'edges' => $follow,
                'production_stops' => true,
            ];
        }

        $output = $result->getOutput();
        $status = $result->isSuccess() ? TraceStepStatus::WOULD_RUN : TraceStepStatus::SKIPPED;
        $would = isset($output['would']) && is_string($output['would'])
            ? $output['would']
            : (string) __('Would run %1', $code);

        return [
            'step' => new TraceStep(
                $visit->getStepKey(),
                Definition::STEP_ACTION,
                $status,
                $would,
                $config,
                null,
                null,
                'next'
            ),
            'edges' => $follow,
            'production_stops' => false,
        ];
    }

    /**
     * @param array $step
     * @param array<string, ?string> $edges
     * @return array{step: TraceStep, edges: array<int, array{label: string, target: ?string}>, production_stops: bool}
     */
    private function evaluateDelay(Visit $visit, ExecutionContext $ctx, array $step, array $edges): array
    {
        $config = is_array($step['config'] ?? null) ? $step['config'] : [];
        $timing = $this->computeTiming($ctx, $config);
        $would = $timing['clamped']
            ? (string) __('Would wait until %1 (%2) — clamped to the maximum delay ceiling', $timing['resume_at'], $timing['timezone'])
            : (string) __('Would wait until %1 (%2)', $timing['resume_at'], $timing['timezone']);

        return [
            'step' => new TraceStep(
                $visit->getStepKey(),
                Definition::STEP_DELAY,
                TraceStepStatus::WOULD_RUN,
                $would,
                $config,
                null,
                $timing,
                'next'
            ),
            'edges' => [['label' => 'next', 'target' => $edges['next'] ?? null]],
            'production_stops' => false,
        ];
    }

    /**
     * @param array $step
     * @param array<string, ?string> $edges
     * @return array{step: TraceStep, edges: array<int, array{label: string, target: ?string}>, production_stops: bool}
     */
    private function evaluateWait(Visit $visit, ExecutionContext $ctx, array $step, array $edges): array
    {
        $config = is_array($step['config'] ?? null) ? $step['config'] : [];
        $event = (string) ($config['event'] ?? '');
        $timing = $this->computeTiming($ctx, ['duration' => $config['timeout'] ?? 'PT0S']);

        return [
            'step' => new TraceStep(
                $visit->getStepKey(),
                Definition::STEP_WAIT,
                TraceStepStatus::WOULD_RUN,
                (string) __('Would wait for event "%1" (dry-run explores both outcomes)', $event),
                $config,
                null,
                $timing,
                null,
                [
                    (string) __('If "%1" fires by %2 → on_event path.', $event, $timing['resume_at']),
                    (string) __('If it does not → on_timeout path.'),
                ]
            ),
            'edges' => [
                ['label' => 'on_event', 'target' => $edges['on_event'] ?? null],
                ['label' => 'on_timeout', 'target' => $edges['on_timeout'] ?? null],
            ],
            'production_stops' => false,
        ];
    }

    /**
     * @param array $step
     * @param array<string, ?string> $edges
     * @return array{step: TraceStep, edges: array<int, array{label: string, target: ?string}>, production_stops: bool}
     */
    private function evaluateBranch(Visit $visit, ExecutionContext $ctx, string $entityType, array $step, array $edges): array
    {
        $serialized = is_string($step['conditions_serialized'] ?? null) ? (string) $step['conditions_serialized'] : '';
        $revalidate = (bool) ($step['revalidate_entity'] ?? true);

        [$result, $failed] = $this->evaluateConditionTree($serialized, $entityType, $ctx, $revalidate);

        if ($failed) {
            // A tree that cannot be evaluated fails closed to the false edge in
            // production (ConditionEvaluator), silently. Dry-run marks the step
            // and explores both edges so it never fabricates one confident path.
            return [
                'step' => new TraceStep(
                    $visit->getStepKey(),
                    Definition::STEP_BRANCH,
                    TraceStepStatus::WOULD_FAIL,
                    (string) __('Condition could not be evaluated — production would follow the on_false edge'),
                    [],
                    ['serialized' => $serialized, 'result' => false, 'revalidated' => $revalidate],
                    null,
                    null,
                    [(string) __('Dry-run explores both edges because the condition is unevaluable.')]
                ),
                'edges' => [
                    ['label' => 'on_true', 'target' => $edges['on_true'] ?? null],
                    ['label' => 'on_false', 'target' => $edges['on_false'] ?? null],
                ],
                'production_stops' => false,
            ];
        }

        $edgeTaken = $result ? 'on_true' : 'on_false';
        $condition = $serialized === ''
            ? null
            : ['serialized' => $serialized, 'result' => $result, 'revalidated' => $revalidate];
        $would = $serialized === ''
            ? (string) __('No condition — would follow on_true')
            : ($result
                ? (string) __('Condition matched — would follow on_true')
                : (string) __('Condition did not match — would follow on_false'));

        return [
            'step' => new TraceStep(
                $visit->getStepKey(),
                Definition::STEP_BRANCH,
                TraceStepStatus::WOULD_RUN,
                $would,
                [],
                $condition,
                null,
                $edgeTaken,
                $revalidate ? [(string) __('Evaluated against current entity state, not the state at trigger time.')] : []
            ),
            'edges' => [['label' => $edgeTaken, 'target' => $edges[$edgeTaken] ?? null]],
            'production_stops' => false,
        ];
    }

    /**
     * @param array $step
     * @param array<string, ?string> $edges
     * @return array{step: TraceStep, edges: array<int, array{label: string, target: ?string}>, production_stops: bool}
     */
    private function evaluateSwitch(Visit $visit, ExecutionContext $ctx, string $entityType, array $step, array $edges): array
    {
        $revalidate = (bool) ($step['revalidate_entity'] ?? true);
        $matched = null;
        $edgeTaken = null;
        $notes = [];

        foreach ((array) ($step['cases'] ?? []) as $case) {
            if (!is_array($case) || !isset($case['key'])) {
                continue;
            }
            $serialized = is_string($case['conditions_serialized'] ?? null) ? (string) $case['conditions_serialized'] : '';
            [$result, $failed] = $this->evaluateConditionTree($serialized, $entityType, $ctx, $revalidate);
            if ($failed) {
                // Any unevaluable case: explore every edge, like a wait.
                $follow = [];
                foreach ($step['cases'] as $c) {
                    if (is_array($c) && isset($c['key'])) {
                        $follow[] = ['label' => 'case:' . (string) $c['key'], 'target' => $edges['case:' . (string) $c['key']] ?? null];
                    }
                }
                $follow[] = ['label' => 'default', 'target' => $edges['default'] ?? null];
                return [
                    'step' => new TraceStep(
                        $visit->getStepKey(),
                        Definition::STEP_SWITCH,
                        TraceStepStatus::WOULD_FAIL,
                        (string) __('A case condition could not be evaluated — production would fall through toward "default"'),
                        [],
                        null,
                        null,
                        null,
                        [(string) __('Dry-run explores every case edge because a case is unevaluable.')]
                    ),
                    'edges' => $follow,
                    'production_stops' => false,
                ];
            }
            if ($result) {
                $matched = (string) $case['key'];
                $edgeTaken = 'case:' . $matched;
                break;
            }
        }

        if ($matched === null) {
            $edgeTaken = 'default';
            $notes[] = (string) __('No case matched — would follow the default edge.');
            $would = (string) __('No case matched — would follow "default"');
        } else {
            $would = (string) __('Case "%1" matched — would follow it', $matched);
        }

        return [
            'step' => new TraceStep(
                $visit->getStepKey(),
                Definition::STEP_SWITCH,
                TraceStepStatus::WOULD_RUN,
                $would,
                [],
                null,
                null,
                $edgeTaken,
                $notes
            ),
            'edges' => [['label' => (string) $edgeTaken, 'target' => $edges[(string) $edgeTaken] ?? null]],
            'production_stops' => false,
        ];
    }

    /**
     * @param array $step
     * @return array{step: TraceStep, edges: array<int, array{label: string, target: ?string}>, production_stops: bool}
     */
    private function evaluateStop(Visit $visit, array $step): array
    {
        return [
            'step' => new TraceStep(
                $visit->getStepKey(),
                (string) ($step['type'] ?? Definition::STEP_STOP),
                TraceStepStatus::WOULD_RUN,
                (string) __('Workflow ends here'),
                [],
                null,
                null,
                null
            ),
            'edges' => [],
            'production_stops' => false,
        ];
    }

    /**
     * Evaluate a serialized condition tree the way production does, but report
     * whether it was unevaluable (malformed tree, or a revalidation that finds
     * no entity) rather than silently collapsing to false.
     *
     * @return array{0: bool, 1: bool} [result, failed]
     */
    private function evaluateConditionTree(string $serialized, string $entityType, ExecutionContext $ctx, bool $revalidate): array
    {
        if (trim($serialized) === '') {
            return [true, false];
        }
        if ($revalidate && $ctx->getEntityId() > 0
            && $this->hydrationProvider->getEntity($entityType, $ctx->getEntityId(), false) === null
        ) {
            return [false, true];
        }
        try {
            return [$this->conditionEvaluator->evaluateSerialized($serialized, $entityType, $ctx, $revalidate), false];
        } catch (\InvalidArgumentException $e) {
            return [false, true];
        }
    }

    /**
     * Resolve a delay/wait resume time via the shared DelayCalculator — the
     * same store timezone and ceiling clamp production uses. Dry-run only
     * annotates it; it never parks.
     *
     * @param array $config
     * @return array{resume_at: string, clamped: bool, timezone: string}
     */
    private function computeTiming(ExecutionContext $ctx, array $config): array
    {
        $maxDays = (int) $this->scopeConfig->getValue(self::CONFIG_MAX_DELAY_DAYS);
        if ($maxDays <= 0) {
            $maxDays = self::DEFAULT_MAX_DELAY_DAYS;
        }
        $timezone = (string) $this->scopeConfig->getValue(
            'general/locale/timezone',
            ScopeInterface::SCOPE_STORE,
            (string) $ctx->getStoreId()
        );
        if ($timezone === '') {
            $timezone = 'UTC';
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        try {
            [$resume, $clamped] = $this->delayCalculator->computeResumeAt($now, $config, $timezone, $maxDays);
        } catch (\InvalidArgumentException $e) {
            $resume = $now;
            $clamped = false;
        }

        return [
            'resume_at' => $resume->format('Y-m-d H:i:s'),
            'clamped' => $clamped,
            'timezone' => $timezone,
        ];
    }
}
