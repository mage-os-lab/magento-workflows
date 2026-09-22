<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\Model;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\WorkflowFactory;
use PHPUnit\Framework\TestCase;

/**
 * Plan #2 (docs/20-integration-test-plan.md §4): WorkflowRepositoryInterface
 * round-trips against the real DB. Definition fidelity is asserted as decoded
 * JSON equality — the column is a MySQL `json` type, which normalizes key
 * order/whitespace, so byte identity is not the contract; semantic identity
 * (including multibyte/emoji content) is.
 *
 * Every save here also transits ValidateWorkflowOnSave through the real
 * merged DI (SYSTEM auth mode outside admin areas), so a fixture that stops
 * validating fails loudly in this suite first.
 *
 * @magentoDbIsolation enabled
 */
class WorkflowRepositoryTest extends TestCase
{
    private WorkflowRepositoryInterface $repository;
    private WorkflowFactory $workflowFactory;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->repository = $objectManager->get(WorkflowRepositoryInterface::class);
        $this->workflowFactory = $objectManager->get(WorkflowFactory::class);
    }

    public function testSaveAndGetByIdRoundTripsAllFields(): void
    {
        $definition = [
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => 'action',
                    'action' => 'order.add_comment',
                    // Multibyte + emoji content must survive the round trip
                    'config' => ['comment' => 'Bestellung geprüft ✅ — 高価値注文 🚨'],
                    'next' => null,
                ],
            ],
        ];
        $conditions = '{"type":"combine","aggregator":"all","value":"1","conditions":'
            . '[{"type":"order_attribute","attribute":"grand_total","operator":">=","value":"500"}]}';

        $workflow = $this->newWorkflow('repo round trip', $definition);
        $workflow->setConditionsSerialized($conditions);
        $workflow->setLoopGuardDepth(3);

        $saved = $this->repository->save($workflow);
        $workflowId = $saved->getWorkflowId();
        $this->assertNotNull($workflowId, 'Save must assign a workflow id');

        $loaded = $this->repository->getById((int)$workflowId);
        $this->assertSame('repo round trip', $loaded->getName());
        $this->assertSame(WorkflowInterface::STATUS_ENABLED, $loaded->getStatus());
        $this->assertSame(WorkflowInterface::TRIGGER_TYPE_EVENT, $loaded->getTriggerType());
        $this->assertSame('sales.order.created', $loaded->getTriggerRef());
        $this->assertSame('sales_order', $loaded->getEntityType());
        $this->assertSame($conditions, $loaded->getConditionsSerialized());
        $this->assertSame(3, $loaded->getLoopGuardDepth());
        $this->assertSame(1, $loaded->getVersion(), 'A fresh workflow starts at version 1');
        // `definition` is a MySQL json column: it normalizes object key order
        // (by key length, then value) and whitespace, so identity is decoded-
        // JSON equality (assertEquals), never byte/order identity (assertSame) —
        // exactly the semantic contract this suite's docblock states. The
        // multibyte/emoji content survives intact either way.
        $this->assertEquals(
            $definition,
            json_decode($loaded->getDefinition(), true),
            'Definition JSON must round-trip semantically, multibyte content intact'
        );
    }

    public function testWebsiteIdsPersistThroughTheLinkTable(): void
    {
        $workflow = $this->newWorkflow('website scoped', $this->linearDefinition());
        $workflow->setWebsiteIds([1]);
        $saved = $this->repository->save($workflow);

        $loaded = $this->repository->getById((int)$saved->getWorkflowId());
        $this->assertSame([1], array_map('intval', $loaded->getWebsiteIds()));

        // Clearing the scope removes the link rows
        $loaded->setWebsiteIds([]);
        $this->repository->save($loaded);
        $reloaded = $this->repository->getById((int)$saved->getWorkflowId());
        $this->assertSame([], $reloaded->getWebsiteIds());
    }

    public function testGetByIdUnknownIdThrows(): void
    {
        $this->expectException(NoSuchEntityException::class);
        $this->repository->getById(99999999);
    }

    public function testGetListFiltersSortsAndCounts(): void
    {
        $this->repository->save($this->newWorkflow('list fixture b', $this->linearDefinition()));
        $this->repository->save($this->newWorkflow('list fixture a', $this->linearDefinition()));

        $objectManager = Bootstrap::getObjectManager();
        $sortOrder = $objectManager->create(SortOrderBuilder::class)
            ->setField(WorkflowInterface::NAME)
            ->setAscendingDirection()
            ->create();
        $searchCriteria = $objectManager->create(SearchCriteriaBuilder::class)
            ->addFilter(WorkflowInterface::NAME, 'list fixture %', 'like')
            ->addSortOrder($sortOrder)
            ->create();

        $results = $this->repository->getList($searchCriteria);
        $this->assertSame(2, $results->getTotalCount());
        $this->assertSame(
            ['list fixture a', 'list fixture b'],
            array_values(array_map(
                static fn (WorkflowInterface $item): string => $item->getName(),
                $results->getItems()
            ))
        );
    }

    public function testDeleteRemovesTheRow(): void
    {
        $saved = $this->repository->save($this->newWorkflow('to delete', $this->linearDefinition()));
        $workflowId = (int)$saved->getWorkflowId();

        $this->assertTrue($this->repository->delete($saved));

        $this->expectException(NoSuchEntityException::class);
        $this->repository->getById($workflowId);
    }

    /**
     * @magentoDataFixture MageOS_Workflows::Test/Integration/_files/workflow_linear.php
     */
    public function testSharedFixtureLoadsThroughTheRealSavePath(): void
    {
        $searchCriteria = Bootstrap::getObjectManager()->create(SearchCriteriaBuilder::class)
            ->addFilter(WorkflowInterface::NAME, 'Integration linear fixture')
            ->create();
        $items = $this->repository->getList($searchCriteria)->getItems();
        $this->assertCount(1, $items);
        $fixture = array_values($items)[0];
        $this->assertSame(WorkflowInterface::STATUS_ENABLED, $fixture->getStatus());
        $this->assertSame('sales_order', $fixture->getEntityType());
    }

    private function newWorkflow(string $name, array $definition): WorkflowInterface
    {
        $workflow = $this->workflowFactory->create();
        $workflow->setName($name);
        $workflow->setStatus(WorkflowInterface::STATUS_ENABLED);
        $workflow->setTriggerType(WorkflowInterface::TRIGGER_TYPE_EVENT);
        $workflow->setTriggerRef('sales.order.created');
        $workflow->setEntityType('sales_order');
        $workflow->setDefinition(json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $workflow;
    }

    private function linearDefinition(): array
    {
        return [
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => 'action',
                    'action' => 'order.add_comment',
                    'config' => ['comment' => 'integration'],
                    'next' => null,
                ],
            ],
        ];
    }
}
