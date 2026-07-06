<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Validation\Check;

use MageOS\Workflows\Model\Action\ActionPool;
use MageOS\Workflows\Model\Definition\Definition;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationMessage;
use MageOS\Workflows\Model\Validation\ValidationSubject;

/**
 * Unknown action codes are errors — always, in every context (F2): a
 * definition referencing an unregistered action would fail terminally at
 * runtime, so it never saves.
 */
class ActionCodesCheck implements CheckInterface
{
    public const CODE_ACTION_UNKNOWN = 'ACTION_UNKNOWN';

    public function __construct(
        private readonly ActionPool $actionPool
    ) {
    }

    /**
     * @inheritDoc
     */
    public function check(ValidationSubject $subject, ValidationContext $context): array
    {
        $definition = $subject->getDefinition();
        if ($definition === null) {
            return [];
        }
        $messages = [];
        foreach ($definition->getSteps() as $stepKey => $step) {
            if (($step['type'] ?? null) !== Definition::STEP_ACTION) {
                continue;
            }
            $code = (string) ($step['action'] ?? '');
            if (!$this->actionPool->has($code)) {
                $messages[] = ValidationMessage::error(
                    self::CODE_ACTION_UNKNOWN,
                    (string) __('Step "%1" references unknown workflow action "%2".', (string) $stepKey, $code),
                    (string) $stepKey
                );
            }
        }
        return $messages;
    }
}
