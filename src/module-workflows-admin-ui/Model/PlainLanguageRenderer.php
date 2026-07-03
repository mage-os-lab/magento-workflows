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
        $sentence = sprintf('When %s', $this->resolveTriggerLabel($triggerType, $triggerRef, $entityType));

        $conditionCount = $this->countConditions($conditionsSerialized);
        if ($conditionCount > 0) {
            $sentence .= sprintf(', if %d condition%s', $conditionCount, $conditionCount === 1 ? '' : 's');
        }

        $steps = $this->renderSteps($definitionJson);
        if ($steps !== []) {
            $sentence .= sprintf(', then: %s', implode(', ', $steps));
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
            return $triggerRef !== '' ? sprintf('"%s" occurs', $triggerRef) : 'an event occurs';
        }
        if ($triggerType === WorkflowInterface::TRIGGER_TYPE_SCHEDULE) {
            return $triggerRef !== '' ? sprintf('on schedule "%s"', $triggerRef) : 'on schedule';
        }
        if ($triggerType === WorkflowInterface::TRIGGER_TYPE_MANUAL) {
            return sprintf('manually run on %s', $entityType !== '' ? $entityType : 'an entity');
        }
        return $triggerRef !== '' ? $triggerRef : 'an event occurs';
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
            Definition::STEP_STOP => 'stop',
            default => 'unknown step',
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
        return $code !== '' ? $code : 'run an action';
    }

    private function renderDelayStep(array $step): string
    {
        $duration = (string) ($step['config']['duration'] ?? '');
        return sprintf('wait %s', $this->humanizeDuration($duration));
    }

    private function renderBranchStep(array $step): string
    {
        $serialized = $step['conditions_serialized'] ?? null;
        $count = $this->countConditions(is_string($serialized) ? $serialized : null);
        return $count > 0
            ? sprintf('if %d more condition%s still hold', $count, $count === 1 ? '' : 's')
            : 'check a condition';
    }

    private function humanizeDuration(string $iso8601): string
    {
        if ($iso8601 === '') {
            return 'a while';
        }
        try {
            $interval = new \DateInterval($iso8601);
        } catch (\Exception $e) {
            return $iso8601;
        }

        $parts = [];
        $units = ['y' => 'year', 'm' => 'month', 'd' => 'day', 'h' => 'hour', 'i' => 'minute', 's' => 'second'];
        foreach ($units as $property => $label) {
            $value = $interval->$property;
            if ($value) {
                $parts[] = $value . ' ' . $label . ($value === 1 ? '' : 's');
            }
        }

        return $parts !== [] ? implode(' ', $parts) : 'a moment';
    }
}
