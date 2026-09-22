<?php
/**
 * Shared fixture (docs/20-integration-test-plan.md §2.4): one enabled,
 * event-triggered sales_order workflow with a single linear action step,
 * created through the REAL repository save path — so it also transits
 * ValidateWorkflowOnSave on every use. Reference from tests as:
 *
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 *
 *   @magentoDataFixture MageOS_Workflows::Test/Integration/_files/workflow_linear.php
 *
 * Look it up by name ('Integration linear fixture') via getList, or through
 * \MageOS\Workflows\Test\Integration\_files\WorkflowFixtureLocator.
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
$workflow->setName('Integration linear fixture');
$workflow->setStatus(WorkflowInterface::STATUS_ENABLED);
$workflow->setTriggerType(WorkflowInterface::TRIGGER_TYPE_EVENT);
$workflow->setTriggerRef('sales.order.created');
$workflow->setEntityType('sales_order');
$workflow->setDefinition(json_encode([
    'schema' => 1,
    'entry' => 's1',
    'steps' => [
        's1' => [
            'type' => 'action',
            'action' => 'order.add_comment',
            'config' => ['comment' => 'workflow_linear fixture ran'],
            'next' => null,
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

$repository->save($workflow);
