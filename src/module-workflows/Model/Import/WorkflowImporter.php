<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Import;

use Magento\Framework\Exception\LocalizedException;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\Definition\Definition;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationSubject;
use MageOS\Workflows\Model\Validation\WorkflowValidator;
use MageOS\Workflows\Model\WorkflowFactory;

/**
 * The one import path (F3): envelope parse, F2 validation pipeline, then
 * persistence — shared by `workflow:import`, Save-adjacent flows, and the
 * template gallery. Import is untrusted input (docs/10-security.md) and is
 * validated hard before anything persists.
 *
 * The caller declares its authorization mode explicitly:
 *  - ValidationContext::MODE_ADMIN_CONTEXT — per-action ACL re-authorization
 *    runs against the current admin (Save-adjacent flows, gallery installs);
 *  - ValidationContext::MODE_SYSTEM — the ACL pass is skipped with the
 *    existing loud warning (CLI, data patches; operators own that risk).
 *
 * Imports are created disabled by default; shadow / enabled are explicit
 * opt-ins. Never auto-enabled.
 */
class WorkflowImporter
{
    /**
     * Export envelope format tag (docs/04-definition-format.md). The single
     * source of truth — ExportCommand emits it, this class requires it.
     */
    public const FORMAT = 'mageos-workflow-export/1';

    private const ALLOWED_STATUSES = [
        WorkflowInterface::STATUS_DISABLED,
        WorkflowInterface::STATUS_ENABLED,
        WorkflowInterface::STATUS_SHADOW,
    ];

    public function __construct(
        private readonly WorkflowFactory $workflowFactory,
        private readonly WorkflowRepositoryInterface $workflowRepository,
        private readonly WorkflowValidator $validator
    ) {
    }

    /**
     * @param array $envelope decoded `workflow:export` JSON envelope
     * @param string $authMode ValidationContext::MODE_ADMIN_CONTEXT | MODE_SYSTEM
     * @param int $status initial status; disabled unless explicitly overridden
     * @throws LocalizedException on an invalid envelope, failed validation, or failed save
     */
    public function import(
        array $envelope,
        string $authMode,
        int $status = WorkflowInterface::STATUS_DISABLED
    ): ImportResult {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new LocalizedException(__('Imported workflows may only start disabled, enabled, or shadow.'));
        }
        self::assertEnvelope($envelope);

        $conditionsSerialized = $envelope['conditions_serialized'] ?? null;
        $conditionsSerialized = is_string($conditionsSerialized) ? $conditionsSerialized : null;

        $result = $this->validator->validate(
            new ValidationSubject(
                (string) json_encode($envelope['definition']),
                $conditionsSerialized
            ),
            new ValidationContext($authMode)
        );
        if ($result->hasErrors()) {
            throw new LocalizedException(__(
                'The imported workflow is invalid: %1',
                implode(' ', array_map(
                    static fn ($m): string => rtrim($m->getMessage(), '.') . '.',
                    $result->getErrors()
                ))
            ));
        }

        // Normalize through the parsed contract so the stored JSON matches
        // what the validator saw (and what export will emit).
        $definition = Definition::fromArray($envelope['definition']);

        $workflow = $this->workflowFactory->create();
        $workflow->setName((string) $envelope['name']);
        $workflow->setEntityType((string) $envelope['entity_type']);
        $workflow->setTriggerType((string) $envelope['trigger_type']);
        $workflow->setTriggerRef((string) $envelope['trigger_ref']);
        $workflow->setConditionsSerialized($conditionsSerialized);
        $workflow->setDefinition($definition->toJson());
        $workflow->setLoopGuardDepth((int) ($envelope['loop_guard_depth'] ?? 1));
        $workflow->setStatus($status);

        $saved = $this->workflowRepository->save($workflow);

        return new ImportResult($saved, $result);
    }

    /**
     * Structural envelope contract, independent of any service state so the
     * shape rules are unit-testable on their own.
     *
     * @throws LocalizedException
     */
    public static function assertEnvelope(array $envelope): void
    {
        $format = $envelope['format'] ?? null;
        if ($format !== self::FORMAT) {
            throw new LocalizedException(
                __('Unsupported export format "%1"; expected "%2".', (string) $format, self::FORMAT)
            );
        }
        if (!is_array($envelope['definition'] ?? null)) {
            throw new LocalizedException(__('Envelope "definition" must be an object.'));
        }
        foreach (['name', 'entity_type', 'trigger_type', 'trigger_ref'] as $field) {
            $value = $envelope[$field] ?? null;
            if (!is_string($value) || $value === '') {
                throw new LocalizedException(__(
                    'Envelope is missing required field(s): name, entity_type, trigger_type, trigger_ref.'
                ));
            }
        }
        $conditions = $envelope['conditions_serialized'] ?? null;
        if ($conditions !== null && !is_string($conditions)) {
            throw new LocalizedException(__('Envelope "conditions_serialized" must be a string or null.'));
        }
    }
}
