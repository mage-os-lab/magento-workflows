<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminExtension\Test\Unit\Stub;

use Magento\Framework\AuthorizationInterface;

/**
 * AuthorizationInterface stand-in backed by a resource => bool allow map.
 */
class FakeAuthorization implements AuthorizationInterface
{
    /**
     * @param array<string, bool> $allowed
     */
    public function __construct(
        private readonly array $allowed = []
    ) {
    }

    public function isAllowed($resource, $privilege = null)
    {
        return (bool) ($this->allowed[$resource] ?? false);
    }
}
