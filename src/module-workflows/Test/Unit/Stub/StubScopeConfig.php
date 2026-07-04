<?php
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

    public function getValue(string $path, string $scope = 'default', ?string $scopeCode = null)
    {
        return $this->values[$path] ?? null;
    }

    public function isSetFlag(string $path, string $scope = 'default', ?string $scopeCode = null): bool
    {
        return (bool) ($this->values[$path] ?? false);
    }
}
