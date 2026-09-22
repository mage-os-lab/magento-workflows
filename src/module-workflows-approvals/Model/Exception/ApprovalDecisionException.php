<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * A rejected decision attempt (docs/discovery/approval-gate.md §4/§5). Carries a
 * stable machine-readable code alongside the human message so REST clients and
 * the admin UI can branch on the reason without string-matching.
 */
class ApprovalDecisionException extends LocalizedException
{
    // Validation (before any claim)
    public const CODE_INVALID_DECISION = 'APPROVAL_INVALID_DECISION';
    public const CODE_NOTE_TOO_LONG = 'APPROVAL_NOTE_TOO_LONG';
    public const CODE_PAYLOAD_NOT_ACCEPTED = 'APPROVAL_PAYLOAD_NOT_ACCEPTED';
    public const CODE_PAYLOAD_NOT_FLAT = 'APPROVAL_PAYLOAD_NOT_FLAT';
    public const CODE_PAYLOAD_TOO_MANY_KEYS = 'APPROVAL_PAYLOAD_TOO_MANY_KEYS';
    public const CODE_PAYLOAD_TOO_LARGE = 'APPROVAL_PAYLOAD_TOO_LARGE';
    public const CODE_PAYLOAD_UNKNOWN_KEY = 'APPROVAL_PAYLOAD_UNKNOWN_KEY';
    public const CODE_PAYLOAD_TYPE_MISMATCH = 'APPROVAL_PAYLOAD_TYPE_MISMATCH';
    public const CODE_PAYLOAD_REQUIRED_MISSING = 'APPROVAL_PAYLOAD_REQUIRED_MISSING';

    // Authorization
    public const CODE_ROLE_REQUIRED = 'APPROVAL_ROLE_REQUIRED';

    // Claim races (§4)
    public const CODE_ALREADY_DECIDED = 'APPROVAL_ALREADY_DECIDED';
    public const CODE_EXECUTION_GONE = 'APPROVAL_EXECUTION_GONE';

    private readonly string $approvalCode;

    public function __construct(string $approvalCode, Phrase $phrase, ?\Exception $cause = null)
    {
        $this->approvalCode = $approvalCode;
        parent::__construct($phrase, $cause);
    }

    public function getApprovalCode(): string
    {
        return $this->approvalCode;
    }
}
