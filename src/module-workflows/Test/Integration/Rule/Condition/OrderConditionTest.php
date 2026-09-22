<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\Rule\Condition;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\WorkflowsSales\Model\Rule\Condition\Order\Attribute as OrderAttribute;
use MageOS\WorkflowsSales\Model\Rule\Condition\Order\Combine as OrderCombine;
use MageOS\Workflows\Model\Rule\ConditionEvaluator;
use MageOS\Workflows\Model\WorkflowExecution;
use PHPUnit\Framework\TestCase;

/**
 * Plan #15 (docs/20-integration-test-plan.md §5) — sales_order condition
 * evaluation against a REAL order loaded through the two-phase evaluator
 * (docs/06-conditions.md). Trigger snapshot is left empty so every leaf
 * misses phase 1 and forces phase-2 hydration of the real order via the
 * OrderHydrator — i.e. these assertions exercise real EAV/flat column reads,
 * not a hand-fed payload.
 *
 * Attribute values are read back from the loaded order and conditions are
 * built RELATIVE to them, so the pins survive core-fixture drift across the
 * 2.4.6–2.4.9 matrix.
 *
 * @magentoDbIsolation enabled
 */
class OrderConditionTest extends TestCase
{
    private ConditionEvaluator $evaluator;
    private OrderRepositoryInterface $orderRepository;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->evaluator = $objectManager->get(ConditionEvaluator::class);
        $this->orderRepository = $objectManager->get(OrderRepositoryInterface::class);
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testNumericAndSelectAttributesEvaluateAgainstTheRealOrder(): void
    {
        $order = $this->loadFixtureOrder();
        $ctx = $this->contextFor($order);
        $grandTotal = (float)$order->getGrandTotal();
        $status = (string)$order->getStatus();

        // grand_total >= its own value: true; strictly greater than its own value: false
        $this->assertTrue(
            $this->evaluator->evaluateSerialized(
                $this->tree($this->leaf('grand_total', '>=', (string)$grandTotal)),
                'sales_order',
                $ctx,
                false
            ),
            'grand_total >= actual should match the hydrated order'
        );
        $this->assertFalse(
            $this->evaluator->evaluateSerialized(
                $this->tree($this->leaf('grand_total', '>', (string)($grandTotal + 1))),
                'sales_order',
                $ctx,
                false
            )
        );

        // status == actual status: true; a bogus status: false
        $this->assertTrue(
            $this->evaluator->evaluateSerialized(
                $this->tree($this->leaf('status', '==', $status)),
                'sales_order',
                $ctx,
                false
            )
        );
        $this->assertFalse(
            $this->evaluator->evaluateSerialized(
                $this->tree($this->leaf('status', '==', '__no_such_status__')),
                'sales_order',
                $ctx,
                false
            )
        );
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testCustomerEmailAndGroupAttributesEvaluate(): void
    {
        $order = $this->loadFixtureOrder();
        $ctx = $this->contextFor($order);

        $this->assertTrue(
            $this->evaluator->evaluateSerialized(
                $this->tree($this->leaf('customer_email', '==', (string)$order->getCustomerEmail())),
                'sales_order',
                $ctx,
                false
            )
        );

        // The core guest order fixture never sets customer_group_id, and the
        // sales_order column is nullable with no default — so on this order the
        // attribute is ABSENT, not 0. An absent attribute only matches negative
        // operators (fail-toward-false), so a naive "== 0" cannot pass. Assert
        // relative to the real value: equality when present, absent-semantics
        // when null, keeping the pin robust if a version starts defaulting it.
        $groupId = $order->getCustomerGroupId();
        if ($groupId === null) {
            $this->assertFalse(
                $this->evaluator->evaluateSerialized(
                    $this->tree($this->leaf('customer_group_id', '==', '0')),
                    'sales_order',
                    $ctx,
                    false
                ),
                'An absent customer_group_id cannot satisfy a positive equality'
            );
            $this->assertTrue(
                $this->evaluator->evaluateSerialized(
                    $this->tree($this->leaf('customer_group_id', '!=', '0')),
                    'sales_order',
                    $ctx,
                    false
                ),
                'An absent customer_group_id matches the negative operator'
            );
        } else {
            $this->assertTrue(
                $this->evaluator->evaluateSerialized(
                    $this->tree($this->leaf('customer_group_id', '==', (string)(int)$groupId)),
                    'sales_order',
                    $ctx,
                    false
                )
            );
        }
    }

    /**
     * A combine with ALL aggregator short-circuits false on any failing leaf.
     *
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testAllAggregatorRequiresEveryLeaf(): void
    {
        $order = $this->loadFixtureOrder();
        $ctx = $this->contextFor($order);
        $grandTotal = (float)$order->getGrandTotal();

        $json = $this->tree(
            $this->leaf('grand_total', '>=', (string)$grandTotal),
            $this->leaf('status', '==', '__no_such_status__')
        );
        $this->assertFalse(
            $this->evaluator->evaluateSerialized($json, 'sales_order', $ctx, false),
            'ALL must be false when one leaf fails'
        );
    }

    /**
     * Empty condition tree means "always run" (docs/06): true regardless of entity.
     *
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testEmptyConditionsAlwaysTrue(): void
    {
        $order = $this->loadFixtureOrder();
        $ctx = $this->contextFor($order);
        $this->assertTrue($this->evaluator->evaluateSerialized('', 'sales_order', $ctx, false));
        $this->assertTrue($this->evaluator->evaluateSerialized('{}', 'sales_order', $ctx, false));
    }

    /**
     * Vanished entity + revalidate_entity=true fails closed (evaluates false)
     * per docs/06 — the entity can no longer be hydrated.
     */
    public function testVanishedEntityFailsClosedOnRevalidation(): void
    {
        $execution = $this->newExecution(0x6d15 /* nonexistent order id */, 1);
        $ctx = new ExecutionContext($execution, [], [], []);

        $json = $this->tree($this->leaf('grand_total', '>=', '0'));
        $this->assertFalse(
            $this->evaluator->evaluateSerialized($json, 'sales_order', $ctx, true),
            'A revalidated condition over a missing order must fail closed'
        );
    }

    /**
     * Malformed condition JSON throws rather than silently running/skipping
     * (ConditionEvaluator::decode contract, docs/06 fail-explicit stance).
     */
    public function testMalformedConditionsThrow(): void
    {
        $execution = $this->newExecution(1, 1);
        $ctx = new ExecutionContext($execution, [], [], []);
        $this->expectException(\InvalidArgumentException::class);
        $this->evaluator->evaluateSerialized('{not valid json', 'sales_order', $ctx, false);
    }

    /**
     * Denylist is honored against the live, OM-resolved condition class: the
     * order condition never exposes credential/internal columns as targets.
     * password_hash/rp_token live on customer, but the order attribute set is
     * a fixed flat whitelist, so this pins that no sensitive column leaks in.
     */
    public function testOrderAttributeOptionsExposeNoSensitiveColumns(): void
    {
        /** @var OrderAttribute $attribute */
        $attribute = Bootstrap::getObjectManager()->create(OrderAttribute::class);
        $options = array_keys($attribute->loadAttributeOptions()->getAttributeOption());

        foreach (['password_hash', 'rp_token', 'rp_token_created_at'] as $forbidden) {
            $this->assertNotContains($forbidden, $options);
        }
        // Sanity: the documented business columns ARE offered.
        $this->assertContains('grand_total', $options);
        $this->assertContains('status', $options);
    }

    /**
     * The root combine resolves through the real ConditionCombinePool merged
     * from di.xml — an order tree is served by Condition\Order\Combine.
     */
    public function testRootCombineResolvesFromMergedPool(): void
    {
        $combine = Bootstrap::getObjectManager()
            ->get(\MageOS\Workflows\Model\Rule\ConditionCombinePool::class)
            ->getCombine('sales_order');
        $this->assertInstanceOf(OrderCombine::class, $combine);
    }

    private function loadFixtureOrder(): Order
    {
        $order = Bootstrap::getObjectManager()->create(Order::class)->loadByIncrementId('100000001');
        $this->assertNotEmpty($order->getId(), 'Core order fixture 100000001 must load');
        return $this->orderRepository->get((int)$order->getId());
    }

    private function contextFor(Order $order): ExecutionContext
    {
        return new ExecutionContext(
            $this->newExecution((int)$order->getId(), (int)$order->getStoreId()),
            [],
            [],
            []
        );
    }

    private function newExecution(int $entityId, int $storeId): WorkflowExecution
    {
        /** @var WorkflowExecution $execution */
        $execution = Bootstrap::getObjectManager()->create(WorkflowExecution::class);
        $execution->setUuid('11111111-1111-1111-1111-111111111111');
        $execution->setEntityId($entityId);
        $execution->setStoreId($storeId);
        return $execution;
    }

    private function leaf(string $attribute, string $operator, string $value): array
    {
        return [
            'type' => OrderAttribute::class,
            'attribute' => $attribute,
            'operator' => $operator,
            'value' => $value,
        ];
    }

    private function tree(array ...$leaves): string
    {
        return (string)json_encode([
            'type' => OrderCombine::class,
            'aggregator' => 'all',
            'value' => '1',
            'conditions' => array_values($leaves),
        ], JSON_THROW_ON_ERROR);
    }
}
