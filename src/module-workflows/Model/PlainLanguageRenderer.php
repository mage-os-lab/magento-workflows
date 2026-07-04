<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model;

use MageOS\Workflows\Api\ActionMetadataInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\Action\ActionPool;
use MageOS\Workflows\Model\Definition\Definition;
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
 * wait (event + timeout), and switch (case keys listed, walk continues down the first
 * case). Edge topology comes from Definition::getStepEdges (F1).
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
        private readonly TriggerRegistry $triggerRegistry
    ) {
    }

    public function render(WorkflowInterface $workflow): string
    {
        return $this->renderFromFields(
            $workflow->getTriggerType(),
            $workflow->getTriggerRef(),
            $workflow->getEntityType(),
            $workflow->getConditionsSerialized(),
            $workflow->getDefinition()
        );
    }

    /**
     * Primitive-typed entry point so callers (e.g. a grid column reading raw row data) don't
     * need to hydrate a full WorkflowInterface just to render the summary.
     */
    public function renderFromFields(
        string $triggerType,
        string $triggerRef,
        string $entityType,
        ?string $conditionsSerialized,
        string $definitionJson
    ): string {
        $sentence = (string) __('When %1', $this->resolveTriggerLabel($triggerType, $triggerRef, $entityType));

        $conditionCount = $this->countConditions($conditionsSerialized);
        if ($conditionCount > 0) {
            $sentence .= (string) ($conditionCount === 1
                ? __(', if %1 condition', $conditionCount)
                : __(', if %1 conditions', $conditionCount));
        }

        $steps = $this->renderSteps($definitionJson);
        if ($steps !== []) {
            $sentence .= (string) __(', then: %1', implode(', ', $steps));
        }

        return $sentence . '.';
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
