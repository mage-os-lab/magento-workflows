<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Option;

use MageOS\Workflows\Model\Option\EntityOptionSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The `entity:*` alias => option-source mapping: lookup, the bounded/min_chars
 * contract (bounded forces 0, unbounded defaults to 2), and the registration
 * guard.
 */
class EntityOptionSourceRegistryTest extends TestCase
{
    private function registry(): EntityOptionSourceRegistry
    {
        return new EntityOptionSourceRegistry([
            'salesrule' => ['source' => 'cart_price_rules'],
            'order_status' => ['source' => 'order_statuses', 'bounded' => true],
            'sku' => ['source' => 'products', 'min_chars' => 3],
        ]);
    }

    public function testHasAndSourceCode(): void
    {
        $registry = $this->registry();

        $this->assertTrue($registry->has('salesrule'));
        $this->assertFalse($registry->has('nope'));
        // The alias is passed WITHOUT the "entity:" prefix.
        $this->assertFalse($registry->has('entity:salesrule'));
        $this->assertSame('cart_price_rules', $registry->sourceCode('salesrule'));
        $this->assertSame('order_statuses', $registry->sourceCode('order_status'));
    }

    public function testUnknownAliasThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry()->sourceCode('missing');
    }

    public function testBoundedFlagAndForcedMinChars(): void
    {
        $registry = $this->registry();

        $this->assertTrue($registry->isBounded('order_status'));
        $this->assertFalse($registry->isBounded('salesrule'));
        // Bounded lists render as a plain select: the whole list resolves on an
        // empty query, so min_chars is 0 regardless of any declared value.
        $this->assertSame(0, $registry->minChars('order_status'));
    }

    public function testMinCharsDefaultsToTwoAndHonoursOverride(): void
    {
        $registry = $this->registry();

        $this->assertSame(2, $registry->minChars('salesrule'));
        $this->assertSame(3, $registry->minChars('sku'));
    }

    public function testEntryWithoutSourceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EntityOptionSourceRegistry(['salesrule' => ['bounded' => true]]);
    }

    public function testEntryWithEmptySourceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EntityOptionSourceRegistry(['salesrule' => ['source' => '']]);
    }
}
