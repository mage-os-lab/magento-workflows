<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Option;

use MageOS\Workflows\Api\OptionSourceInterface;
use MageOS\Workflows\Model\Option\OptionSourcePool;
use PHPUnit\Framework\TestCase;

/**
 * The DI-registered option-source pool (F6): registration, lookup, guards.
 */
class OptionSourcePoolTest extends TestCase
{
    private function source(string $code): OptionSourceInterface
    {
        return new class ($code) implements OptionSourceInterface {
            public function __construct(private readonly string $code)
            {
            }

            public function getCode(): string
            {
                return $this->code;
            }

            public function fetch(?string $query = null): array
            {
                return [['value' => 'v', 'label' => 'l']];
            }

            public function all(): array
            {
                return [['value' => 'v', 'label' => 'l']];
            }

            public function hasValue(string $value): bool
            {
                return $value === 'v';
            }
        };
    }

    public function testHasGetAndCodes(): void
    {
        $pool = new OptionSourcePool([
            'order_statuses' => $this->source('order_statuses'),
            'customer_groups' => $this->source('customer_groups'),
        ]);

        $this->assertTrue($pool->has('order_statuses'));
        $this->assertFalse($pool->has('nope'));
        $this->assertSame('customer_groups', $pool->get('customer_groups')->getCode());
        $this->assertSame(['order_statuses', 'customer_groups'], $pool->getCodes());
    }

    public function testUnknownSourceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new OptionSourcePool([]))->get('missing');
    }

    public function testRejectsNonOptionSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OptionSourcePool(['bad' => new \stdClass()]);
    }
}
