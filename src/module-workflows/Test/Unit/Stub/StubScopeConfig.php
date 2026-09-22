<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Stub;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Minimal ScopeConfigInterface stand-in: a flat path => value map, with an
 * optional default returned for unset paths.
 */
class StubScopeConfig implements ScopeConfigInterface
{
    /**
     * @param array<string, mixed> $values config path => value
     */
    public function __construct(
        private readonly array $values = []
    ) {
    }

    // $scopeCode is untyped like the real ScopeConfigInterface: callers pass an
    // int store id (Executor::computeResumeAt) as readily as a string code.
    public function getValue($path, $scopeType = 'default', $scopeCode = null)
    {
        return $this->values[$path] ?? null;
    }

    public function isSetFlag($path, $scopeType = 'default', $scopeCode = null): bool
    {
        return (bool) ($this->values[$path] ?? false);
    }
}
