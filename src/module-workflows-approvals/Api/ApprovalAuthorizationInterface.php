<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Api;

/**
 * Role-enforcement seam (§5). When a task carries an assignee_role, the decision
 * path checks the deciding actor holds it at decide time — not just filters it
 * in the UI. REST and the admin surface each supply their own check by binding
 * an implementation; the addon ships a deny-safe default
 * (MageOS\WorkflowsApprovals\Model\Authorization\DenyRoleAuthorization) so a
 * role-gated task can never be decided until a real authorization is wired
 * (Stage 3 / webapi binds the Magento authorization).
 */
interface ApprovalAuthorizationInterface
{
    /**
     * @param string $actorType 'admin' | 'integration'
     * @param string $actorId the admin user id / integration id
     * @param string $role the required role code
     * @return bool true only when the actor demonstrably holds the role
     */
    public function actorHoldsRole(string $actorType, string $actorId, string $role): bool;
}
