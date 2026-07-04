<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Webapi;

use MageOS\Workflows\Api\Data\DefinitionValidationResultInterface;
use MageOS\Workflows\Api\DefinitionValidationInterface;
use MageOS\Workflows\Model\PlainLanguageRenderer;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationSubject;
use MageOS\Workflows\Model\Validation\WorkflowValidator;

/**
 * POST /V1/workflows/validate (F6): the F2 pipeline in dry-run mode — the
 * server remains the sole authority for validation; clients (form preview,
 * canvas, CI) never re-implement the rules. Never persists, never throws on
 * findings: errors come back as messages with getValid() = false.
 */
class DefinitionValidation implements DefinitionValidationInterface
{
    public function __construct(
        private readonly WorkflowValidator $validator,
        private readonly PlainLanguageRenderer $plainLanguageRenderer
    ) {
    }

    /**
     * @inheritDoc
     */
    public function validate(
        string $definition,
        ?string $conditionsSerialized = null,
        ?string $triggerType = null,
        ?string $triggerRef = null,
        ?string $entityType = null
    ): DefinitionValidationResultInterface {
        $result = $this->validator->validate(
            new ValidationSubject($definition, $conditionsSerialized),
            // Dry-run: per-action ACL re-authorization is an authoring-time
            // gate and deliberately does not run here (F2).
            new ValidationContext(
                ValidationContext::MODE_ADMIN_CONTEXT,
                ValidationContext::KIND_STANDARD,
                true
            )
        );

        return new DefinitionValidationResult(
            $result->isValid(),
            $result->getMessages(),
            $this->plainLanguageRenderer->renderFromFields(
                (string) $triggerType,
                (string) $triggerRef,
                (string) $entityType,
                $conditionsSerialized,
                $definition
            )
        );
    }
}
