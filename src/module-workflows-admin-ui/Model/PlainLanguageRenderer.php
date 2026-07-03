<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Model;

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

        $summaries = [];
        $visited = [];
        $key = $entry;
        while ($key !== null && !isset($visited[$key]) && count($summaries) < self::MAX_STEPS) {
            $visited[$key] = true;
            if (!$definition->hasStep($key)) {
                break;
            }
            $step = $definition->getStep($key);
            $summaries[] = $this->renderStep($step);
            $key = match ($step['type'] ?? null) {
                Definition::STEP_ACTION, Definition::STEP_DELAY => $step['next'] ?? null,
                Definition::STEP_BRANCH => $step['on_true'] ?? null,
                default => null,
            };
        }

        return $summaries;
    }

    private function renderStep(array $step): string
    {
        return match ($step['type'] ?? null) {
            Definition::STEP_ACTION => $this->renderActionStep($step),
            Definition::STEP_DELAY => $this->renderDelayStep($step),
            Definition::STEP_BRANCH => $this->renderBranchStep($step),
            Definition::STEP_STOP => (string) __('stop'),
            default => (string) __('unknown step'),
        };
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

    private function renderBranchStep(array $step): string
    {
        $serialized = $step['conditions_serialized'] ?? null;
        $count = $this->countConditions(is_string($serialized) ? $serialized : null);
        if ($count === 0) {
            return (string) __('check a condition');
        }
        return (string) ($count === 1
            ? __('if %1 more condition still holds', $count)
            : __('if %1 more conditions still hold', $count));
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
