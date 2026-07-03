<?php
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
        $this->condition = new TriggerData(new Context());
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
        $method->setAccessible(true);
        return $method->invoke($this->condition, $model, $path);
    }

}
