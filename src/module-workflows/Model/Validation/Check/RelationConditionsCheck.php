<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Validation\Check;

use MageOS\Workflows\Model\Definition\Definition;
use MageOS\Workflows\Model\Rule\Condition\RelatedEntity\Combine as RelatedEntityCombine;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationMessage;
use MageOS\Workflows\Model\Validation\ValidationSubject;

/**
 * Save-time rule for the RelatedEntity combine (F2, entity cross-referencing
 * §9 / open question 1): a `NOT EXISTS` related-entity node MUST NOT carry
 * child conditions.
 *
 * Rationale — the two operators stop being complements once children exist.
 * `EXISTS` + children means "a related entity exists AND matches the
 * children"; the honest negation of that is "no related entity matches the
 * children", NOT "no related entity exists". If `NOT EXISTS` silently kept
 * children it would read as "no related entity exists at all", so
 * "NOT EXISTS a customer with orders_count ≥ 3" would never match the moment
 * ANY unqualified customer exists — a silent, dangerous mis-evaluation. Hence
 * a HARD error at save, not an advisory warning.
 *
 * Walks the root condition tree and every embedded tree (branch steps, switch
 * cases), mirroring ConditionsShapeCheck, so the target.step_key pins the
 * finding to the offending node for the form and canvas.
 */
class RelationConditionsCheck implements CheckInterface
{
    public const CODE_NOT_EXISTS_WITH_CHILDREN = 'RELATION_NOT_EXISTS_WITH_CHILDREN';

    /**
     * @inheritDoc
     */
    public function check(ValidationSubject $subject, ValidationContext $context): array
    {
        $messages = [];

        if ($this->treeHasNotExistsWithChildren($subject->getConditionsSerialized())) {
            $messages[] = $this->error(null);
        }

        $definition = $subject->getDefinition();
        if ($definition === null) {
            return $messages;
        }
        foreach ($definition->getSteps() as $stepKey => $step) {
            $stepKey = (string) $stepKey;
            $type = $step['type'] ?? null;
            if ($type === Definition::STEP_BRANCH
                && $this->treeHasNotExistsWithChildren($step['conditions_serialized'] ?? null)
            ) {
                $messages[] = $this->error($stepKey);
            }
            if ($type === Definition::STEP_SWITCH) {
                foreach ((array) ($step['cases'] ?? []) as $case) {
                    if (is_array($case)
                        && $this->treeHasNotExistsWithChildren($case['conditions_serialized'] ?? null)
                    ) {
                        $messages[] = $this->error($stepKey, 'case:' . (string) ($case['key'] ?? ''));
                    }
                }
            }
        }
        return $messages;
    }

    private function error(?string $stepKey, ?string $edge = null): ValidationMessage
    {
        return ValidationMessage::error(
            self::CODE_NOT_EXISTS_WITH_CHILDREN,
            (string) __(
                'A "NOT EXISTS" related-entity condition cannot have child conditions: '
                . '"exists" and "not exists" are not opposites once children are added, so the '
                . 'condition would never match. Use "EXISTS" with the child conditions, or remove them.'
            ),
            $stepKey,
            $edge
        );
    }

    private function treeHasNotExistsWithChildren(mixed $conditionsSerialized): bool
    {
        if (!is_scalar($conditionsSerialized) || trim((string) $conditionsSerialized) === '') {
            return false;
        }
        $tree = json_decode((string) $conditionsSerialized, true);
        return is_array($tree) && $this->walk($tree);
    }

    private function walk(array $node): bool
    {
        $children = $node['conditions'] ?? null;
        $hasChildren = is_array($children) && $children !== [];

        if ($this->isRelatedEntity($node) && $this->isNotExists($node) && $hasChildren) {
            return true;
        }
        if (is_array($children)) {
            foreach ($children as $child) {
                if (is_array($child) && $this->walk($child)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function isRelatedEntity(array $node): bool
    {
        return ($node['type'] ?? null) === RelatedEntityCombine::class;
    }

    /**
     * NOT EXISTS is value 0 (EXISTS is 1). An absent value defaults to EXISTS,
     * so only an explicit falsey value forbids children.
     */
    private function isNotExists(array $node): bool
    {
        if (!array_key_exists('value', $node)) {
            return false;
        }
        $value = $node['value'];
        return $value === 0 || $value === false || $value === '0' || $value === 'false';
    }
}
