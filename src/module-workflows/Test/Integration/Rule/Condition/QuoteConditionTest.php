<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\Rule\Condition;

use Magento\Quote\Model\Quote;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\WorkflowsSales\Model\Rule\Condition\Quote\Attribute as QuoteAttribute;
use MageOS\WorkflowsSales\Model\Rule\Condition\Quote\Combine as QuoteCombine;
use MageOS\Workflows\Model\Rule\ConditionEvaluator;
use MageOS\Workflows\Model\WorkflowExecution;
use PHPUnit\Framework\TestCase;

/**
 * Plan #15 (docs/20-integration-test-plan.md §5) — quote (cart) condition
 * evaluation against a real, first-class quote fixture (docs/06: "quote is a
 * first-class root ... needs no workaround"). Empty trigger forces phase-2
 * hydration through the QuoteHydrator (CartRepositoryInterface).
 *
 * @magentoDbIsolation enabled
 */
class QuoteConditionTest extends TestCase
{
    private ConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = Bootstrap::getObjectManager()->get(ConditionEvaluator::class);
    }

    /**
     * @magentoDataFixture MageOS_Workflows::Test/Integration/Rule/_files/quote_abandoned.php
     */
    public function testGrandTotalAndItemsCountEvaluateAgainstTheRealQuote(): void
    {
        $ctx = $this->contextFor($this->loadQuoteId());

        // Stored grand_total is 150.00
        $this->assertTrue(
            $this->evaluator->evaluateSerialized(
                $this->tree($this->leaf('grand_total', '>=', '100')),
                'quote',
                $ctx,
                false
            ),
            '"cart total > $100" must match the hydrated quote'
        );
        $this->assertFalse(
            $this->evaluator->evaluateSerialized(
                $this->tree($this->leaf('grand_total', '>', '150')),
                'quote',
                $ctx,
                false
            )
        );
        $this->assertTrue(
            $this->evaluator->evaluateSerialized(
                $this->tree($this->leaf('items_count', '==', '2')),
                'quote',
                $ctx,
                false
            )
        );
    }

    /**
     * @magentoDataFixture MageOS_Workflows::Test/Integration/Rule/_files/quote_abandoned.php
     */
    public function testCustomerEmailEvaluates(): void
    {
        $ctx = $this->contextFor($this->loadQuoteId());
        $this->assertTrue(
            $this->evaluator->evaluateSerialized(
                $this->tree($this->leaf('customer_email', '==', 'cart.shopper@example.com')),
                'quote',
                $ctx,
                false
            )
        );
    }

    public function testVanishedQuoteFailsClosedOnRevalidation(): void
    {
        $ctx = $this->contextFor(0x6d18 /* nonexistent quote id */);
        $this->assertFalse(
            $this->evaluator->evaluateSerialized(
                $this->tree($this->leaf('grand_total', '>=', '0')),
                'quote',
                $ctx,
                true
            )
        );
    }

    public function testRootCombineResolvesFromMergedPool(): void
    {
        $combine = Bootstrap::getObjectManager()
            ->get(\MageOS\Workflows\Model\Rule\ConditionCombinePool::class)
            ->getCombine('quote');
        $this->assertInstanceOf(QuoteCombine::class, $combine);
    }

    private function loadQuoteId(): int
    {
        /** @var Quote $quote */
        $quote = Bootstrap::getObjectManager()->create(Quote::class);
        $quote->load('wf-quote-01', 'reserved_order_id');
        $this->assertNotEmpty($quote->getId(), 'Quote fixture wf-quote-01 must load');
        return (int)$quote->getId();
    }

    private function contextFor(int $quoteId): ExecutionContext
    {
        /** @var WorkflowExecution $execution */
        $execution = Bootstrap::getObjectManager()->create(WorkflowExecution::class);
        $execution->setUuid('44444444-4444-4444-4444-444444444444');
        $execution->setEntityId($quoteId);
        $execution->setStoreId(1);
        return new ExecutionContext($execution, [], [], []);
    }

    private function leaf(string $attribute, string $operator, string $value): array
    {
        return [
            'type' => QuoteAttribute::class,
            'attribute' => $attribute,
            'operator' => $operator,
            'value' => $value,
        ];
    }

    private function tree(array ...$leaves): string
    {
        return (string)json_encode([
            'type' => QuoteCombine::class,
            'aggregator' => 'all',
            'value' => '1',
            'conditions' => array_values($leaves),
        ], JSON_THROW_ON_ERROR);
    }
}
