<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model\Authorization;

use MageOS\WorkflowsApprovals\Api\ApprovalAuthorizationInterface;

/**
 * Deny-safe default binding for the role-enforcement seam (§5). Until a real
 * authorization is wired (Stage 3 / webapi binds the Magento authorization),
 * every role-gated task is undecidable rather than silently decidable — a
 * missing check must never read as "allowed". Tasks with no assignee_role never
 * reach this class (the decision path skips the check when no role is set).
 */
class DenyRoleAuthorization implements ApprovalAuthorizationInterface
{
    public function actorHoldsRole(string $actorType, string $actorId, string $role): bool
    {
        return false;
    }
}
