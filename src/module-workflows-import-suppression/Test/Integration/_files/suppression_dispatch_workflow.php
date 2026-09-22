<?php
/**
 * Shared fixture (docs/20-integration-test-plan.md §2.4): one enabled,
 * manually-dispatchable sales_order workflow with a single linear action
 * step, saved through the real repository (transits ValidateWorkflowOnSave).
 * Used by ImportSuppressionTest to prove Dispatcher::dispatch() is gated by
 * WorkflowSuppression before and after a real Import::importSource() call.
 *
 * Reference as:
 *
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 *
 *   @magentoDataFixture MageOS_WorkflowsImportSuppression::Test/Integration/_files/suppression_dispatch_workflow.php
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
$workflow->setName('Import suppression dispatch fixture');
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
            'config' => ['comment' => 'suppression_dispatch_workflow fixture ran'],
            'next' => null,
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

$repository->save($workflow);
