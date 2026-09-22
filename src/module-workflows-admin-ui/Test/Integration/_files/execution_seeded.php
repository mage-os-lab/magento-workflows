<?php
/**
 * Fixture (docs/20-integration-test-plan.md §6, suite #25): one completed
 * execution with a single completed step, so the admin Execution log/view
 * controllers have a real row to render. The workflow is created through the
 * real repository save path; the execution + step rows are written directly
 * (the engine is exercised elsewhere — here we only need a loadable row).
 *
 * Look the execution up by uuid 'aaaaaaaa-0000-0000-0000-000000000025'.
 *
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\WorkflowFactory;

$objectManager = Bootstrap::getObjectManager();

$definition = json_encode([
    'schema' => 1,
    'entry' => 's1',
    'steps' => [
        's1' => [
            'type' => 'action',
            'action' => 'order.add_comment',
            'config' => ['comment' => 'seeded execution'],
            'next' => null,
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

/** @var WorkflowInterface $workflow */
$workflow = $objectManager->get(WorkflowFactory::class)->create();
$workflow->setName('Execution seed workflow');
$workflow->setStatus(WorkflowInterface::STATUS_ENABLED);
$workflow->setTriggerType(WorkflowInterface::TRIGGER_TYPE_EVENT);
$workflow->setTriggerRef('sales.order.created');
$workflow->setEntityType('sales_order');
$workflow->setDefinition($definition);
$workflow = $objectManager->get(WorkflowRepositoryInterface::class)->save($workflow);

$resource = $objectManager->get(ResourceConnection::class);
$connection = $resource->getConnection();

$connection->insert($resource->getTableName('mageos_workflow_execution'), [
    'uuid' => 'aaaaaaaa-0000-0000-0000-000000000025',
    'workflow_id' => (int) $workflow->getWorkflowId(),
    'workflow_version' => 1,
    'definition_snapshot' => $definition,
    'entity_id' => 1,
    'store_id' => 1,
    'status' => 'complete',
    'trigger_type' => 'manual',
    'mode' => 'live',
    'context' => json_encode(['trigger' => ['entity_id' => 1], 'steps' => [], 'workflow' => ['entity_type' => 'sales_order']]),
]);
$executionId = (int) $connection->lastInsertId($resource->getTableName('mageos_workflow_execution'));

$connection->insert($resource->getTableName('mageos_workflow_execution_step'), [
    'execution_id' => $executionId,
    'step_key' => 's1',
    'status' => 'complete',
    'result' => json_encode(['ok' => true]),
]);
