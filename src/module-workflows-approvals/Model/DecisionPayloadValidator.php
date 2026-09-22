<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model;

use Magento\Framework\Phrase;
use MageOS\WorkflowsApprovals\Model\Exception\ApprovalDecisionException;

/**
 * Decision-payload and note validation (docs/discovery/approval-gate.md §5).
 * This is merchant-facing input entering execution context — same trust class
 * as a captured webhook response: flat object, scalars only, capped, and (when
 * the gate declares payload_fields) allowlisted + type-coerced. Runs BEFORE any
 * claim in the decision path, so a rejected payload never touches task or
 * execution state.
 */
class DecisionPayloadValidator
{
    public const MAX_KEYS = 20;
    public const MAX_BYTES = 8192;
    public const MAX_NOTE_LENGTH = 4000;

    /**
     * @throws ApprovalDecisionException CODE_NOTE_TOO_LONG
     */
    public function validateNote(?string $note): void
    {
        if ($note !== null && mb_strlen($note) > self::MAX_NOTE_LENGTH) {
            throw new ApprovalDecisionException(
                ApprovalDecisionException::CODE_NOTE_TOO_LONG,
                new Phrase('The note is too long (max %1 characters).', [self::MAX_NOTE_LENGTH])
            );
        }
    }

    /**
     * Validate and coerce the decision payload against the gate's declaration.
     *
     * @param array<string, mixed> $payload the raw posted payload
     * @param array<int, array>|null $declaration the step config.payload_fields list, or null when the gate declares none
     * @param bool $isApproved required fields are enforced on approve only (§5)
     * @return array<string, mixed> the coerced payload to persist
     * @throws ApprovalDecisionException on any constraint violation
     */
    public function validate(array $payload, ?array $declaration, bool $isApproved): array
    {
        // Whole-payload envelope constraints — independent of any declaration.
        if (count($payload) > self::MAX_KEYS) {
            throw new ApprovalDecisionException(
                ApprovalDecisionException::CODE_PAYLOAD_TOO_MANY_KEYS,
                new Phrase('The payload has too many keys (max %1).', [self::MAX_KEYS])
            );
        }
        foreach ($payload as $key => $value) {
            if (!is_scalar($value)) {
                throw new ApprovalDecisionException(
                    ApprovalDecisionException::CODE_PAYLOAD_NOT_FLAT,
                    new Phrase('Payload field "%1" must be a scalar value.', [(string) $key])
                );
            }
        }
        if (strlen((string) json_encode($payload)) > self::MAX_BYTES) {
            throw new ApprovalDecisionException(
                ApprovalDecisionException::CODE_PAYLOAD_TOO_LARGE,
                new Phrase('The payload exceeds the %1-byte limit.', [self::MAX_BYTES])
            );
        }

        // No declaration = note-only; payload acceptance is opt-in per gate (§5).
        if ($declaration === null) {
            if ($payload !== []) {
                throw new ApprovalDecisionException(
                    ApprovalDecisionException::CODE_PAYLOAD_NOT_ACCEPTED,
                    new Phrase('This gate accepts no payload; only a note may be supplied.')
                );
            }
            return [];
        }

        $declared = [];
        foreach ($declaration as $field) {
            if (is_array($field) && isset($field['key'])) {
                $declared[(string) $field['key']] = $field;
            }
        }

        // Keys are allowlisted against the declaration.
        foreach (array_keys($payload) as $key) {
            if (!isset($declared[(string) $key])) {
                throw new ApprovalDecisionException(
                    ApprovalDecisionException::CODE_PAYLOAD_UNKNOWN_KEY,
                    new Phrase('Payload field "%1" is not declared by this gate.', [(string) $key])
                );
            }
        }

        $coerced = [];
        foreach ($payload as $key => $value) {
            $coerced[(string) $key] = $this->coerce($value, (string) ($declared[(string) $key]['type'] ?? ''), (string) $key);
        }

        // Required fields are enforced on approve only (§5).
        if ($isApproved) {
            foreach ($declared as $key => $field) {
                if (($field['required'] ?? false) === true && !array_key_exists($key, $coerced)) {
                    throw new ApprovalDecisionException(
                        ApprovalDecisionException::CODE_PAYLOAD_REQUIRED_MISSING,
                        new Phrase('Payload field "%1" is required to approve.', [$key])
                    );
                }
            }
        }

        return $coerced;
    }

    /**
     * Coerce one scalar to its declared type, or reject it as non-coercible.
     *
     * @return string|int|float|bool
     * @throws ApprovalDecisionException CODE_PAYLOAD_TYPE_MISMATCH
     */
    private function coerce(mixed $value, string $type, string $key): string|int|float|bool
    {
        switch ($type) {
            case 'string':
                if (is_string($value)) {
                    return $value;
                }
                if (is_bool($value)) {
                    return $value ? 'true' : 'false';
                }
                return (string) $value;

            case 'number':
                if (is_int($value) || is_float($value)) {
                    return $value;
                }
                if (is_string($value) && is_numeric($value)) {
                    return $value + 0;
                }
                break;

            case 'boolean':
                if (is_bool($value)) {
                    return $value;
                }
                if ($value === 1 || $value === 0) {
                    return $value === 1;
                }
                if (is_string($value)) {
                    $lower = strtolower($value);
                    if (in_array($lower, ['true', '1'], true)) {
                        return true;
                    }
                    if (in_array($lower, ['false', '0'], true)) {
                        return false;
                    }
                }
                break;
        }

        throw new ApprovalDecisionException(
            ApprovalDecisionException::CODE_PAYLOAD_TYPE_MISMATCH,
            new Phrase('Payload field "%1" is not a valid %2.', [$key, $type])
        );
    }
}
