<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Test\Unit\Model\Rule\Condition\Order;

use MageOS\Workflows\Model\Rule\AggregateProviderInterface;
use MageOS\Workflows\Model\Rule\AggregateProviderPool;
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
}
