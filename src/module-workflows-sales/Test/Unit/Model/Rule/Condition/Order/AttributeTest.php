<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Test\Unit\Model\Rule\Condition\Order;

use Magento\Framework\DataObject;
use MageOS\Workflows\Model\Rule\AggregateProviderInterface;
use MageOS\Workflows\Model\Rule\AggregateProviderPool;
use MageOS\WorkflowsSales\Model\Option\CartPriceRuleOptionSource;
use MageOS\WorkflowsSales\Model\Rule\Condition\Order\Attribute;
use PHPUnit\Framework\TestCase;

/**
 * Order attribute condition exposure of the lifecycle-flag aggregates (ORD-C1):
 * the aggregate codes contributed to the sales_order root through the pool
 * appear as condition targets, and drive the input-type / value-element
 * mapping (boolean -> Yes/No select, numeric) exactly like a flat column.
 *
 * The five collaborators of Attribute's real constructor have no standalone
 * shims (the condition is normally DI-wired), so the instance is built without
 * a constructor and only the pool it actually reads for aggregate exposure is
 * injected — mirroring the engine's AbstractWorkflowConditionTest approach of
 * exercising behavior in isolation from the Rule Context.
 */
class AttributeTest extends TestCase
{
    private function attributeWithPool(AggregateProviderPool $pool): Attribute
    {
        $ref = new \ReflectionClass(Attribute::class);
        /** @var Attribute $attribute */
        $attribute = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('aggregateProviderPool');
        $prop->setValue($attribute, $pool);
        return $attribute;
    }

    private function attributeWithSource(CartPriceRuleOptionSource $source): Attribute
    {
        $ref = new \ReflectionClass(Attribute::class);
        /** @var Attribute $attribute */
        $attribute = $this->attributeWithPool($this->pool());
        $prop = $ref->getProperty('cartPriceRuleOptionSource');
        $prop->setValue($attribute, $source);
        return $attribute;
    }

    private function ruleSourceReturning(array $options): CartPriceRuleOptionSource
    {
        return new class($options) extends CartPriceRuleOptionSource {
            /**
             * @param array<int, array{value: string, label: string}> $options
             */
            public function __construct(private readonly array $options)
            {
            }
            protected function loadOptions(): array
            {
                return $this->options;
            }
        };
    }

    private function pool(): AggregateProviderPool
    {
        $provider = new class implements AggregateProviderInterface {
            public function getAttributeMetadata(): array
            {
                return [
                    'can_ship' => ['label' => 'Can Ship', 'input_type' => 'boolean'],
                    'invoice_count' => ['label' => 'Invoice Count', 'input_type' => 'numeric'],
                ];
            }

            public function getAggregates(int $entityId): array
            {
                return [];
            }
        };
        return new AggregateProviderPool(['sales_order' => [$provider]]);
    }

    public function testLoadAttributeOptionsMergesAggregatesWithFlatColumns(): void
    {
        $attribute = $this->attributeWithPool($this->pool());

        $options = $attribute->loadAttributeOptions()->getAttributeOption();

        // Flat column still present...
        $this->assertArrayHasKey('status', $options);
        // ...alongside the pool-contributed aggregates.
        $this->assertArrayHasKey('can_ship', $options);
        $this->assertArrayHasKey('invoice_count', $options);
        $this->assertSame('Can Ship', (string)$options['can_ship']);
    }

    public function testInputTypeComesFromAggregateMetadataForContributedCodes(): void
    {
        $attribute = $this->attributeWithPool($this->pool());

        $attribute->setData('attribute', 'can_ship');
        $this->assertSame('boolean', $attribute->getInputType());

        $attribute->setData('attribute', 'invoice_count');
        $this->assertSame('numeric', $attribute->getInputType());
    }

    public function testFlatColumnInputTypeUnaffectedByAggregatePool(): void
    {
        $attribute = $this->attributeWithPool($this->pool());

        $attribute->setData('attribute', 'grand_total');
        $this->assertSame('numeric', $attribute->getInputType());

        $attribute->setData('attribute', 'status');
        $this->assertSame('select', $attribute->getInputType());
    }

    public function testBooleanAggregateOffersYesNoValueOptions(): void
    {
        $attribute = $this->attributeWithPool($this->pool());
        $attribute->setData('attribute', 'can_ship');

        $options = $attribute->getValueSelectOptions();

        $values = array_column($options, 'value');
        $this->assertTrue(in_array(1, $values, true));
        $this->assertTrue(in_array(0, $values, true));
        $this->assertSame('select', $attribute->getValueElementType());
    }

    // --- ORD-C2: applied_rule_ids multiselect --------------------------------

    public function testAppliedRuleIdsIsMultiselect(): void
    {
        $attribute = $this->attributeWithPool($this->pool());
        $attribute->setData('attribute', 'applied_rule_ids');

        $this->assertSame('multiselect', $attribute->getInputType());
        $this->assertSame('multiselect', $attribute->getValueElementType());
        $this->assertArrayHasKey('applied_rule_ids', $attribute->loadAttributeOptions()->getAttributeOption());
    }

    public function testAppliedRuleIdsOptionsComeFromCartPriceRuleSource(): void
    {
        $attribute = $this->attributeWithSource($this->ruleSourceReturning([
            ['value' => '1', 'label' => 'Summer Sale'],
            ['value' => '4', 'label' => 'VIP'],
        ]));
        $attribute->setData('attribute', 'applied_rule_ids');

        $options = $attribute->getValueSelectOptions();

        $this->assertSame(['1', '4'], array_column($options, 'value'));
        $this->assertSame(['Summer Sale', 'VIP'], array_column($options, 'label'));
    }

    public function testAppliedRuleIdsIsOneOfMatchesOnSetIntersection(): void
    {
        $attribute = $this->appliedRuleIdsCondition('()', '1,4');

        // Order matched rules 4 and 7: shares 4 with the selected {1,4} -> match.
        $this->assertTrue($attribute->validate(new DataObject(['applied_rule_ids' => '4,7'])));
        // Order matched rules 8 and 9: disjoint from {1,4} -> no match.
        $this->assertFalse($attribute->validate(new DataObject(['applied_rule_ids' => '8,9'])));
    }

    public function testAppliedRuleIdsIsNotOneOfIsTheSetComplement(): void
    {
        $attribute = $this->appliedRuleIdsCondition('!()', '1,4');

        // Disjoint -> "is not one of" is true.
        $this->assertTrue($attribute->validate(new DataObject(['applied_rule_ids' => '8,9'])));
        // Intersecting -> false.
        $this->assertFalse($attribute->validate(new DataObject(['applied_rule_ids' => '4,7'])));
    }

    public function testAppliedRuleIdsEmptyOrderIsTheEmptySet(): void
    {
        // An order that matched no rule: "is one of" false, "is not one of" true.
        $this->assertFalse(
            $this->appliedRuleIdsCondition('()', '1,4')->validate(new DataObject(['applied_rule_ids' => '']))
        );
        $this->assertTrue(
            $this->appliedRuleIdsCondition('!()', '1,4')->validate(new DataObject(['applied_rule_ids' => '']))
        );
    }

    public function testAppliedRuleIdsAbsentFailsTowardFalse(): void
    {
        // Attribute entirely absent (no snapshot value, no hydration provider):
        // only the negative operator matches.
        $this->assertFalse(
            $this->appliedRuleIdsCondition('()', '1,4')->validate(new DataObject([]))
        );
        $this->assertTrue(
            $this->appliedRuleIdsCondition('!()', '1,4')->validate(new DataObject([]))
        );
    }

    private function appliedRuleIdsCondition(string $operator, string $value): Attribute
    {
        $attribute = $this->attributeWithPool($this->pool());
        $attribute->setData('attribute', 'applied_rule_ids');
        $attribute->setData('operator', $operator);
        $attribute->setData('value', $value);
        return $attribute;
    }
}
