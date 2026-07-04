<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Validation\Check;

use MageOS\Workflows\Model\Definition\Definition;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationMessage;
use MageOS\Workflows\Model\Validation\ValidationSubject;

/**
 * The shallow conditions_serialized JSON check (F2, relocated from the admin
 * Save controller): the save-time contract is "empty, or a JSON structure".
 * Deeper semantic validation is intentionally out of scope. Applies to the
 * root condition tree and to every embedded tree (branch steps, switch
 * cases) — a tree that does not decode would fail the execution at runtime.
 */
class ConditionsShapeCheck implements CheckInterface
{
    public const CODE_CONDITIONS_INVALID = 'CONDITIONS_INVALID_JSON';

    /**
     * @inheritDoc
     */
    public function check(ValidationSubject $subject, ValidationContext $context): array
    {
        $messages = [];

        if (!$this->isValidTree($subject->getConditionsSerialized())) {
            $messages[] = ValidationMessage::error(
                self::CODE_CONDITIONS_INVALID,
                (string) __(
                    'The Conditions field must be empty or contain a valid JSON condition tree (object or array).'
                )
            );
        }

        $definition = $subject->getDefinition();
        if ($definition === null) {
            return $messages;
        }
        foreach ($definition->getSteps() as $stepKey => $step) {
            $stepKey = (string) $stepKey;
            $type = $step['type'] ?? null;
            if ($type === Definition::STEP_BRANCH
                && !$this->isValidTree($step['conditions_serialized'] ?? null)
            ) {
                $messages[] = ValidationMessage::error(
                    self::CODE_CONDITIONS_INVALID,
                    (string) __('Step "%1" conditions must be empty or a valid JSON condition tree.', $stepKey),
                    $stepKey
                );
            }
            if ($type === Definition::STEP_SWITCH) {
                foreach ((array) ($step['cases'] ?? []) as $case) {
                    if (is_array($case) && !$this->isValidTree($case['conditions_serialized'] ?? null)) {
                        $caseKey = (string) ($case['key'] ?? '');
                        $messages[] = ValidationMessage::error(
                            self::CODE_CONDITIONS_INVALID,
                            (string) __(
                                'Step "%1" case "%2" conditions must be empty or a valid JSON condition tree.',
                                $stepKey,
                                $caseKey
                            ),
                            $stepKey,
                            'case:' . $caseKey
                        );
                    }
                }
            }
        }
        return $messages;
    }

    private function isValidTree(mixed $conditionsSerialized): bool
    {
        if ($conditionsSerialized === null
            || (is_scalar($conditionsSerialized) && trim((string) $conditionsSerialized) === '')
        ) {
            return true;
        }
        if (!is_scalar($conditionsSerialized)) {
            return false;
        }
        return is_array(json_decode((string) $conditionsSerialized, true));
    }
}
