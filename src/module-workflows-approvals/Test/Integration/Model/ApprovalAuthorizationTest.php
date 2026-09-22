<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Integration\Model;

use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\WorkflowsApprovals\Api\ApprovalAuthorizationInterface;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;
use MageOS\WorkflowsApprovals\Model\Authorization\RoleTableAuthorization;
use MageOS\WorkflowsApprovals\Model\Exception\ApprovalDecisionException;
use MageOS\WorkflowsApprovals\Test\Integration\_files\ApprovalTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Plan #26 (docs/20-integration-test-plan.md §6): the role-enforcement contract
 * (Model/Authorization) exercised against the REAL authorization_role tables and
 * the REAL decision path. A gate carrying an assignee_role can only be decided
 * by an actor demonstrably holding it (checked at decide time, not merely
 * filtered in the UI); an actor without it is refused with CODE_ROLE_REQUIRED,
 * and the deny leaves task and execution untouched. The merged di.xml binds
 * RoleTableAuthorization (not the deny-safe default) — proven by resolving the
 * interface from the object manager.
 *
 * @magentoAppArea adminhtml
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ApprovalAuthorizationTest extends TestCase
{
    use ApprovalTestTrait;

    private const ROLE = 'Refund Approvers';

    public function testMergedDiBindsRealRoleTableAuthorization(): void
    {
        $this->assertInstanceOf(
            RoleTableAuthorization::class,
            $this->om()->get(ApprovalAuthorizationInterface::class),
            'The addon binds the real authorization, replacing the deny-safe default'
        );
    }

    public function testAdminHoldingTheRoleResolvesTrueCaseInsensitively(): void
    {
        $this->seedAdminRole(self::ROLE, 777);
        $auth = $this->om()->get(ApprovalAuthorizationInterface::class);

        $this->assertTrue($auth->actorHoldsRole('admin', '777', self::ROLE));
        $this->assertTrue($auth->actorHoldsRole('admin', '777', 'REFUND APPROVERS'), 'match is case-insensitive');
        $this->assertTrue($auth->actorHoldsRole('admin', '777', '  Refund Approvers  '), 'match is trimmed');
    }

    public function testAdminWithoutTheRoleResolvesFalse(): void
    {
        $this->seedAdminRole(self::ROLE, 777);
        $auth = $this->om()->get(ApprovalAuthorizationInterface::class);

        $this->assertFalse($auth->actorHoldsRole('admin', '888', self::ROLE), 'an unassigned admin does not hold it');
        $this->assertFalse($auth->actorHoldsRole('admin', '777', 'Some Other Role'), 'a different role name misses');
        $this->assertFalse($auth->actorHoldsRole('robot', '777', self::ROLE), 'an unknown actor type is deny-safe');
    }

    public function testDecideDeniedWhenActorLacksAssigneeRole(): void
    {
        [, $executionId] = $this->parkApproval(['assignee_role' => self::ROLE]);
        $uuid = $this->taskUuid($executionId);

        try {
            $this->approvalService()->decide($uuid, ApprovalInterface::STATUS_APPROVED, null, [], 'admin', '888');
            $this->fail('A role-gated task must refuse an actor lacking the role');
        } catch (ApprovalDecisionException $e) {
            $this->assertSame(ApprovalDecisionException::CODE_ROLE_REQUIRED, $e->getApprovalCode());
        }

        // The refusal happens before any claim: task open, execution still waiting.
        $this->assertSame(ApprovalInterface::STATUS_OPEN, $this->taskStatus($executionId));
        $this->assertSame(
            WorkflowExecutionInterface::STATUS_WAITING,
            $this->reloadExecution($executionId)->getStatus()
        );
    }

    public function testDecideAllowedWhenActorHoldsAssigneeRole(): void
    {
        [, $executionId] = $this->parkApproval(['assignee_role' => self::ROLE]);
        $uuid = $this->taskUuid($executionId);
        $this->seedAdminRole(self::ROLE, 777);

        $result = $this->approvalService()->decide($uuid, ApprovalInterface::STATUS_APPROVED, null, [], 'admin', '777');

        $this->assertSame(ApprovalInterface::STATUS_APPROVED, $result->getStatus());
        $this->assertSame(ApprovalInterface::STATUS_APPROVED, $this->taskStatus($executionId));
        $this->assertSame(
            WorkflowExecutionInterface::STATUS_PENDING,
            $this->reloadExecution($executionId)->getStatus(),
            'A held role lets the decision claim the execution'
        );
    }
}
