<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\Rule\Condition;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\WorkflowsCustomer\Model\Rule\Condition\Customer\Attribute as CustomerAttribute;
use MageOS\WorkflowsCustomer\Model\Rule\Condition\Customer\Combine as CustomerCombine;
use MageOS\Workflows\Model\Rule\ConditionEvaluator;
use MageOS\Workflows\Model\WorkflowExecution;
use PHPUnit\Framework\TestCase;

/**
 * Plan #15 (docs/20-integration-test-plan.md §5) — customer condition
 * evaluation with real EAV metadata (docs/06-conditions.md):
 *   - the attribute denylist is honored against the OM-resolved condition
 *     class (closes the docs/19 sensitive-attribute tripwire for conditions);
 *   - a fixture-created custom EAV attribute auto-discovers into the option
 *     set (the differentiator vs. payload-only engines);
 *   - order-history aggregates evaluate against seeded orders through
 *     phase-2 hydration (CustomerAggregateProvider);
 *   - a vanished customer fails closed on revalidation.
 *
 * App isolation is enabled because HydrationProvider keeps a per-instance
 * identity map keyed by "customer:<id>" and the core customer fixture always
 * reuses id 1: without a fresh application per method, an earlier method (e.g.
 * testFlatAttributes, 0 orders) memoizes customer:1 and testOrderHistory would
 * then read that stale aggregate instead of its two seeded orders. It also
 * gives the EAV attribute-metadata cache a cold start so the fixture-created
 * custom attribute auto-discovers.
 *
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class CustomerConditionTest extends TestCase
{
    private ConditionEvaluator $evaluator;
    private CustomerRepositoryInterface $customerRepository;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->evaluator = $objectManager->get(ConditionEvaluator::class);
        $this->customerRepository = $objectManager->get(CustomerRepositoryInterface::class);
    }

    /**
     * Deny-by-default: credential/internal customer attributes are never
     * offered as condition targets, resolved from the live class.
     */
    public function testDenylistedAttributesNeverAppearAsConditionTargets(): void
    {
        $options = $this->attributeOptionCodes();

        foreach ([
            'password_hash',
            'rp_token',
            'rp_token_created_at',
            'confirmation',
            'failures_num',
            'lock_expires',
        ] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $options,
                sprintf('Sensitive attribute "%s" must never surface as a condition target', $forbidden)
            );
        }

        // The documented business basics + aggregates ARE offered.
        $this->assertContains('email', $options);
        $this->assertContains('group_id', $options);
        $this->assertContains('orders_count', $options);
        $this->assertContains('lifetime_sales', $options);
    }

    /**
     * A user-defined EAV attribute auto-discovers via metadata introspection.
     *
     * @magentoDataFixture MageOS_Workflows::Test/Integration/Rule/_files/customer_custom_attribute.php
     */
    public function testCustomEavAttributeAutoDiscovers(): void
    {
        $this->assertContains(
            'wf_loyalty_tier',
            $this->attributeOptionCodes(),
            'Custom customer EAV attribute must auto-discover into condition metadata'
        );
    }

    /**
     * @magentoDataFixture Magento/Customer/_files/customer.php
     */
    public function testFlatAttributesEvaluateAgainstTheRealCustomer(): void
    {
        $customer = $this->customerRepository->get('customer@example.com');
        $ctx = $this->contextFor((int)$customer->getId());

        $this->assertTrue(
            $this->evaluator->evaluateSerialized(
                $this->tree($this->leaf('email', '==', 'customer@example.com')),
                'customer',
                $ctx,
                false
            )
        );
        $this->assertTrue(
            $this->evaluator->evaluateSerialized(
                $this->tree($this->leaf('group_id', '==', (string)(int)$customer->getGroupId())),
                'customer',
                $ctx,
                false
            )
        );
        $this->assertFalse(
            $this->evaluator->evaluateSerialized(
                $this->tree($this->leaf('email', '==', 'someone-else@example.com')),
                'customer',
                $ctx,
                false
            )
        );
    }

    /**
     * Order-history aggregates are computed at hydration time and match the
     * two seeded orders (lifetime_sales 300, orders_count 2). Relies on the
     * class-level app isolation (see class docblock) to avoid a stale customer:1 aggregate
     * leaking in from an earlier method's hydration.
     *
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoDataFixture MageOS_Workflows::Test/Integration/Rule/_files/customer_orders.php
     */
    public function testOrderHistoryAggregatesEvaluate(): void
    {
        $customer = $this->customerRepository->get('customer@example.com');
        $ctx = $this->contextFor((int)$customer->getId());

        $this->assertTrue(
            $this->evaluator->evaluateSerialized(
                $this->tree($this->leaf('orders_count', '>=', '2')),
                'customer',
                $ctx,
                false
            ),
            'Seeded two non-canceled orders => orders_count >= 2'
        );
        $this->assertFalse(
            $this->evaluator->evaluateSerialized(
                $this->tree($this->leaf('orders_count', '>', '5')),
                'customer',
                $ctx,
                false
            )
        );
        $this->assertTrue(
            $this->evaluator->evaluateSerialized(
                $this->tree($this->leaf('lifetime_sales', '>=', '300')),
                'customer',
                $ctx,
                false
            )
        );
    }

    /**
     * A customer with no orders has orders_count = 0 but NO last_order_at:
     * an absent aggregate matches only negative operators (fail-toward-false).
     *
     * @magentoDataFixture Magento/Customer/_files/customer.php
     */
    public function testAbsentAggregateMatchesOnlyNegativeOperators(): void
    {
        $customer = $this->customerRepository->get('customer@example.com');
        $ctx = $this->contextFor((int)$customer->getId());

        // orders_count is always present (0), positive comparison works
        $this->assertTrue(
            $this->evaluator->evaluateSerialized(
                $this->tree($this->leaf('orders_count', '==', '0')),
                'customer',
                $ctx,
                false
            )
        );
        // days_since_last_order is absent -> "!=" matches, "==" cannot
        $this->assertTrue(
            $this->evaluator->evaluateSerialized(
                $this->tree($this->leaf('days_since_last_order', '!=', '10')),
                'customer',
                $ctx,
                false
            )
        );
        $this->assertFalse(
            $this->evaluator->evaluateSerialized(
                $this->tree($this->leaf('days_since_last_order', '==', '10')),
                'customer',
                $ctx,
                false
            )
        );
    }

    public function testVanishedCustomerFailsClosedOnRevalidation(): void
    {
        $ctx = $this->contextFor(0x6d16 /* nonexistent customer id */);
        $this->assertFalse(
            $this->evaluator->evaluateSerialized(
                $this->tree($this->leaf('group_id', '==', '1')),
                'customer',
                $ctx,
                true
            )
        );
    }

    /**
     * @return string[]
     */
    private function attributeOptionCodes(): array
    {
        /** @var CustomerAttribute $attribute */
        $attribute = Bootstrap::getObjectManager()->create(CustomerAttribute::class);
        return array_map('strval', array_keys($attribute->loadAttributeOptions()->getAttributeOption()));
    }

    private function contextFor(int $customerId): ExecutionContext
    {
        /** @var WorkflowExecution $execution */
        $execution = Bootstrap::getObjectManager()->create(WorkflowExecution::class);
        $execution->setUuid('22222222-2222-2222-2222-222222222222');
        $execution->setEntityId($customerId);
        $execution->setStoreId(1);
        return new ExecutionContext($execution, [], [], []);
    }

    private function leaf(string $attribute, string $operator, string $value): array
    {
        return [
            'type' => CustomerAttribute::class,
            'attribute' => $attribute,
            'operator' => $operator,
            'value' => $value,
        ];
    }

    private function tree(array ...$leaves): string
    {
        return (string)json_encode([
            'type' => CustomerCombine::class,
            'aggregator' => 'all',
            'value' => '1',
            'conditions' => array_values($leaves),
        ], JSON_THROW_ON_ERROR);
    }
}
