<?php
/**
 * Shared fixture (docs/20-integration-test-plan.md §2.4, suite #26): one
 * enabled, event-triggered sales_order workflow whose entry step is an
 * `approval` gate (schema 4) with stop-step edges on every branch
 * (on_approved / on_rejected / on_timeout). Created through the REAL repository
 * save path, so it also transits ValidateWorkflowOnSave / ApprovalCheck on
 * every use — a gate that stops validating is itself a finding.
 *
 * Reference from tests as:
 *
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 *
 *   @magentoDataFixture MageOS_WorkflowsApprovals::Test/Integration/_files/workflow_approval_gate.php
 *
 * Look it up by name ('Approval gate integration fixture') via getList.
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
$workflow->setName('Approval gate integration fixture');
$workflow->setStatus(WorkflowInterface::STATUS_ENABLED);
$workflow->setTriggerType(WorkflowInterface::TRIGGER_TYPE_EVENT);
$workflow->setTriggerRef('sales.order.created');
$workflow->setEntityType('sales_order');
$workflow->setDefinition(json_encode([
    'schema' => 4,
    'entry' => 'gate',
    'steps' => [
        'gate' => [
            'type' => 'approval',
            'config' => ['title' => 'Approve the order', 'timeout' => 'PT8H'],
            'on_approved' => 'approved_end',
            'on_rejected' => 'rejected_end',
            'on_timeout' => 'timeout_end',
        ],
        'approved_end' => ['type' => 'stop'],
        'rejected_end' => ['type' => 'stop'],
        'timeout_end' => ['type' => 'stop'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

$repository->save($workflow);
