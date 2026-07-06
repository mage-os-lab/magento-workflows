<?php
declare(strict_types=1);

namespace Magento\Framework;

/**
 * Standalone-runner shim for Magento\Framework\AuthorizationInterface.
 */
interface AuthorizationInterface
{
    /**
     * @param string $resource
     * @param string|null $privilege
     * @return bool
     */
    public function isAllowed($resource, $privilege = null);
}
