<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Validation\Check;

use MageOS\Workflows\Model\Definition\Definition;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationMessage;
use MageOS\Workflows\Model\Validation\ValidationSubject;

/**
 * Step-type + action allowlist per workflow kind (F2). Inert until a profile
 * is registered for a kind: the 'standard' kind carries no profile, so every
 * definition passes untouched. Batch aggregation (05) registers the
 * 'aggregated' profile (no wait steps, restricted actions, in-snapshot root
 * conditions) via the di.xml `profiles` argument.
 */
class ProfileCheck implements CheckInterface
{
    public const CODE_STEP_TYPE_FORBIDDEN = 'PROFILE_STEP_TYPE_FORBIDDEN';
    public const CODE_ACTION_FORBIDDEN = 'PROFILE_ACTION_FORBIDDEN';

    /**
     * @param array<string, array{step_types?: string[], actions?: string[]}> $profiles
     *        workflow kind => allowlists; a kind with no entry is unrestricted
     */
    public function __construct(
        private readonly array $profiles = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function check(ValidationSubject $subject, ValidationContext $context): array
    {
        $definition = $subject->getDefinition();
        $profile = $this->profiles[$context->getWorkflowKind()] ?? null;
        if ($definition === null || $profile === null) {
            return [];
        }

        $messages = [];
        $allowedTypes = $profile['step_types'] ?? null;
        $allowedActions = $profile['actions'] ?? null;
        foreach ($definition->getSteps() as $stepKey => $step) {
            $stepKey = (string) $stepKey;
            $type = (string) ($step['type'] ?? '');
            if (is_array($allowedTypes) && !in_array($type, $allowedTypes, true)) {
                $messages[] = ValidationMessage::error(
                    self::CODE_STEP_TYPE_FORBIDDEN,
                    (string) __(
                        'Step "%1": step type "%2" is not allowed for %3 workflows.',
                        $stepKey,
                        $type,
                        $context->getWorkflowKind()
                    ),
                    $stepKey
                );
            }
            if ($type === Definition::STEP_ACTION && is_array($allowedActions)) {
                $action = (string) ($step['action'] ?? '');
                if (!in_array($action, $allowedActions, true)) {
                    $messages[] = ValidationMessage::error(
                        self::CODE_ACTION_FORBIDDEN,
                        (string) __(
                            'Step "%1": action "%2" is not allowed for %3 workflows.',
                            $stepKey,
                            $action,
                            $context->getWorkflowKind()
                        ),
                        $stepKey
                    );
                }
            }
        }
        return $messages;
    }
}
