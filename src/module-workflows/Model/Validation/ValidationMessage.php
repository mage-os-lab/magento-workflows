<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Validation;

use MageOS\Workflows\Api\Data\ValidationMessageInterface;

/**
 * Immutable validation finding (F2). See ValidationMessageInterface.
 */
class ValidationMessage implements ValidationMessageInterface
{
    public function __construct(
        private readonly string $severity,
        private readonly string $code,
        private readonly string $message,
        private readonly ?string $stepKey = null,
        private readonly ?string $edge = null
    ) {
    }

    public static function error(string $code, string $message, ?string $stepKey = null, ?string $edge = null): self
    {
        return new self(self::SEVERITY_ERROR, $code, $message, $stepKey, $edge);
    }

    public static function warning(string $code, string $message, ?string $stepKey = null, ?string $edge = null): self
    {
        return new self(self::SEVERITY_WARNING, $code, $message, $stepKey, $edge);
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getStepKey(): ?string
    {
        return $this->stepKey;
    }

    public function getEdge(): ?string
    {
        return $this->edge;
    }
}
