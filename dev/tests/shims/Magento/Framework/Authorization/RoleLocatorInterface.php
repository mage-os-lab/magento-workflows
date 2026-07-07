<?php
declare(strict_types=1);

namespace Magento\Framework\Authorization;

/**
 * Standalone-runner shim for Magento\Framework\Authorization\RoleLocatorInterface.
 * ValidationContextResolver requires an actual ACL role before resolving
 * MODE_ADMIN_CONTEXT; doubles return '' (no principal) or a role id.
 */
interface RoleLocatorInterface
{
    /**
     * Retrieve current role id
     *
     * @return string|null
     */
    public function getAclRoleId();
}
