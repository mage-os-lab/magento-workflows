<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Rule\Condition;

use Magento\Framework\DataObject;
use Magento\Rule\Model\Condition\Context;
use MageOS\Workflows\Model\Rule\Condition\TriggerData;
use MageOS\Workflows\Model\Rule\Condition\AbstractWorkflowCondition;
use PHPUnit\Framework\TestCase;

class TriggerDataTest extends TestCase
{
    private TriggerData $condition;

    public function setUp(): void
    {
        // Real Magento's Rule Context needs 5 constructor deps; the condition
        // under test never uses them, so pass a bare Context (empty constructor).
        $this->condition = new TriggerData(new class extends Context {
            public function __construct()
            {
            }
        });
    }

    /**
     * Test resolvePath private method via reflection: simple root-level key
     */
    public function testResolvePathSimpleRoot(): void
    {
        $model = new DataObject(['from_status' => 'pending']);
        $result = $this->invokeResolvePath($model, 'from_status');
        $this->assertSame('pending', $result);
    }

    /**
     * Test resolvePath with nested array traversal using dot notation
     */
    public function testResolvePathNestedArray(): void
    {
        $model = new DataObject([
            'payment' => [
                'method' => 'credit_card',
                'cc_type' => 'visa'
            ]
        ]);
        $result = $this->invokeResolvePath($model, 'payment.method');
        $this->assertSame('credit_card', $result);
    }

    /**
     * Test resolvePath with numeric segment (list array access)
     */
    public function testResolvePathNumericSegment(): void
    {
        $model = new DataObject([
            'items' => [
                ['sku' => 'SKU001', 'qty' => 1],
                ['sku' => 'SKU002', 'qty' => 2],
            ]
        ]);
        $result = $this->invokeResolvePath($model, 'items.0.sku');
        $this->assertSame('SKU001', $result);
    }

    /**
     * Test resolvePath with second item in array
     */
    public function testResolvePathNumericSegmentSecondItem(): void
    {
        $model = new DataObject([
            'items' => [
                ['sku' => 'SKU001'],
                ['sku' => 'SKU002'],
            ]
        ]);
        $result = $this->invokeResolvePath($model, 'items.1.sku');
        $this->assertSame('SKU002', $result);
    }

    /**
     * Test resolvePath returns null for missing root segment
     */
    public function testResolvePathMissingRoot(): void
    {
        $model = new DataObject(['from_status' => 'pending']);
        $result = $this->invokeResolvePath($model, 'to_status');
        $this->assertNull($result);
    }

    /**
     * Test resolvePath returns null for missing nested segment
     */
    public function testResolvePathMissingNestedSegment(): void
    {
        $model = new DataObject([
            'payment' => ['method' => 'credit_card']
        ]);
        $result = $this->invokeResolvePath($model, 'payment.invalid_key');
        $this->assertNull($result);
    }

    /**
     * Test resolvePath with deeply nested path
     */
    public function testResolvePathDeeplyNested(): void
    {
        $model = new DataObject([
            'a' => [
                'b' => [
                    'c' => [
                        'd' => 'value'
                    ]
                ]
            ]
        ]);
        $result = $this->invokeResolvePath($model, 'a.b.c.d');
        $this->assertSame('value', $result);
    }

    /**
     * Test resolvePath returns null when intermediate segment is not an array
     */
    public function testResolvePathNonArrayIntermediate(): void
    {
        $model = new DataObject([
            'payment' => 'credit_card'
        ]);
        $result = $this->invokeResolvePath($model, 'payment.method');
        $this->assertNull($result);
    }

    /**
     * Test resolvePath with empty root key (empty path after explode)
     * Empty path becomes an empty key lookup which returns full data from DataObject
     */
    public function testResolvePathEmpty(): void
    {
        $model = new DataObject(['key' => 'value']);
        $result = $this->invokeResolvePath($model, '');
        // When path is empty, root becomes '', and getData('') returns the whole _data array
        $this->assertTrue(is_array($result));
    }

    /**
     * Test validate returns false for empty path
     */
    public function testValidateEmptyPath(): void
    {
        $this->condition->setAttribute('');
        $model = new DataObject(['key' => 'value']);
        $result = $this->condition->validate($model);
        $this->assertFalse($result);
    }

    /**
     * Test validate with whitespace-only path
     */
    public function testValidateWhitespacePath(): void
    {
        $this->condition->setAttribute('   ');
        $model = new DataObject(['key' => 'value']);
        $result = $this->condition->validate($model);
        $this->assertFalse($result);
    }

    /**
     * The fan-out origin lands in the trigger snapshot at the payload root, so
     * a Trigger Data leaf resolves the dot-path `origin.event` (NOT
     * `trigger.origin.event`) — the same root the dispatcher writes the origin
     * into via context.trigger (F1, discovery/fan-out.md §2 point 4).
     */
    public function testResolvePathOriginEvent(): void
    {
        $model = new DataObject([
            'entity_id' => 55,
            'origin' => [
                'event' => 'customer.group_changed',
                'entity_type' => 'customer',
                'entity_id' => 7,
                'via' => 'fan_out',
            ],
        ]);
        $this->assertSame('customer.group_changed', $this->invokeResolvePath($model, 'origin.event'));
        $this->assertSame('fan_out', $this->invokeResolvePath($model, 'origin.via'));
    }

    /**
     * End-to-end: a child execution can gate on why it exists via
     * `origin.event == customer.group_changed`.
     */
    public function testOriginLeafMatchesEventEndToEnd(): void
    {
        $this->condition->setAttribute('origin.event');
        $this->condition->setOperator('==');
        $this->condition->setValue('customer.group_changed');

        $model = new DataObject(['origin' => ['event' => 'customer.group_changed']]);
        $this->assertTrue($this->condition->validate($model));
    }

    /**
     * Pins the snapshot-only caveat: after a revalidate_entity branch the model
     * being validated is the freshly hydrated entity, which carries NO origin —
     * so origin-based gating only works at the root / pre-delay. A hydrated
     * order model lacks `origin`, and the leaf fails toward false.
     */
    public function testHydratedModelLacksOriginPostRevalidate(): void
    {
        // What a re-hydrated order snapshot looks like: entity data, no origin.
        $hydrated = new DataObject(['entity_id' => 55, 'state' => 'processing', 'increment_id' => '100000055']);
        $this->assertNull($this->invokeResolvePath($hydrated, 'origin.event'));

        $this->condition->setAttribute('origin.event');
        $this->condition->setOperator('==');
        $this->condition->setValue('customer.group_changed');
        $this->assertFalse($this->condition->validate($hydrated));
    }

    /**
     * Test loadAttributeOptions returns self
     */
    public function testLoadAttributeOptions(): void
    {
        $result = $this->condition->loadAttributeOptions();
        $this->assertSame($this->condition, $result);
    }

    /**
     * Test getAttributeName returns the dot-path as-is
     */
    public function testGetAttributeName(): void
    {
        $this->condition->setAttribute('payment.method');
        $name = $this->condition->getAttributeName();
        $this->assertSame('payment.method', $name);
    }

    /**
     * Test getInputType returns 'string'
     */
    public function testGetInputType(): void
    {
        $this->assertSame('string', $this->condition->getInputType());
    }

    /**
     * Test getValueElementType returns 'text'
     */
    public function testGetValueElementType(): void
    {
        $this->assertSame('text', $this->condition->getValueElementType());
    }

    /**
     * Invoke the private resolvePath method via reflection
     */
    private function invokeResolvePath(DataObject $model, string $path): mixed
    {
        $reflection = new \ReflectionClass(TriggerData::class);
        $method = $reflection->getMethod('resolvePath');
        return $method->invoke($this->condition, $model, $path);
    }

}
