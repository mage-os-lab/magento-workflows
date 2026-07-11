<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Rule;

use MageOS\Workflows\Model\Rule\ConditionLeafPool;
use PHPUnit\Framework\TestCase;

/**
 * The leaf pool is the cross-pack seam that lets one domain pack offer
 * child conditions against another pack's entity without compile-time
 * coupling — so the contract under test is exactly its degradation
 * behavior: unregistered entity types resolve to null (the consumer then
 * offers no such children), never to an error.
 */
final class ConditionLeafPoolTest extends TestCase
{
    public function testUnregisteredEntityTypeResolvesToNull(): void
    {
        $pool = new ConditionLeafPool(new FakeLeafObjectManager([]), []);
        self::assertNull($pool->getLeafClass('catalog_product'));
        self::assertNull($pool->createLeaf('catalog_product'));
    }

    public function testRegisteredLeafClassIsExposedWithoutInstantiation(): void
    {
        $om = new FakeLeafObjectManager([]);
        $pool = new ConditionLeafPool($om, ['catalog_product' => 'Some\\Leaf\\ClassName']);
        self::assertSame('Some\\Leaf\\ClassName', $pool->getLeafClass('catalog_product'));
        self::assertSame(0, $om->created, 'getLeafClass must not instantiate');
    }

    public function testCreateLeafRejectsNonConditionClasses(): void
    {
        $om = new FakeLeafObjectManager(['Bad\\Class' => new \stdClass()]);
        $pool = new ConditionLeafPool($om, ['catalog_product' => 'Bad\\Class']);
        $this->expectException(\InvalidArgumentException::class);
        $pool->createLeaf('catalog_product');
    }
}

/**
 * Minimal ObjectManagerInterface stand-in: returns canned instances and
 * counts creations.
 */
final class FakeLeafObjectManager implements \Magento\Framework\ObjectManagerInterface
{
    public int $created = 0;

    /** @param array<string, object> $instances */
    public function __construct(private readonly array $instances)
    {
    }

    public function create($type, array $arguments = [])
    {
        $this->created++;
        return $this->instances[$type] ?? new \stdClass();
    }

    public function get($type)
    {
        return $this->instances[$type] ?? new \stdClass();
    }

    public function configure(array $configuration)
    {
    }
}
