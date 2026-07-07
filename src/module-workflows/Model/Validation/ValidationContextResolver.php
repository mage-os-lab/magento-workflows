<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Validation;

use Magento\Framework\App\State;
use Magento\Framework\Authorization\RoleLocatorInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Derives the default ValidationContext for the repository save plugin.
 * Admin sessions and webapi tokens carry an authorization context
 * (per-action ACL runs); CLI, cron, and everything area-less runs as
 * SYSTEM (per-action ACL is skipped with the documented loud warning —
 * see WorkflowImporter / ImportCommand).
 *
 * MODE_ADMIN_CONTEXT requires BOTH an admin-class area AND an actual ACL
 * role (docs/09: it is the *session/token* that carries the authorization
 * context, not the area). Area alone is a proxy that misfires: code running
 * under an emulated adminhtml area with no authenticated principal — cron
 * or CLI area emulation, the integration-test framework — would otherwise
 * hit AuthorizationInterface with an empty role and be denied outright,
 * while every real admin/webapi request reaches the repository only after
 * front-controller authentication, so requiring a role never loosens a
 * reachable path.
 *
 * Callers with better knowledge (WorkflowImporter, the validate endpoint)
 * build their ValidationContext explicitly instead of resolving it.
 */
class ValidationContextResolver
{
    private const ADMIN_AREAS = ['adminhtml', 'webapi_rest', 'webapi_soap'];

    public function __construct(
        private readonly State $appState,
        private readonly RoleLocatorInterface $roleLocator
    ) {
    }

    public function resolve(): ValidationContext
    {
        try {
            $area = $this->appState->getAreaCode();
        } catch (LocalizedException $e) {
            $area = null;
        }
        $mode = in_array($area, self::ADMIN_AREAS, true) && $this->hasAclRole()
            ? ValidationContext::MODE_ADMIN_CONTEXT
            : ValidationContext::MODE_SYSTEM;

        return new ValidationContext($mode);
    }

    private function hasAclRole(): bool
    {
        return (string)$this->roleLocator->getAclRoleId() !== '';
    }
}
