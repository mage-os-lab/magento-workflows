<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Template;

use Magento\Framework\Phrase;

/**
 * One typed reason a template is incompatible with this install — the text that
 * appears under a greyed-out card ("requires the B2B pack") and the stable
 * machine code a test or the install controller asserts on.
 */
final class CompatibilityReason
{
    public const MISSING_TRIGGER = 'MISSING_TRIGGER';
    public const MISSING_ACTION = 'MISSING_ACTION';
    public const MISSING_MODULE = 'MISSING_MODULE';
    public const EDITION_MISMATCH = 'EDITION_MISMATCH';
    public const SCHEMA_TOO_NEW = 'SCHEMA_TOO_NEW';
    public const MISSING_LOCALE = 'MISSING_LOCALE';

    public function __construct(
        private readonly string $code,
        private readonly Phrase $message
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getMessage(): Phrase
    {
        return $this->message;
    }
}
