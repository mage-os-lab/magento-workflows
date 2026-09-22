<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Rule;

use MageOS\Workflows\Model\Rule\AttributeClassifier;
use MageOS\Workflows\Model\Rule\Condition\RelatedEntity\Combine as RelatedEntityCombine;
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

    // ------------------------------------------------------------------
    // Node-type awareness (F4)
    // ------------------------------------------------------------------

    public function testForcingNodeTypeReportedEvenWithZeroAttributes(): void
    {
        // A childless NOT-EXISTS related-entity node references zero
        // attributes and would otherwise classify zero-query.
        $conditionTree = [
            'type' => 'combine',
            'conditions' => [
                ['type' => 'related_entity', 'relation' => 'order_customer', 'operator' => 'not_exists'],
            ],
        ];

        $classifier = new AttributeClassifier(['related_entity']);
        $result = $classifier->classify($conditionTree, ['grand_total']);

        $this->assertEquals([], $result['in_snapshot']);
        $this->assertEquals([], $result['needs_hydration']);
        $this->assertEquals(['related_entity'], $result['forcing_node_types']);
        $this->assertFalse($classifier->isZeroQuery($conditionTree, ['grand_total']));
    }

    public function testNoForcingTypesRegisteredKeepsZeroQueryClassification(): void
    {
        $conditionTree = [
            'type' => 'combine',
            'conditions' => [
                ['type' => 'related_entity', 'relation' => 'order_customer', 'operator' => 'not_exists'],
            ],
        ];

        $classifier = new AttributeClassifier();
        $result = $classifier->classify($conditionTree, []);

        $this->assertEquals([], $result['forcing_node_types']);
        $this->assertTrue($classifier->isZeroQuery($conditionTree, []));
    }

    public function testIsZeroQueryFalseWhenAttributeNeedsHydration(): void
    {
        $conditionTree = [
            'type' => 'combine',
            'conditions' => [
                ['type' => 'order_attribute', 'attribute' => 'shipping_method', 'operator' => '==', 'value' => 'x'],
            ],
        ];

        $classifier = new AttributeClassifier();

        $this->assertFalse($classifier->isZeroQuery($conditionTree, ['grand_total']));
        $this->assertTrue($classifier->isZeroQuery($conditionTree, ['shipping_method']));
    }

    public function testChildlessNotExistsRelatedEntityForcesHydration(): void
    {
        // The exact node the RelatedEntity combine emits (setType(self::class))
        // wired against the exact registry value from di.xml — a childless
        // NOT EXISTS references zero attributes yet must classify needs-hydration.
        $conditionTree = [
            'type' => 'combine',
            'conditions' => [
                [
                    'type' => RelatedEntityCombine::class,
                    'relation' => 'order.customer_by_email',
                    'value' => '0',
                ],
            ],
        ];

        $classifier = new AttributeClassifier([RelatedEntityCombine::class]);

        $this->assertFalse($classifier->isZeroQuery($conditionTree, ['grand_total']));
        $this->assertEquals([RelatedEntityCombine::class], $classifier->classify($conditionTree, [])['forcing_node_types']);
    }

    public function testDuplicateForcingNodesReportedOnce(): void
    {
        $conditionTree = [
            'type' => 'combine',
            'conditions' => [
                ['type' => 'related_entity', 'relation' => 'a'],
                ['type' => 'related_entity', 'relation' => 'b'],
            ],
        ];

        $classifier = new AttributeClassifier(['related_entity']);
        $result = $classifier->classify($conditionTree, []);

        $this->assertEquals(['related_entity'], $result['forcing_node_types']);
    }
}
