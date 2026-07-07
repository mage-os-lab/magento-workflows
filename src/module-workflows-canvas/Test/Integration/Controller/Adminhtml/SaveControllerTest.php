<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCanvas\Test\Integration\Controller\Adminhtml;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Message\MessageInterface;
use Magento\TestFramework\TestCase\AbstractBackendController;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;

/**
 * Plan #27 (docs/20-integration-test-plan.md §6): the canvas save path. The
 * canvas has NO save endpoint of its own — it posts the mapped definition
 * through the EXISTING admin Save controller (mageos_workflows/workflow/save),
 * exactly as buildSavePayload (app/src/saveClient.ts) constructs it, so the
 * identical gauntlet applies: Definition::fromJson->toJson normalization, the
 * WorkflowRepositoryInterface::save before-plugin (ValidateWorkflowOnSave —
 * suite #4's pipeline), the form-key check, and the ::manage ADMIN_RESOURCE
 * gate. This pins the PHP side the mocked Playwright smoke trusts entirely: a
 * canvas-shaped POST persists a row whose definition round-trips decoded-equal.
 * ACL has-access / no-access come from AbstractBackendController via
 * $uri/$resource (the shared admin Save route + ::manage resource the canvas
 * posts through).
 *
 * @magentoAppArea adminhtml
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class SaveControllerTest extends AbstractBackendController
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

    public function testCanvasShapedSavePersistsDefinitionDecodedEqual(): void
    {
        $definition = [
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => 'action',
                    'action' => 'order.add_comment',
                    'config' => ['comment' => 'saved from canvas'],
                    'next' => 's2',
                ],
                's2' => [
                    'type' => 'action',
                    'action' => 'order.add_comment',
                    'config' => ['comment' => 'second'],
                    'next' => null,
                ],
            ],
        ];

        // The exact field set app/src/saveClient.ts::buildSavePayload posts.
        $this->postSave([
            'name' => 'Canvas saved workflow',
            'status' => (string) WorkflowInterface::STATUS_ENABLED,
            'entity_type' => 'sales_order',
            'trigger_type' => WorkflowInterface::TRIGGER_TYPE_EVENT,
            'trigger_ref' => 'sales.order.created',
            'loop_guard_depth' => '1',
            'fan_out_relation' => '',
            'fan_out_cap' => '',
            'conditions_serialized' => '',
            'definition' => json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'back' => '1',
        ]);

        $this->assertSessionMessages(
            $this->equalTo([(string) __('The workflow has been saved.')]),
            MessageInterface::TYPE_SUCCESS
        );

        $items = $this->workflowsNamed('Canvas saved workflow');
        $this->assertCount(1, $items, 'A valid canvas save persists exactly one workflow row');
        $saved = array_values($items)[0];
        $this->assertSame(WorkflowInterface::STATUS_ENABLED, (int) $saved->getStatus());
        $this->assertSame('sales_order', $saved->getEntityType());

        // Server re-normalizes via fromJson->toJson; equality is decoded, not byte.
        $this->assertSame(
            $definition,
            json_decode((string) $saved->getDefinition(), true),
            'The canvas-posted definition round-trips decoded-equal through the save controller'
        );
    }

    public function testInvalidCanvasDefinitionPersistsNothing(): void
    {
        $this->postSave([
            'name' => 'Canvas invalid workflow',
            'status' => (string) WorkflowInterface::STATUS_ENABLED,
            'entity_type' => 'sales_order',
            'trigger_type' => WorkflowInterface::TRIGGER_TYPE_EVENT,
            'trigger_ref' => 'sales.order.created',
            'loop_guard_depth' => '1',
            // Malformed JSON: Definition::fromJson throws before persistence.
            'definition' => '{"schema":1,"entry":"s1",',
            'back' => '1',
        ]);

        $this->assertCount(
            0,
            $this->workflowsNamed('Canvas invalid workflow'),
            'A failed validation persists no workflow row'
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
}
