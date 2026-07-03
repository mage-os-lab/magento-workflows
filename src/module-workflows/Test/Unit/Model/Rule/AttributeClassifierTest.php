<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Rule;

use MageOS\Workflows\Model\Rule\AttributeClassifier;
use PHPUnit\Framework\TestCase;

class AttributeClassifierTest extends TestCase
{
    public function testClassifiesNestedTreeIntoSnapshotAndHydrationBuckets(): void
    {
        $conditionTree = [
            'type' => 'combine',
            'aggregator' => 'all',
            'value' => '1',
            'conditions' => [
                ['type' => 'order_attribute', 'attribute' => 'grand_total', 'operator' => '>=', 'value' => '500'],
                [
                    'type' => 'combine',
                    'aggregator' => 'any',
                    'value' => '1',
                    'conditions' => [
                        ['type' => 'order_attribute', 'attribute' => 'customer_group_id', 'operator' => '==', 'value' => '3'],
                        ['type' => 'order_attribute', 'attribute' => 'shipping_method', 'operator' => '==', 'value' => 'flatrate'],
                    ],
                ],
                // duplicate reference to an already-seen attribute
                ['type' => 'order_attribute', 'attribute' => 'grand_total', 'operator' => '<=', 'value' => '10000'],
            ],
        ];

        $classifier = new AttributeClassifier();
        $result = $classifier->classify($conditionTree, ['grand_total', 'customer_group_id']);

        sort($result['in_snapshot']);
        sort($result['needs_hydration']);

        $this->assertEquals(['customer_group_id', 'grand_total'], $result['in_snapshot']);
        $this->assertEquals(['shipping_method'], $result['needs_hydration']);
    }

    public function testEmptyTreeYieldsEmptyBuckets(): void
    {
        $classifier = new AttributeClassifier();
        $result = $classifier->classify(['type' => 'combine', 'conditions' => []], ['grand_total']);

        $this->assertEquals([], $result['in_snapshot']);
        $this->assertEquals([], $result['needs_hydration']);
    }

    public function testAllAttributesNeedHydrationWhenSnapshotEmpty(): void
    {
        $conditionTree = [
            'type' => 'combine',
            'conditions' => [
                ['type' => 'product_attribute', 'attribute' => 'sku', 'operator' => '==', 'value' => 'ABC'],
            ],
        ];

        $classifier = new AttributeClassifier();
        $result = $classifier->classify($conditionTree, []);

        $this->assertEquals([], $result['in_snapshot']);
        $this->assertEquals(['sku'], $result['needs_hydration']);
    }

    public function testNodesWithoutAttributeAreIgnored(): void
    {
        $conditionTree = [
            'type' => 'combine',
            'conditions' => [
                ['type' => 'combine', 'conditions' => []],
                ['type' => 'order_attribute', 'attribute' => '', 'operator' => '==', 'value' => 'x'],
            ],
        ];

        $classifier = new AttributeClassifier();
        $result = $classifier->classify($conditionTree, ['grand_total']);

        $this->assertEquals([], $result['in_snapshot']);
        $this->assertEquals([], $result['needs_hydration']);
    }
}
