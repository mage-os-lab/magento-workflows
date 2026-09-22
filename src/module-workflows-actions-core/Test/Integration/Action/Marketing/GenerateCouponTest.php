<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Integration\Action\Marketing;

use Magento\Framework\App\ResourceConnection;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory as RuleCollectionFactory;
use MageOS\WorkflowsSales\Action\Marketing\GenerateCoupon;
use MageOS\WorkflowsActionsCore\Test\Integration\Action\ActionTestCase;

/**
 * Plan #20 (docs/20-integration-test-plan.md §5) — marketing.generate_coupon
 * creates a real salesrule_coupon row from an auto-generation cart price rule
 * and surfaces the code in step output for downstream steps (docs/07 Marketing).
 *
 * @magentoDbIsolation enabled
 */
class GenerateCouponTest extends ActionTestCase
{
    private GenerateCoupon $action;
    private ResourceConnection $resource;

    protected function setUp(): void
    {
        $this->action = $this->resolve(GenerateCoupon::class);
        $this->resource = $this->resolve(ResourceConnection::class);
    }

    /**
     * @magentoDataFixture MageOS_WorkflowsActionsCore::Test/Integration/_files/cart_price_rule_autogen.php
     */
    public function testCreatesCouponFromRule(): void
    {
        $ruleId = $this->ruleId('WF Autogen Rule');
        $ctx = $this->buildContext(1, 1);

        $result = $this->action->execute($ctx, ['rule_id' => $ruleId]);
        $this->assertTrue($result->isSuccess(), $result->getError() ?? '');

        $code = (string)$result->getOutput()['coupon_code'];
        $this->assertNotSame('', $code);
        $this->assertSame($ruleId, (int)$result->getOutput()['rule_id']);

        // A real coupon row exists for the rule with the generated code.
        $connection = $this->resource->getConnection();
        $storedRuleId = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('salesrule_coupon'), ['rule_id'])
                ->where('code = ?', $code)
        );
        $this->assertSame($ruleId, (int)$storedRuleId, 'A salesrule_coupon row must exist for the generated code');
    }

    public function testNonexistentRuleFails(): void
    {
        $ctx = $this->buildContext(1, 1);
        $result = $this->action->execute($ctx, ['rule_id' => 99999999]);
        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('does not exist', (string)$result->getError());
    }

    public function testMissingRuleIdIsTerminalFailure(): void
    {
        $ctx = $this->buildContext(1, 1);
        $result = $this->action->execute($ctx, []);
        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
    }

    public function testConfigFormRuleIdSearchesTheCartPriceRuleSource(): void
    {
        $field = $this->action->getConfigForm()[0];

        $this->assertSame('rule_id', $field['name']);
        $this->assertSame(['source' => 'cart_price_rules', 'min_chars' => 0], $field['options_search']);
        $this->assertTrue($field['required']);
        $this->assertStringContainsString('auto-generated', (string)$field['notice']);
    }

    private function ruleId(string $name): int
    {
        /** @var RuleCollectionFactory $factory */
        $factory = $this->resolve(RuleCollectionFactory::class);
        $rule = $factory->create()->addFieldToFilter('name', $name)->getFirstItem();
        $this->assertNotEmpty($rule->getId(), 'Cart price rule fixture must load');
        return (int)$rule->getId();
    }
}
