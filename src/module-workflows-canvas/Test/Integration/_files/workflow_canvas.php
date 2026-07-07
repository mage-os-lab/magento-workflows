<?php
/**
 * Shared fixture (docs/20-integration-test-plan.md §2.4, suite #27): one
 * enabled, event-triggered sales_order workflow with a single linear action
 * step and a root condition tree, created through the REAL repository save path.
 * The canvas Data (mount) payload bootstraps the editor from a workflow like
 * this; the fixture gives the mount test a persisted workflow to load.
 *
 * Reference from tests as:
 *   @magentoDataFixture MageOS_WorkflowsCanvas::Test/Integration/_files/workflow_canvas.php
 *
 * Look it up by name ('Canvas mount fixture') via getList.
 */
declare(strict_types=1);

use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\WorkflowFactory;

$objectManager = Bootstrap::getObjectManager();
$repository = $objectManager->get(WorkflowRepositoryInterface::class);

/** @var WorkflowInterface $workflow */
$workflow = $objectManager->get(WorkflowFactory::class)->create();
$workflow->setName('Canvas mount fixture');
$workflow->setStatus(WorkflowInterface::STATUS_ENABLED);
$workflow->setTriggerType(WorkflowInterface::TRIGGER_TYPE_EVENT);
$workflow->setTriggerRef('sales.order.created');
$workflow->setEntityType('sales_order');
$workflow->setConditionsSerialized(
    '{"type":"combine","aggregator":"all","value":"1","conditions":'
    . '[{"type":"order_attribute","attribute":"grand_total","operator":">=","value":"100"}]}'
);
$workflow->setDefinition(json_encode([
    'schema' => 1,
    'entry' => 's1',
    'steps' => [
        's1' => [
            'type' => 'action',
            'action' => 'order.add_comment',
            'config' => ['comment' => 'canvas mount fixture'],
            'next' => null,
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

$repository->save($workflow);
