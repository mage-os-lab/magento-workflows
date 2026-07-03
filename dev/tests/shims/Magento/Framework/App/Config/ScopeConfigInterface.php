<?php
declare(strict_types=1);

namespace Magento\Framework\App\Config;

interface ScopeConfigInterface
{
    public function getValue(string $path, string $scope = 'default', ?string $scopeCode = null);
    public function isSetFlag(string $path, string $scope = 'default', ?string $scopeCode = null): bool;
}
