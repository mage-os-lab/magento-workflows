<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Integration\Controller\Adminhtml;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Framework\Message\MessageInterface;
use Magento\TestFramework\TestCase\AbstractBackendController;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;
use MageOS\WorkflowsApprovals\Test\Integration\_files\ApprovalTestTrait;

/**
 * Plan #26 (docs/20-integration-test-plan.md §6): the admin Decide controller
 * over AbstractBackendController. Approve/reject POSTs (form key enforced by the
 * admin router) run the SAME single decision path (ApprovalService::decide) as
 * REST, so they claim the task and the execution and publish the resume; a real
 * ResumeConsumer delivery then routes on_approved / on_rejected and completes
 * the walk. ACL is the ::approvals_decide resource — deliberately separate from
 * ::manage (the people who approve refunds are not the people who author
 * workflows). ACL has-access / no-access come from AbstractBackendController via
 * $uri/$resource.
 *
 * @magentoAppArea adminhtml
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class DecideControllerTest extends AbstractBackendController
{
    use ApprovalTestTrait;

    /**
     * @var string
     */
    protected $uri = 'backend/mageos_workflows_approvals/approval/decide';

    /**
     * @var string
     */
    protected $resource = 'MageOS_WorkflowsApprovals::approvals_decide';

    /**
     * @var string
     */
    protected $httpMethod = 'POST';

    /**
     * @magentoDataFixture MageOS_WorkflowsApprovals::Test/Integration/_files/workflow_approval_gate.php
     */
    public function testApproveRecordsDecisionAndResumesExecution(): void
    {
        $executionId = $this->parkFixtureExecution();
        $uuid = $this->taskUuid($executionId);

        $this->postDecide(['uuid' => $uuid, 'decision' => ApprovalInterface::STATUS_APPROVED, 'note' => 'ship it']);

        $this->assertSessionMessages(
            $this->equalTo([(string) __('The decision has been recorded.')]),
            MessageInterface::TYPE_SUCCESS
        );
        $this->assertSame(ApprovalInterface::STATUS_APPROVED, $this->taskStatus($executionId));
        $this->assertSame(
            WorkflowExecutionInterface::STATUS_PENDING,
            $this->reloadExecution($executionId)->getStatus(),
            'The controller decision claims the parked execution'
        );

        // Draining the resume the decision published completes the walk down on_approved.
        $this->resume($executionId);
        $this->assertSame(
            WorkflowExecutionInterface::STATUS_COMPLETE,
            $this->reloadExecution($executionId)->getStatus()
        );
        $this->assertArrayHasKey('approved_end', $this->stepStatuses($executionId));
    }

    /**
     * @magentoDataFixture MageOS_WorkflowsApprovals::Test/Integration/_files/workflow_approval_gate.php
     */
    public function testRejectRecordsDecisionAndEndsExecutionViaOnRejected(): void
    {
        $executionId = $this->parkFixtureExecution();
        $uuid = $this->taskUuid($executionId);

        $this->postDecide(['uuid' => $uuid, 'decision' => ApprovalInterface::STATUS_REJECTED]);

        $this->assertSame(ApprovalInterface::STATUS_REJECTED, $this->taskStatus($executionId));

        $this->resume($executionId);
        $this->assertSame(
            WorkflowExecutionInterface::STATUS_COMPLETE,
            $this->reloadExecution($executionId)->getStatus()
        );
        $this->assertArrayHasKey('rejected_end', $this->stepStatuses($executionId));
        $this->assertArrayNotHasKey('approved_end', $this->stepStatuses($executionId));
    }

    /**
     * @magentoDataFixture MageOS_WorkflowsApprovals::Test/Integration/_files/workflow_approval_gate.php
     */
    public function testAlreadyDecidedTaskSurfacesAWarningAndDoesNotFlip(): void
    {
        $executionId = $this->parkFixtureExecution();
        $uuid = $this->taskUuid($executionId);

        // First decision claims the task.
        $this->approvalService()->decide($uuid, ApprovalInterface::STATUS_APPROVED, null, [], 'admin', '1');

        // The controller's second attempt is a lost race: it warns, not errors,
        // and never overwrites the recorded decision.
        $this->postDecide(['uuid' => $uuid, 'decision' => ApprovalInterface::STATUS_REJECTED]);

        $warnings = $this->_objectManager->get(MessageManagerInterface::class)
            ->getMessages()
            ->getItemsByType(MessageInterface::TYPE_WARNING);
        $this->assertNotEmpty($warnings, 'A lost decision race surfaces a warning, not an error');
        $this->assertSame(
            ApprovalInterface::STATUS_APPROVED,
            $this->taskStatus($executionId),
            'The already-recorded decision is never overwritten by the losing attempt'
        );
    }

    /**
     * Park the fixture workflow's gate: look the workflow up by name, seed a
     * pending execution against its snapshot, and drive the real ExecuteConsumer
     * so the gate parks and its task is created.
     */
    private function parkFixtureExecution(): int
    {
        $searchCriteria = $this->_objectManager->create(SearchCriteriaBuilder::class)
            ->addFilter('name', 'Approval gate integration fixture')
            ->create();
        $items = $this->_objectManager->get(WorkflowRepositoryInterface::class)
            ->getList($searchCriteria)
            ->getItems();
        $this->assertNotEmpty($items, 'The approval-gate fixture workflow must be present');
        /** @var WorkflowInterface $workflow */
        $workflow = array_values($items)[0];

        $execution = $this->seedExecution(
            (int) $workflow->getWorkflowId(),
            $workflow->getDefinition(),
            4242,
            1
        );
        $executionId = (int) $execution->getExecutionId();
        $this->om()->get(\MageOS\Workflows\Model\Queue\ExecuteConsumer::class)->process((string) $executionId);

        return $executionId;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function postDecide(array $data): void
    {
        $data['form_key'] = $this->_objectManager->get(FormKey::class)->getFormKey();
        $this->getRequest()->setMethod('POST')->setPostValue($data);
        $this->dispatch($this->uri);
    }
}
