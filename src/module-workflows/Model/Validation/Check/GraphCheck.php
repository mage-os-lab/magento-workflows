<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Validation\Check;

use MageOS\Workflows\Model\Definition\Definition;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationMessage;
use MageOS\Workflows\Model\Validation\ValidationSubject;

/**
 * Topological validation over Definition::getStepEdges (F2 / branching §2):
 *
 *  - cycle reachable from entry            => error   GRAPH_CYCLE
 *  - step unreachable from entry           => warning GRAPH_UNREACHABLE_STEP
 *  - branch/switch with ALL edges null     => warning GRAPH_DEAD_EDGE
 *    (deliberately a warning, not an error: the shipped form assembler can
 *    emit exactly this as a last-row branch, and re-save compatibility wins)
 *  - branch/switch directly after a delay
 *    or approval gate (both park for an
 *    unbounded stretch) with
 *    revalidate_entity: false              => warning GRAPH_POST_DELAY_STALE
 *
 * Compatibility bar (docs/discovery/implementation/01-branching.md): this
 * check never turns a currently-savable definition into an unsavable one —
 * a genuine cycle is the only new error on previously-valid input.
 */
class GraphCheck implements CheckInterface
{
    public const CODE_CYCLE = 'GRAPH_CYCLE';
    public const CODE_UNREACHABLE_STEP = 'GRAPH_UNREACHABLE_STEP';
    public const CODE_DEAD_EDGE = 'GRAPH_DEAD_EDGE';
    public const CODE_POST_DELAY_STALE = 'GRAPH_POST_DELAY_STALE';

    private const BRANCHING_TYPES = [Definition::STEP_BRANCH, Definition::STEP_SWITCH];

    /**
     * Steps that park for an unbounded stretch, after which the frozen trigger
     * snapshot is stale: a plain delay, a wait step that can sleep to its
     * timeout, and an approval gate that can do the same
     * (docs/discovery/approval-gate.md §4, docs/08-execution-model.md).
     */
    private const POST_PARK_STALE_SOURCES = [
        Definition::STEP_DELAY,
        Definition::STEP_WAIT,
        Definition::STEP_APPROVAL,
    ];

    /**
     * @inheritDoc
     */
    public function check(ValidationSubject $subject, ValidationContext $context): array
    {
        $definition = $subject->getDefinition();
        if ($definition === null) {
            return [];
        }
        $entry = $definition->getEntryKey();
        if ($entry === null) {
            return [];
        }

        $messages = [];
        $visited = [];
        $this->walk($definition, $entry, [], $visited, $messages);

        foreach (array_diff_key($definition->getSteps(), $visited) as $stepKey => $step) {
            $messages[] = ValidationMessage::warning(
                self::CODE_UNREACHABLE_STEP,
                (string) __('Step "%1" is not reachable from the entry step.', $stepKey),
                (string) $stepKey
            );
        }

        foreach ($definition->getSteps() as $stepKey => $step) {
            $stepKey = (string) $stepKey;
            $type = $step['type'] ?? null;
            if (in_array($type, self::BRANCHING_TYPES, true)
                && array_filter($definition->getStepEdges($stepKey), static fn ($t) => $t !== null) === []
            ) {
                $messages[] = ValidationMessage::warning(
                    self::CODE_DEAD_EDGE,
                    (string) __('Step "%1" has no outgoing edges; every path through it ends the workflow.', $stepKey),
                    $stepKey
                );
            }
            if (in_array($type, self::POST_PARK_STALE_SOURCES, true)) {
                // Every edge leaving the parking step; an approval gate has
                // three (on_approved/on_rejected/on_timeout), a delay one.
                // Report each stale branch/switch target once.
                $reported = [];
                foreach ($definition->getStepEdges($stepKey) as $next) {
                    if ($next === null || isset($reported[$next]) || !$definition->hasStep($next)) {
                        continue;
                    }
                    $target = $definition->getStep($next);
                    if (in_array($target['type'] ?? null, self::BRANCHING_TYPES, true)
                        && ($target['revalidate_entity'] ?? true) === false
                    ) {
                        $reported[$next] = true;
                        $messages[] = ValidationMessage::warning(
                            self::CODE_POST_DELAY_STALE,
                            (string) __(
                                'Step "%1" evaluates conditions against the stale trigger snapshot directly '
                                . 'after a delay or approval gate (revalidate_entity is false). '
                                . 'This is usually a mistake.',
                                $next
                            ),
                            $next
                        );
                    }
                }
            }
        }

        return $messages;
    }

    /**
     * Iterative DFS over the declared edges; each back edge into the current
     * path is one GRAPH_CYCLE error.
     *
     * @param array<string, true> $path steps on the current DFS path
     * @param array<string, true> $visited fully explored steps
     * @param ValidationMessage[] $messages
     */
    private function walk(
        Definition $definition,
        string $stepKey,
        array $path,
        array &$visited,
        array &$messages
    ): void {
        if (isset($visited[$stepKey])) {
            return;
        }
        $path[$stepKey] = true;
        foreach ($definition->getStepEdges($stepKey) as $edge => $target) {
            if ($target === null) {
                continue;
            }
            if (isset($path[$target])) {
                $messages[] = ValidationMessage::error(
                    self::CODE_CYCLE,
                    (string) __(
                        'Step "%1" edge "%2" creates a cycle back to step "%3". '
                        . 'Workflow graphs must not loop.',
                        $stepKey,
                        $edge,
                        $target
                    ),
                    $stepKey,
                    (string) $edge
                );
                continue;
            }
            $this->walk($definition, $target, $path, $visited, $messages);
        }
        $visited[$stepKey] = true;
    }
}
