<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Test\Integration\Controller\Adminhtml;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Framework\Message\MessageInterface;
use Magento\TestFramework\TestCase\AbstractBackendController;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;

/**
 * Plan #25 (docs/20-integration-test-plan.md §6): the Save controller
 * round-trip through the real F2 validation pipeline behind
 * WorkflowRepositoryInterface::save. A valid definition persists a row and
 * surfaces the success message; an invalid definition re-renders with an error
 * and persists nothing. Form key + ACL are enforced by the framework
 * (AbstractBackendController supplies the ACL has/no-access contract).
 *
 * @magentoAppArea adminhtml
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class WorkflowSaveTest extends AbstractBackendController
{
    /**
     * @var string
     */
    protected $uri = 'backend/mageos_workflows/workflow/save';

    /**
     * @var string
     */
    protected $resource = 'MageOS_Workflows::manage';

    /**
     * @var string
     */
    protected $httpMethod = 'POST';

    public function testSavePersistsWorkflowAndShowsSuccess(): void
    {
        $this->postSave([
            'name' => 'Controller saved workflow',
            'status' => WorkflowInterface::STATUS_ENABLED,
            'trigger_type' => WorkflowInterface::TRIGGER_TYPE_EVENT,
            'trigger_ref' => 'sales.order.created',
            'entity_type' => 'sales_order',
            'definition' => $this->validDefinition(),
            'loop_guard_depth' => 1,
        ]);

        $this->assertSessionMessages(
            $this->equalTo([(string) __('The workflow has been saved.')]),
            MessageInterface::TYPE_SUCCESS
        );

        $items = $this->workflowsNamed('Controller saved workflow');
        $this->assertCount(1, $items, 'A valid save must persist exactly one workflow row');
        $saved = array_values($items)[0];
        $this->assertSame(WorkflowInterface::STATUS_ENABLED, $saved->getStatus());
        $this->assertSame('sales_order', $saved->getEntityType());
    }

    public function testInvalidDefinitionReRendersWithErrorAndPersistsNothing(): void
    {
        $this->postSave([
            'name' => 'Invalid definition workflow',
            'status' => WorkflowInterface::STATUS_ENABLED,
            'trigger_type' => WorkflowInterface::TRIGGER_TYPE_EVENT,
            'trigger_ref' => 'sales.order.created',
            'entity_type' => 'sales_order',
            // Malformed JSON: Definition::fromJson throws before persistence.
            'definition' => '{"schema":1,"entry":"s1",',
            'loop_guard_depth' => 1,
        ]);

        $errors = $this->_objectManager->get(MessageManagerInterface::class)
            ->getMessages()
            ->getItemsByType(MessageInterface::TYPE_ERROR);
        $this->assertNotEmpty($errors, 'An invalid definition must surface an error message');

        $this->assertCount(
            0,
            $this->workflowsNamed('Invalid definition workflow'),
            'A failed validation must persist no workflow row'
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function postSave(array $data): void
    {
        $data['form_key'] = $this->_objectManager->get(FormKey::class)->getFormKey();
        $this->getRequest()->setMethod('POST')->setPostValue($data);
        $this->dispatch($this->uri);
    }

    /**
     * @return WorkflowInterface[]
     */
    private function workflowsNamed(string $name): array
    {
        $searchCriteria = $this->_objectManager->create(SearchCriteriaBuilder::class)
            ->addFilter('name', $name)
            ->create();
        return $this->_objectManager->get(WorkflowRepositoryInterface::class)
            ->getList($searchCriteria)
            ->getItems();
    }

    private function validDefinition(): string
    {
        return (string) json_encode([
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => 'action',
                    'action' => 'order.add_comment',
                    'config' => ['comment' => 'controller save'],
                    'next' => null,
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
