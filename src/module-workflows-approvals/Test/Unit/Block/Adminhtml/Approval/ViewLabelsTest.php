<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Block\Adminhtml\Approval;

use MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface;
use MageOS\Workflows\Api\EntityTypeMetadataProviderInterface;
use MageOS\Workflows\Model\Webapi\EntityTypeMetadata;
use MageOS\WorkflowsAdminUi\Model\Source\EntityType;
use MageOS\WorkflowsAdminUi\Model\Source\ExecutionStatus;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;
use MageOS\WorkflowsApprovals\Block\Adminhtml\Approval\View;
use MageOS\WorkflowsApprovals\Model\Source\ApprovalStatus;
use MageOS\WorkflowsApprovals\Test\Unit\Stub\FakeApproval;
use PHPUnit\Framework\TestCase;

/**
 * The decision page is where an admin commits to an irreversible action, so it
 * may not ask them to read machine codes. Status comes from the existing
 * ApprovalStatus source and the entity type from admin-ui's EntityType (already
 * a hard composer dependency of this package).
 *
 * assignee_role is the exception: it holds a free-form admin ROLE name, which no
 * option source can enumerate, so it is humanized in place and the raw token is
 * kept in the cell's title attribute by the template.
 *
 * Anonymous subclass + reflection injection, per InstallWidgetMappingTest.
 */
class ViewLabelsTest extends TestCase
{
    public function testStatusRendersItsLabel(): void
    {
        $approval = (new FakeApproval())->setStatus(ApprovalInterface::STATUS_APPROVED);

        $this->assertSame('Approved', $this->block($approval)->getStatusLabel());
    }

    public function testUnknownStatusRendersItsRawCode(): void
    {
        $approval = (new FakeApproval())->setStatus('escalated');

        $this->assertSame('escalated', $this->block($approval)->getStatusLabel());
    }

    public function testEntityCellCombinesTheEntityTypeLabelAndTheId(): void
    {
        $approval = (new FakeApproval())->setEntityType('sales_order')->setEntityId(142);

        $this->assertSame('Order #142', $this->block($approval)->getEntityLabel());
    }

    public function testUninstalledEntityTypeKeepsItsRawCode(): void
    {
        $approval = (new FakeApproval())->setEntityType('b2b_quote')->setEntityId(9);

        $this->assertSame('b2b_quote #9', $this->block($approval)->getEntityLabel());
    }

    public function testMissingEntityTypeLeavesTheBareId(): void
    {
        $approval = (new FakeApproval())->setEntityId(9);

        $this->assertSame('9', $this->block($approval)->getEntityLabel());
    }

    public function testAssigneeRoleIsHumanized(): void
    {
        $block = $this->block((new FakeApproval())->setAssigneeRole('finance_manager'));

        $this->assertSame('Finance Manager', $block->getAssigneeRoleLabel());
    }

    public function testAssigneeRoleHumanizerLeavesAnAlreadyReadableRoleAlone(): void
    {
        $block = $this->block((new FakeApproval())->setAssigneeRole('Store Manager'));

        $this->assertSame('Store Manager', $block->getAssigneeRoleLabel());
    }

    public function testUnassignedRoleStaysEmptySoTheTemplateCanDashIt(): void
    {
        $this->assertSame('', $this->block(new FakeApproval())->getAssigneeRoleLabel());
        $this->assertSame('', $this->block((new FakeApproval())->setAssigneeRole('  '))->getAssigneeRoleLabel());
    }

    /**
     * The timeline shares the execution status code set, so admin-ui's
     * ExecutionStatus source labels it — no second list to drift.
     */
    public function testEveryStepStatusResolvesThroughTheExecutionStatusSource(): void
    {
        $block = $this->block(new FakeApproval());

        foreach ((new \ReflectionClass(WorkflowExecutionStepInterface::class))->getConstants() as $name => $code) {
            if (!str_starts_with($name, 'STATUS_')) {
                continue;
            }
            $this->assertTrue(
                $block->getStepStatusLabel((string) $code) !== (string) $code,
                sprintf('step status %s has no label in ExecutionStatus', $name)
            );
        }
    }

    public function testUnknownStepStatusRendersItsRawCode(): void
    {
        $this->assertSame('parked', $this->block(new FakeApproval())->getStepStatusLabel('parked'));
    }

    public function testNoApprovalYieldsEmptyLabelsRatherThanAFatal(): void
    {
        $block = $this->block(null);

        $this->assertSame('', $block->getEntityLabel());
        $this->assertSame('', $block->getStatusLabel());
        $this->assertSame('', $block->getAssigneeRoleLabel());
    }

    private function block(?ApprovalInterface $approval): View
    {
        $block = new class ($approval) extends View {
            public function __construct(private readonly ?ApprovalInterface $stub = null)
            {
            }

            public function getApproval(): ?ApprovalInterface
            {
                return $this->stub;
            }
        };

        $dependencies = [
            'approvalStatusSource' => new ApprovalStatus(),
            'executionStatusSource' => new ExecutionStatus(),
            'entityTypeSource' => new EntityType(
                new class implements EntityTypeMetadataProviderInterface {
                    public function getEntityTypes(): array
                    {
                        return [new EntityTypeMetadata('sales_order', 'Order')];
                    }
                }
            ),
        ];

        foreach ($dependencies as $name => $value) {
            $property = new \ReflectionProperty(View::class, $name);
            $property->setAccessible(true);
            $property->setValue($block, $value);
        }

        return $block;
    }
}
