<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Validation\Check;

use MageOS\Workflows\Api\ApprovalTaskManagerInterface;
use MageOS\Workflows\Model\Definition\Definition;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationMessage;
use MageOS\Workflows\Model\Validation\ValidationSubject;

/**
 * Save-time gate over approval steps (docs/discovery/approval-gate.md §7). Core
 * carries the schema-4 step semantics but delegates the task record to the
 * optional addon; this check is the authoring boundary:
 *
 *  - APPROVAL_MODULE_MISSING (error): the definition uses an approval step but
 *    no ApprovalTaskManagerInterface is bound (the addon is not installed).
 *    Mirrors how an unknown action code is rejected — a gate reached with no
 *    task manager would fail terminally at runtime, so it never saves.
 *  - APPROVAL_BULK_REQUIRED_PAYLOAD (error): allow_bulk with a required
 *    payload field. A bulk approval supplies one shared note and an empty
 *    payload, so it can never fill a per-task required value (§6).
 *  - APPROVAL_SECRET_IN_PROMPT (error): {{ secrets.* }} in title/instructions.
 *    These render in grids and emails; a decision is not a secret channel (§5).
 *
 * The task manager is an optional (nullable) dependency: absent addon → null →
 * only the module-missing error can fire, which is the point.
 */
class ApprovalCheck implements CheckInterface
{
    public const CODE_MODULE_MISSING = 'APPROVAL_MODULE_MISSING';
    public const CODE_BULK_REQUIRED_PAYLOAD = 'APPROVAL_BULK_REQUIRED_PAYLOAD';
    public const CODE_SECRET_IN_PROMPT = 'APPROVAL_SECRET_IN_PROMPT';

    /**
     * Matches every whitespace form the VariableResolver's placeholder regex
     * ('/\{\{\s*…/') would actually resolve — {{secrets.x}}, {{ secrets.x }},
     * {{   secrets.x }} — not just the canonical single-space spelling.
     */
    private const SECRET_PATTERN = '/\{\{\s*secrets\./';

    public function __construct(
        private readonly ?ApprovalTaskManagerInterface $approvalTaskManager = null
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
            if (($step['type'] ?? null) !== Definition::STEP_APPROVAL) {
                continue;
            }
            $stepKey = (string) $stepKey;
            $config = is_array($step['config'] ?? null) ? $step['config'] : [];

            if ($this->approvalTaskManager === null) {
                $messages[] = ValidationMessage::error(
                    self::CODE_MODULE_MISSING,
                    (string) __(
                        'Step "%1" is an approval gate, but the approvals module is not installed. '
                        . 'Install MageOS_WorkflowsApprovals to author approval steps.',
                        $stepKey
                    ),
                    $stepKey
                );
            }

            if (($config['allow_bulk'] ?? false) === true && $this->hasRequiredPayloadField($config)) {
                $messages[] = ValidationMessage::error(
                    self::CODE_BULK_REQUIRED_PAYLOAD,
                    (string) __(
                        'Step "%1" allows bulk decisions but declares a required payload field. '
                        . 'A bulk approval cannot supply per-task values.',
                        $stepKey
                    ),
                    $stepKey
                );
            }

            if ($this->referencesSecret($config['title'] ?? null)
                || $this->referencesSecret($config['instructions'] ?? null)
            ) {
                $messages[] = ValidationMessage::error(
                    self::CODE_SECRET_IN_PROMPT,
                    (string) __(
                        'Step "%1" references {{ secrets.* }} in its title or instructions. '
                        . 'Approval prompts render in grids and emails; secrets are not allowed there.',
                        $stepKey
                    ),
                    $stepKey
                );
            }
        }

        return $messages;
    }

    private function hasRequiredPayloadField(array $config): bool
    {
        foreach ((array) ($config['payload_fields'] ?? []) as $field) {
            if (is_array($field) && ($field['required'] ?? false) === true) {
                return true;
            }
        }
        return false;
    }

    private function referencesSecret(mixed $value): bool
    {
        return is_string($value) && preg_match(self::SECRET_PATTERN, $value) === 1;
    }
}
