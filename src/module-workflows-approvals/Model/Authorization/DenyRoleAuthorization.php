<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model\Authorization;

use MageOS\WorkflowsApprovals\Api\ApprovalAuthorizationInterface;

/**
 * Deny-safe shape for the role-enforcement seam (§5). Stage 1/2 bound this as
 * the module's default (no real check wired yet); Stage 3 wires
 * RoleTableAuthorization as the default binding instead (etc/di.xml), which
 * itself falls back to deny-safe for any actor/role it cannot resolve. This
 * class remains available — an unconditional deny-safe binding an operator can
 * still opt into (e.g. via their own di.xml override) if they want role-gated
 * tasks undecidable outright rather than resolved against the authorization
 * tables. Tasks with no assignee_role never reach either implementation (the
 * decision path skips the check when no role is set).
 */
class DenyRoleAuthorization implements ApprovalAuthorizationInterface
{
    public function actorHoldsRole(string $actorType, string $actorId, string $role): bool
    {
        return false;
    }
}
