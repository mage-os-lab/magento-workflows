<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Test\Unit\Model\Rule\Condition\Quote;

use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\ObjectManagerInterface;
use Magento\Rule\Model\Condition\AbstractCondition;
use Magento\Rule\Model\Condition\Context;
use MageOS\Workflows\Model\Rule\ConditionLeafPool;
use MageOS\WorkflowsSales\Model\Rule\Condition\Quote\ItemsFound;
use PHPUnit\Framework\TestCase;

/**
 * Stand-in catalog_product leaf: a real AbstractCondition (so ConditionLeafPool
 * accepts it) exposing one product attribute option, modelling the catalog
 * pack's product leaf as seen through the pool.
 */
class FakeProductLeaf extends AbstractCondition
{
    public function __construct()
    {
    }

    public function loadAttributeOptions()
    {
        $this->setAttributeOption(['brand' => __('Brand')]);
        return $this;
    }

    public function validate(\Magento\Framework\DataObject $model)
    {
        return true;
    }
}

/**
 * Cart-items ANY/ALL subtree (QTE-C1). The same FOUND / NOT FOUND aggregation
 * as Order\ItemsFound, resolving items from the quote snapshot's `items` array,
 * and degrading gracefully (no product-attribute children offered) when the
 * catalog pack's product leaf is not registered in the ConditionLeafPool.
 */
class ItemsFoundTest extends TestCase
{
    private function context(): Context
    {
        return new class extends Context {
            public function __construct()
            {
            }
        };
    }

    private function dataObjectFactory(): DataObjectFactory
    {
        return new class extends DataObjectFactory {
            public function __construct()
            {
            }

            public function create(array $arguments = []): DataObject
            {
                return new DataObject($arguments['data'] ?? []);
            }
        };
    }

    private function leafPool(?string $productLeafClass = null): ConditionLeafPool
    {
        $objectManager = new class implements ObjectManagerInterface {
            public function create($type, array $arguments = [])
            {
                return new $type();
            }
            public function get($type)
            {
                return new $type();
            }
            public function configure(array $configuration)
            {
            }
        };
        $leaves = $productLeafClass === null ? [] : ['catalog_product' => $productLeafClass];
        return new ConditionLeafPool($objectManager, $leaves);
    }

    private function itemsFound(?string $productLeafClass = null): ItemsFound
    {
        return new ItemsFound($this->context(), $this->leafPool($productLeafClass), $this->dataObjectFactory());
    }

    /**
     * Child condition matching an item on a single field.
     */
    private function child(string $field, mixed $wanted): object
    {
        return new class($field, $wanted) {
            public function __construct(private readonly string $field, private readonly mixed $wanted)
            {
            }
            public function validate(DataObject $item): bool
            {
                return $item->getData($this->field) === $this->wanted;
            }
        };
    }

    private function quoteSnapshot(array $items): DataObject
    {
        return new DataObject(['items' => $items]);
    }

    public function testFoundWhenAnItemMatchesTheChildCondition(): void
    {
        $itemsFound = $this->itemsFound();
        $itemsFound->setData('value', 1); // FOUND
        $itemsFound->setData('aggregator', 'all');
        $itemsFound->setConditions([$this->child('sku', 'WIDGET-1')]);

        $model = $this->quoteSnapshot([
            ['sku' => 'OTHER', 'product_id' => 5],
            ['sku' => 'WIDGET-1', 'product_id' => 9],
        ]);

        $this->assertTrue($itemsFound->validate($model));
    }

    public function testNotFoundValueInvertsTheResult(): void
    {
        $itemsFound = $this->itemsFound();
        $itemsFound->setData('value', 0); // NOT FOUND
        $itemsFound->setData('aggregator', 'all');
        $itemsFound->setConditions([$this->child('sku', 'ABSENT')]);

        $model = $this->quoteSnapshot([
            ['sku' => 'WIDGET-1', 'product_id' => 9],
        ]);

        // No item matches 'ABSENT', and value = NOT FOUND, so the condition holds.
        $this->assertTrue($itemsFound->validate($model));
    }

    public function testAllAggregatorRequiresEveryChildToMatchTheSameItem(): void
    {
        $itemsFound = $this->itemsFound();
        $itemsFound->setData('value', 1);
        $itemsFound->setData('aggregator', 'all');
        $itemsFound->setConditions([
            $this->child('sku', 'WIDGET-1'),
            $this->child('color', 'red'),
        ]);

        // The red item is a different SKU; the WIDGET-1 item is not red -> no single
        // item satisfies ALL children.
        $noSingleItem = $this->quoteSnapshot([
            ['sku' => 'WIDGET-1', 'color' => 'blue', 'product_id' => 9],
            ['sku' => 'OTHER', 'color' => 'red', 'product_id' => 3],
        ]);
        $this->assertFalse($itemsFound->validate($noSingleItem));

        // One item satisfies both children.
        $oneItem = $this->quoteSnapshot([
            ['sku' => 'WIDGET-1', 'color' => 'red', 'product_id' => 9],
        ]);
        $this->assertTrue($itemsFound->validate($oneItem));
    }

    public function testAnyAggregatorMatchesWhenEitherChildMatches(): void
    {
        $itemsFound = $this->itemsFound();
        $itemsFound->setData('value', 1);
        $itemsFound->setData('aggregator', 'any');
        $itemsFound->setConditions([
            $this->child('sku', 'WIDGET-1'),
            $this->child('color', 'red'),
        ]);

        $model = $this->quoteSnapshot([
            ['sku' => 'NOPE', 'color' => 'red', 'product_id' => 3],
        ]);

        $this->assertTrue($itemsFound->validate($model));
    }

    public function testNoItemsMeansNothingFound(): void
    {
        $itemsFound = $this->itemsFound();
        $itemsFound->setData('value', 1);
        $itemsFound->setData('aggregator', 'all');
        $itemsFound->setConditions([$this->child('sku', 'WIDGET-1')]);

        $this->assertFalse($itemsFound->validate($this->quoteSnapshot([])));
    }

    public function testChildOptionsDegradeWhenNoProductLeafRegistered(): void
    {
        $itemsFound = $this->itemsFound(null);

        $labels = array_map(
            static fn (array $option): string => (string)($option['label'] ?? ''),
            $itemsFound->getNewChildSelectOptions()
        );

        $this->assertFalse(in_array('Product Attribute', $labels, true));
    }

    public function testChildOptionsOfferProductAttributesWhenLeafRegistered(): void
    {
        $itemsFound = $this->itemsFound(FakeProductLeaf::class);

        $labels = array_map(
            static fn (array $option): string => (string)($option['label'] ?? ''),
            $itemsFound->getNewChildSelectOptions()
        );

        $this->assertTrue(in_array('Product Attribute', $labels, true));
    }
}
