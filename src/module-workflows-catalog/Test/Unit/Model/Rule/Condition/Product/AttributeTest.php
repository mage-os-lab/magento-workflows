<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCatalog\Test\Unit\Model\Rule\Condition\Product;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as ProductAttributeCollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\CollectionFactory as AttributeSetCollectionFactory;
use Magento\Rule\Model\Condition\Context;
use Magento\Store\Api\WebsiteRepositoryInterface;
use MageOS\Workflows\Model\Rule\AggregateProviderInterface;
use MageOS\Workflows\Model\Rule\AggregateProviderPool;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;
use MageOS\WorkflowsCatalog\Model\Rule\Condition\Product\Attribute;
use PHPUnit\Framework\TestCase;

/**
 * Catalog product-condition exposure of pool aggregate leaves (PRD-C1): the
 * product root must surface the stock leaves an inventory-side provider
 * contributes through AggregateProviderPool under 'catalog_product', with the
 * provider's declared input types, exactly as the customer root surfaces the
 * order-history aggregates. The provider itself is doubled here — this asserts
 * the CONSUMPTION wiring in the catalog pack, decoupled from which pack
 * registers the leaves.
 */
class AttributeTest extends TestCase
{
    private const AGGREGATES = [
        'qty' => ['label' => 'Stock: Quantity', 'input_type' => 'numeric'],
        'is_in_stock' => ['label' => 'Stock: In Stock', 'input_type' => 'boolean'],
        'salable_qty' => ['label' => 'Stock: Salable Quantity (MSI)', 'input_type' => 'numeric'],
    ];

    public function testAggregateLeavesAreOfferedAlongsideSpecialAttributes(): void
    {
        $options = $this->attribute()->loadAttributeOptions()->getAttributeOption();

        // Pool-contributed stock leaves are offered as condition targets...
        $this->assertArrayHasKey('qty', $options);
        $this->assertArrayHasKey('is_in_stock', $options);
        $this->assertArrayHasKey('salable_qty', $options);
        // ...alongside the always-present special attributes.
        $this->assertArrayHasKey('attribute_set_id', $options);
        $this->assertArrayHasKey('category_ids', $options);
        $this->assertArrayHasKey('website_ids', $options);
        $this->assertArrayHasKey('sku', $options);
    }

    public function testWebsiteIdsIsAMultiselectSpecialAttribute(): void
    {
        // PRD-C3: website membership mirrors category_ids' multiselect set
        // semantics.
        $attribute = $this->attribute();
        $attribute->setAttribute('website_ids');

        $this->assertSame('multiselect', $attribute->getInputType());
        $this->assertSame('multiselect', $attribute->getValueElementType());
    }

    public function testWebsiteIdsValueOptionsComeFromTheWebsiteSource(): void
    {
        $attribute = $this->attribute();
        $attribute->setAttribute('website_ids');

        $options = $attribute->getValueSelectOptions();
        $values = array_column($options, 'value');
        $labels = array_column($options, 'label');

        $this->assertSame(['1', '2'], $values);
        $this->assertTrue(in_array('Main Website', $labels, true));
        $this->assertTrue(in_array('B2B Website', $labels, true));
    }

    public function testAggregateLeavesUseTheProviderDeclaredInputTypes(): void
    {
        $this->assertSame('numeric', $this->inputTypeOf('qty'));
        $this->assertSame('boolean', $this->inputTypeOf('is_in_stock'));
        $this->assertSame('numeric', $this->inputTypeOf('salable_qty'));
    }

    public function testBooleanAggregateResolvesToASelectValueElement(): void
    {
        $attribute = $this->attribute();
        $attribute->setAttribute('is_in_stock');
        $this->assertSame('select', $attribute->getValueElementType());
    }

    public function testNoAggregatesRegisteredLeavesSpecialAttributesIntact(): void
    {
        // When no provider is registered for catalog_product the root degrades
        // gracefully: only the special attributes are offered, no stock leaves.
        $attribute = $this->attribute(new AggregateProviderPool([]));
        $options = $attribute->loadAttributeOptions()->getAttributeOption();

        $this->assertArrayHasKey('sku', $options);
        $this->assertFalse(array_key_exists('qty', $options));
        $this->assertFalse(array_key_exists('salable_qty', $options));
    }

    private function inputTypeOf(string $code): string
    {
        $attribute = $this->attribute();
        $attribute->setAttribute($code);
        return $attribute->getInputType();
    }

    private function attribute(?AggregateProviderPool $pool = null): Attribute
    {
        return new Attribute(
            // Every one of these Magento parents has a required DI constructor;
            // bypass it (empty __construct) so the double is instantiable under
            // real Magento, exactly as the proven condition tests do.
            new class extends Context {
                public function __construct()
                {
                }
            },
            $this->throwingProductAttributeCollectionFactory(),
            new class extends EavConfig {
                public function __construct()
                {
                }
            },
            new class extends AttributeSetCollectionFactory {
                public function __construct()
                {
                }
            },
            $pool ?? $this->poolWithStockProvider(),
            $this->websiteRepository()
        );
    }

    /**
     * Two-website system source for the website_ids special attribute (PRD-C3).
     */
    private function websiteRepository(): WebsiteRepositoryInterface
    {
        return new class implements WebsiteRepositoryInterface {
            public function get($code)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function getById($id)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function getList()
            {
                // Plain website-like data bags: only getId()/getName() are read.
                $make = static fn (int $id, string $name): object => new class ($id, $name) {
                    public function __construct(private int $id, private string $name)
                    {
                    }
                    public function getId(): int
                    {
                        return $this->id;
                    }
                    public function getName(): string
                    {
                        return $this->name;
                    }
                };
                return [$make(1, 'Main Website'), $make(2, 'B2B Website')];
            }

            public function getDefault()
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function clean()
            {
            }
        };
    }

    private function poolWithStockProvider(): AggregateProviderPool
    {
        $provider = new class (self::AGGREGATES) implements AggregateProviderInterface {
            public function __construct(private array $metadata)
            {
            }

            public function getAttributeMetadata(): array
            {
                return $this->metadata;
            }

            public function getAggregates(int $entityId): array
            {
                return ['qty' => 5.0, 'is_in_stock' => true];
            }
        };

        return new AggregateProviderPool([HydrationProviderInterface::TYPE_PRODUCT => [$provider]]);
    }

    /**
     * EAV discovery unavailable (no DB under the runner): loadAttributeOptions
     * catches the failure and keeps the special + aggregate attributes.
     */
    private function throwingProductAttributeCollectionFactory(): ProductAttributeCollectionFactory
    {
        return new class extends ProductAttributeCollectionFactory {
            // Bypass the generated factory's DI constructor (ObjectManager).
            public function __construct()
            {
            }
            public function create()
            {
                throw new \RuntimeException('no attribute collection under the standalone runner');
            }
        };
    }
}
