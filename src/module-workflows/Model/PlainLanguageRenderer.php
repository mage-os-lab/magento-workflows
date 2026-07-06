<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model;

use MageOS\Workflows\Api\ActionMetadataInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\Action\ActionPool;
use MageOS\Workflows\Model\Definition\Definition;
use MageOS\Workflows\Model\Engine\FanOutExpander;
use MageOS\Workflows\Model\Relation\RelationPool;
use MageOS\Workflows\Model\Rule\Condition\RelatedEntity\Combine as RelatedEntityCombine;
use MageOS\Workflows\Model\Trigger\TriggerRegistry;

/**
 * Walks a workflow's trigger + conditions + step graph and produces the merchant-readable
 * sentence described in docs/11-admin-ui.md#merchant-accessibility--openness, e.g.:
 *
 *   "When Order Created, if 2 conditions, then: Add Order Comment, wait 1 hour, stop."
 *
 * Relocated from module-workflows-admin-ui (F6): the validate endpoint, dry-run traces,
 * and gallery previews live in core/webapi and must not depend on the admin-ui module;
 * admin-ui keeps its grid column as a thin consumer.
 *
 * Rendering coverage: action, delay, stop, branch (both edges — a non-null on_false
 * renders an inline "otherwise: …" chain; the legacy on_false = null shape renders
 * byte-identically to the pre-relocation output so existing grid rows do not change),
 * wait (event + timeout), switch (case keys listed, walk continues down the first
 * case), and approval (all three outcomes described inline — the timeout consequence
 * must be unmissable). Edge topology comes from Definition::getStepEdges (F1).
 *
 * Deliberately defensive: a malformed/partial definition or condition tree (mid-edit, bad
 * import) degrades to omitting that clause rather than throwing -- this class is called from
 * grid rendering, where a hard failure would break the whole listing.
 */
class PlainLanguageRenderer
{
    private const MAX_STEPS = 25;

    public function __construct(
        private readonly ActionPool $actionPool,
        private readonly TriggerRegistry $triggerRegistry,
        private readonly RelationPool $relationPool
    ) {
    }

    public function render(WorkflowInterface $workflow): string
    {
        return $this->renderFromFields(
            $workflow->getTriggerType(),
            $workflow->getTriggerRef(),
            $workflow->getEntityType(),
            $workflow->getConditionsSerialized(),
            $workflow->getDefinition(),
            $workflow->getFanOut(),
            $workflow->getAggregation()
        );
    }

    /**
     * Primitive-typed entry point so callers (e.g. a grid column reading raw row data) don't
     * need to hydrate a full WorkflowInterface just to render the summary.
     *
     * An aggregated workflow (non-null $aggregationJson, 05) renders in plain
     * batch language — "Once a day, as one digest: …" — instead of the
     * per-entity "When … then …" framing.
     */
    public function renderFromFields(
        string $triggerType,
        string $triggerRef,
        string $entityType,
        ?string $conditionsSerialized,
        string $definitionJson,
        ?string $fanOut = null,
        ?string $aggregationJson = null
    ): string {
        $batchPrefix = $this->batchCadence($aggregationJson);
        if ($batchPrefix !== '') {
            return $this->renderBatchSentence($batchPrefix, $conditionsSerialized, $definitionJson);
        }

        $sentence = (string) __('When %1', $this->resolveTriggerLabel($triggerType, $triggerRef, $entityType));

        // Fan-out leads with the per-target phrasing (the single most important
        // comprehension detail — a merchant who reads this as "runs once" is
        // surprised in the worst way): "for each of <relation> (up to N)".
        $fanOutClause = $this->renderFanOutClause($fanOut);
        if ($fanOutClause !== '') {
            $sentence .= (string) __(', %1', $fanOutClause);
        }

        $conditionCount = $this->countConditions($conditionsSerialized);
        $relationClause = $this->renderRelationClause($conditionsSerialized);
        if ($conditionCount > 0) {
            $sentence .= (string) ($conditionCount === 1
                ? __(', if %1 condition', $conditionCount)
                : __(', if %1 conditions', $conditionCount));
            if ($relationClause !== '') {
                $sentence .= (string) __(' and %1', $relationClause);
            }
        } elseif ($relationClause !== '') {
            // A bare existence check (the flagship guest node) carries no
            // attribute leaves, so the count is 0 — describe it explicitly
            // rather than dropping the whole condition clause.
            $sentence .= (string) __(', if %1', $relationClause);
        }

        $steps = $this->renderSteps($definitionJson);
        if ($steps !== []) {
            $sentence .= (string) __(', then: %1', implode(', ', $steps));
        }

        return $sentence . '.';
    }

    /**
     * "for each of <relation label> (up to N)" — the fan-out per-target lead.
     * Returns '' when the workflow does not fan out or the clause is unreadable.
     * When no per-workflow cap is set, the global default is shown so the
     * sentence always renders a number (the cap is the load-bearing detail).
     */
    private function renderFanOutClause(?string $fanOut): string
    {
        if ($fanOut === null || trim($fanOut) === '') {
            return '';
        }
        $config = json_decode($fanOut, true);
        $code = is_array($config) ? trim((string) ($config['relation'] ?? '')) : '';
        if ($code === '') {
            return '';
        }
        $label = $this->relationPool->has($code) ? $this->relationPool->get($code)->getLabel() : $code;
        $cap = (is_array($config) && isset($config['cap']) && (int) $config['cap'] > 0)
            ? (int) $config['cap']
            : FanOutExpander::DEFAULT_FAN_OUT_CAP;

        return (string) __('for each of %1 (up to %2)', $label, $cap);
    }

    /**
     * Batch phrasing for aggregated workflows: "<cadence>, as one digest, for
     * everything that matches <N conditions>: <actions>."
     */
    private function renderBatchSentence(string $cadence, ?string $conditionsSerialized, string $definitionJson): string
    {
        $conditionCount = $this->countConditions($conditionsSerialized);
        $sentence = (string) __('%1, as one digest', $cadence);
        if ($conditionCount > 0) {
            $sentence .= (string) ($conditionCount === 1
                ? __(', for everything that matches %1 condition', $conditionCount)
                : __(', for everything that matches %1 conditions', $conditionCount));
        } else {
            $sentence .= (string) __(', for everything');
        }

        $steps = $this->renderSteps($definitionJson);
        if ($steps !== []) {
            $sentence .= (string) __(', then: %1', implode(', ', $steps));
        }

        return $sentence . '.';
    }

    /**
     * Coarse human cadence from the aggregation window policy. Deliberately
     * approximate (exact cron humanization is out of scope); defensive against
     * a malformed column (returns '' so the caller falls back to per-entity
     * phrasing rather than throwing in a grid row).
     */
    private function batchCadence(?string $aggregationJson): string
    {
        if ($aggregationJson === null || trim($aggregationJson) === '') {
            return '';
        }
        try {
            $config = \MageOS\Workflows\Model\Aggregation\AggregationConfig::fromJson($aggregationJson);
        } catch (\InvalidArgumentException $e) {
            return '';
        }
        if ($config === null) {
            return '';
        }

        $window = $config->getWindow();
        $type = $config->getWindowType();
        if ($config->isCollected() || $type === null) {
            return (string) __('On a schedule');
        }
        if ($type === \MageOS\Workflows\Model\Aggregation\AggregationConfig::WINDOW_INTERVAL) {
            $duration = $config->getDuration();
            return $duration !== null
                ? (string) __('Every %1', $this->humanizeDuration($duration))
                : (string) __('At an interval');
        }
        // schedule
        $cron = $config->getCron();
        return $cron !== null ? $this->humanizeCron($cron) : (string) __('On a schedule');
    }

    private function humanizeCron(string $cron): string
    {
        $parts = preg_split('/\s+/', trim($cron)) ?: [];
        if (count($parts) !== 5) {
            return (string) __('On a schedule');
        }
        [$minute, $hour, $dom, $month, $dow] = $parts;
        if ($dom === '*' && $month === '*' && $dow === '*' && $hour !== '*' && !str_contains($hour, '*')) {
            return (string) __('Once a day');
        }
        if ($dom === '*' && $month === '*' && $dow !== '*') {
            return (string) __('Once a week');
        }
        if ($hour === '*' && $minute !== '*') {
            return (string) __('Every hour');
        }
        return (string) __('On a schedule');
    }

    private function resolveTriggerLabel(string $triggerType, string $triggerRef, string $entityType): string
    {
        if ($triggerType === WorkflowInterface::TRIGGER_TYPE_EVENT) {
            foreach ($this->triggerRegistry->getAll() as $trigger) {
                if (($trigger['event'] ?? null) === $triggerRef) {
                    return (string) $trigger['label'];
                }
            }
            return $triggerRef !== ''
                ? (string) __('"%1" occurs', $triggerRef)
                : (string) __('an event occurs');
        }
        if ($triggerType === WorkflowInterface::TRIGGER_TYPE_SCHEDULE) {
            return $triggerRef !== ''
                ? (string) __('on schedule "%1"', $triggerRef)
                : (string) __('on schedule');
        }
        if ($triggerType === WorkflowInterface::TRIGGER_TYPE_MANUAL) {
            return (string) __(
                'manually run on %1',
                $entityType !== '' ? $entityType : (string) __('an entity')
            );
        }
        return $triggerRef !== '' ? $triggerRef : (string) __('an event occurs');
    }

    /**
     * conditions_serialized shares the Magento\Rule condition-tree shape (salesrule/catalogrule
     * lineage): a combine node with a "conditions" array of child nodes; leaves carry
     * "attribute". No formal schema is exposed as peer context here, so this is best-effort --
     * an unrecognized/legacy shape degrades to a count of 0 rather than throwing.
     */
    private function countConditions(?string $conditionsSerialized): int
    {
        if ($conditionsSerialized === null || trim($conditionsSerialized) === '') {
            return 0;
        }
        $tree = json_decode($conditionsSerialized, true);
        if (!is_array($tree)) {
            return 0;
        }
        return $this->countLeaves($tree);
    }

    private function countLeaves(array $node): int
    {
        if (isset($node['attribute'])) {
            return 1;
        }
        $count = 0;
        foreach ($node['conditions'] ?? [] as $child) {
            if (is_array($child)) {
                $count += $this->countLeaves($child);
            }
        }
        return $count;
    }

    /**
     * Describe the RelatedEntity existence nodes in a condition tree, e.g.
     * "a customer account matching the order email does not exist". Joined by
     * "and" and appended to the condition clause so the flagship guest check
     * (childless NOT EXISTS, zero attribute leaves) still reads in plain
     * language. Returns '' when the tree has no relation nodes.
     */
    private function renderRelationClause(?string $conditionsSerialized): string
    {
        if ($conditionsSerialized === null || trim($conditionsSerialized) === '') {
            return '';
        }
        $tree = json_decode($conditionsSerialized, true);
        if (!is_array($tree)) {
            return '';
        }
        $phrases = [];
        $this->collectRelationPhrases($tree, $phrases);
        return implode((string) __(' and '), $phrases);
    }

    /**
     * @param string[] $phrases
     */
    private function collectRelationPhrases(array $node, array &$phrases): void
    {
        if (($node['type'] ?? null) === RelatedEntityCombine::class) {
            $phrase = $this->relationPhrase($node);
            if ($phrase !== '') {
                $phrases[] = $phrase;
            }
        }
        foreach ($node['conditions'] ?? [] as $child) {
            if (is_array($child)) {
                $this->collectRelationPhrases($child, $phrases);
            }
        }
    }

    private function relationPhrase(array $node): string
    {
        $code = trim((string) ($node['relation'] ?? ''));
        if ($code === '') {
            return '';
        }
        $label = $this->relationPool->has($code) ? $this->relationPool->get($code)->getLabel() : $code;
        // EXISTS is value 1; NOT EXISTS is value 0 (absent value = EXISTS).
        $exists = !array_key_exists('value', $node) || (string) $node['value'] !== '0';
        return (string) ($exists ? __('%1 exists', $label) : __('%1 does not exist', $label));
    }

    /**
     * @return string[]
     */
    private function renderSteps(string $definitionJson): array
    {
        try {
            $definition = Definition::fromJson($definitionJson);
        } catch (\InvalidArgumentException $e) {
            return [];
        }

        $entry = $definition->getEntryKey();
        if ($entry === null) {
            return [];
        }

        $visited = [];
        return $this->renderChain($definition, $entry, $visited);
    }

    /**
     * Walk one chain of steps. $visited is shared across the whole render
     * (including nested "otherwise" chains) so cycles and diamonds terminate
     * and the global MAX_STEPS budget holds.
     *
     * @param array<string, true> $visited
     * @return string[]
     */
    private function renderChain(Definition $definition, ?string $key, array &$visited): array
    {
        $summaries = [];
        while ($key !== null && !isset($visited[$key]) && count($visited) < self::MAX_STEPS) {
            $visited[$key] = true;
            if (!$definition->hasStep($key)) {
                break;
            }
            $step = $definition->getStep($key);
            $edges = $definition->getStepEdges($key);

            switch ($step['type'] ?? null) {
                case Definition::STEP_ACTION:
                    $summaries[] = $this->renderActionStep($step);
                    $key = $edges['next'];
                    break;
                case Definition::STEP_DELAY:
                    $summaries[] = $this->renderDelayStep($step);
                    $key = $edges['next'];
                    break;
                case Definition::STEP_BRANCH:
                    $summaries[] = $this->renderBranchStep($definition, $step, $edges, $visited);
                    $key = $edges['on_true'];
                    break;
                case Definition::STEP_WAIT:
                    $summaries[] = $this->renderWaitStep($step);
                    $key = $edges['on_event'] ?? $edges['on_timeout'];
                    break;
                case Definition::STEP_SWITCH:
                    $summaries[] = $this->renderSwitchStep($edges);
                    $key = $this->firstSwitchTarget($edges);
                    break;
                case Definition::STEP_APPROVAL:
                    // All three outcomes render inline, so the main chain ends here.
                    $summaries[] = $this->renderApprovalStep($definition, $step, $edges, $visited);
                    $key = null;
                    break;
                case Definition::STEP_STOP:
                    $summaries[] = (string) __('stop');
                    $key = null;
                    break;
                default:
                    $summaries[] = (string) __('unknown step');
                    $key = null;
                    break;
            }
        }

        return $summaries;
    }

    private function renderActionStep(array $step): string
    {
        $code = (string) ($step['action'] ?? '');
        if ($code !== '' && $this->actionPool->has($code)) {
            $action = $this->actionPool->get($code);
            if ($action instanceof ActionMetadataInterface) {
                return $action->getLabel();
            }
        }
        return $code !== '' ? $code : (string) __('run an action');
    }

    private function renderDelayStep(array $step): string
    {
        $duration = (string) ($step['config']['duration'] ?? '');
        return (string) __('wait %1', $this->humanizeDuration($duration));
    }

    /**
     * Legacy shape (on_false null — everything the v1 form assembler emits)
     * renders byte-identically to the pre-relocation output. A non-null
     * on_false renders its chain inline as "otherwise: …".
     *
     * @param array<string, ?string> $edges
     * @param array<string, true> $visited
     */
    private function renderBranchStep(Definition $definition, array $step, array $edges, array &$visited): string
    {
        $serialized = $step['conditions_serialized'] ?? null;
        $count = $this->countConditions(is_string($serialized) ? $serialized : null);

        if ($edges['on_false'] === null) {
            if ($count === 0) {
                return (string) __('check a condition');
            }
            return (string) ($count === 1
                ? __('if %1 more condition still holds', $count)
                : __('if %1 more conditions still hold', $count));
        }

        $condition = match (true) {
            $count === 0 => (string) __('always'),
            $count === 1 => (string) __('if %1 more condition still holds', $count),
            default => (string) __('if %1 more conditions still hold', $count),
        };
        $otherwise = $this->renderChain($definition, $edges['on_false'], $visited);
        if ($otherwise === []) {
            return $condition;
        }
        return (string) __('%1 (otherwise: %2)', $condition, implode(', ', $otherwise));
    }

    private function renderWaitStep(array $step): string
    {
        $event = (string) ($step['config']['event'] ?? '');
        $timeout = (string) ($step['config']['timeout'] ?? '');
        if ($event === '') {
            return (string) __('wait for an event');
        }
        if ($timeout === '') {
            return (string) __('wait for "%1"', $event);
        }
        return (string) __('wait for "%1" up to %2', $event, $this->humanizeDuration($timeout));
    }

    /**
     * Approval gate (docs/discovery/approval-gate.md §6): "wait up to 3 days for
     * a decision (role: sales_managers): if approved → …, if rejected → …, if no
     * decision by then → …". All three outcomes are described inline (recursing
     * over the shared $visited budget like a branch's "otherwise"); the timeout
     * consequence must be unmissable — silence takes a branch.
     *
     * @param array<string, ?string> $edges
     * @param array<string, true> $visited
     */
    private function renderApprovalStep(Definition $definition, array $step, array $edges, array &$visited): string
    {
        $timeout = (string) ($step['config']['timeout'] ?? '');
        $role = trim((string) ($step['config']['assignee_role'] ?? ''));

        $lead = $timeout === ''
            ? (string) __('wait for a decision')
            : (string) __('wait up to %1 for a decision', $this->humanizeDuration($timeout));
        if ($role !== '') {
            $lead .= (string) __(' (role: %1)', $role);
        }

        return (string) __(
            '%1: if approved → %2, if rejected → %3, if no decision by then → %4',
            $lead,
            $this->renderOutcome($definition, $edges['on_approved'] ?? null, $visited),
            $this->renderOutcome($definition, $edges['on_rejected'] ?? null, $visited),
            $this->renderOutcome($definition, $edges['on_timeout'] ?? null, $visited)
        );
    }

    /**
     * One approval outcome edge: its continuation chain, or "the workflow ends"
     * when the edge is null (a legal author choice for on_timeout).
     *
     * @param array<string, true> $visited
     */
    private function renderOutcome(Definition $definition, ?string $key, array &$visited): string
    {
        if ($key === null) {
            return (string) __('the workflow ends');
        }
        $chain = $this->renderChain($definition, $key, $visited);
        return $chain === [] ? (string) __('the workflow ends') : implode(', ', $chain);
    }

    /**
     * @param array<string, ?string> $edges getStepEdges output: case:<key> entries + default
     */
    private function renderSwitchStep(array $edges): string
    {
        $caseKeys = [];
        foreach ($edges as $edge => $target) {
            if (str_starts_with($edge, 'case:')) {
                $caseKeys[] = substr($edge, strlen('case:'));
            }
        }
        $count = count($caseKeys);
        return (string) ($count === 1
            ? __('first match of %1 case (%2)', $count, implode(', ', $caseKeys))
            : __('first match of %1 cases (%2)', $count, implode(', ', $caseKeys)));
    }

    /**
     * Primary continuation of a switch for the one-line summary: the first
     * case's target, falling back to the default edge.
     *
     * @param array<string, ?string> $edges
     */
    private function firstSwitchTarget(array $edges): ?string
    {
        foreach ($edges as $edge => $target) {
            if (str_starts_with($edge, 'case:') && $target !== null) {
                return $target;
            }
        }
        return $edges['default'] ?? null;
    }

    private function humanizeDuration(string $iso8601): string
    {
        if ($iso8601 === '') {
            return (string) __('a while');
        }
        try {
            $interval = new \DateInterval($iso8601);
        } catch (\Exception $e) {
            return $iso8601;
        }

        $parts = [];
        $units = [
            'y' => ['%1 year', '%1 years'],
            'm' => ['%1 month', '%1 months'],
            'd' => ['%1 day', '%1 days'],
            'h' => ['%1 hour', '%1 hours'],
            'i' => ['%1 minute', '%1 minutes'],
            's' => ['%1 second', '%1 seconds'],
        ];
        foreach ($units as $property => [$singular, $plural]) {
            $value = $interval->$property;
            if ($value) {
                $parts[] = (string) __($value === 1 ? $singular : $plural, $value);
            }
        }

        return $parts !== [] ? implode(' ', $parts) : (string) __('a moment');
    }
}
