<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Controller\Adminhtml\Approval;

use MageOS\WorkflowsApprovals\Controller\Adminhtml\Approval\AbstractMassDecide;
use MageOS\WorkflowsApprovals\Controller\Adminhtml\Approval\Decide;
use MageOS\WorkflowsApprovals\Controller\Adminhtml\Approval\Index;
use MageOS\WorkflowsApprovals\Controller\Adminhtml\Approval\MassApprove;
use MageOS\WorkflowsApprovals\Controller\Adminhtml\Approval\MassReject;
use MageOS\WorkflowsApprovals\Controller\Adminhtml\Approval\View;
use PHPUnit\Framework\TestCase;

/**
 * ACL contract (docs/discovery/approval-gate.md §5, §6): viewing
 * (Index/View) is gated ::approvals_view; deciding (Decide, both mass
 * controllers) is gated ::approvals_decide — deliberately NOT implied by
 * ::manage, and viewing is deliberately a separate, lesser grant from
 * deciding. Controllers have heavy Action\Context constructors, so
 * ADMIN_RESOURCE is asserted by reflection on the class (mirrors admin-ui's
 * TemplateAclTest), never by instantiation.
 */
class ApprovalAclTest extends TestCase
{
    public function testViewingControllersRequireApprovalsView(): void
    {
        foreach ([Index::class, View::class] as $controller) {
            $this->assertSame(
                'MageOS_WorkflowsApprovals::approvals_view',
                $controller::ADMIN_RESOURCE,
                $controller . ' must be gated by approvals_view'
            );
        }
    }

    public function testDecidingControllersRequireApprovalsDecide(): void
    {
        foreach ([Decide::class, MassApprove::class, MassReject::class, AbstractMassDecide::class] as $controller) {
            $this->assertSame(
                'MageOS_WorkflowsApprovals::approvals_decide',
                $controller::ADMIN_RESOURCE,
                $controller . ' must be gated by approvals_decide'
            );
        }
    }

    public function testDefaultGridFilterIsStatusOpen(): void
    {
        $this->assertSame(
            ['filters_modifier' => ['status' => ['condition_type' => 'eq', 'value' => 'open']]],
            Index::defaultFilterParams()
        );
    }

    public function testMassApproveAndRejectDecideDifferentDecisions(): void
    {
        $approve = (new \ReflectionClass(MassApprove::class))->newInstanceWithoutConstructor();
        $reject = (new \ReflectionClass(MassReject::class))->newInstanceWithoutConstructor();

        $approveMethod = new \ReflectionMethod(MassApprove::class, 'getDecision');
        $rejectMethod = new \ReflectionMethod(MassReject::class, 'getDecision');

        $this->assertSame('approved', $approveMethod->invoke($approve));
        $this->assertSame('rejected', $rejectMethod->invoke($reject));
    }
}
