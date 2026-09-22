<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Template;

/**
 * Outcome of CompatibilityChecker: compatible when no reasons were collected.
 * The gallery greys out cards with reasons; TemplateInstaller refuses to
 * install one (a compat failure aborts before any write).
 */
class CompatibilityResult
{
    /**
     * @param CompatibilityReason[] $reasons
     */
    public function __construct(
        private readonly array $reasons = []
    ) {
    }

    public function isCompatible(): bool
    {
        return $this->reasons === [];
    }

    /**
     * @return CompatibilityReason[]
     */
    public function getReasons(): array
    {
        return $this->reasons;
    }

    public function hasReasonWithCode(string $code): bool
    {
        foreach ($this->reasons as $reason) {
            if ($reason->getCode() === $code) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return string[] the human-readable reason texts, for a one-line summary
     */
    public function getMessages(): array
    {
        return array_map(
            static fn (CompatibilityReason $reason): string => (string) $reason->getMessage(),
            $this->reasons
        );
    }
}
