<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\DryRun;

use MageOS\Workflows\Api\RecentEntityProviderInterface;
use MageOS\Workflows\Model\DryRun\RecentEntityProviderPool;
use PHPUnit\Framework\TestCase;

class RecentEntityProviderPoolTest extends TestCase
{
    private function provider(string $type, array $rows): RecentEntityProviderInterface
    {
        return new class ($type, $rows) implements RecentEntityProviderInterface {
            public function __construct(private string $type, private array $rows)
            {
            }

            public function getEntityType(): string
            {
                return $this->type;
            }

            public function getRecent(int $limit): array
            {
                return array_slice($this->rows, 0, $limit);
            }
        };
    }

    public function testResolvesProviderByEntityType(): void
    {
        $pool = new RecentEntityProviderPool([
            $this->provider('sales_order', [['id' => 5, 'label' => '#100']]),
        ]);

        $this->assertTrue($pool->hasProvider('sales_order'));
        $this->assertSame([['id' => 5, 'label' => '#100']], $pool->getRecent('sales_order'));
    }

    public function testUnknownEntityTypeYieldsEmptyList(): void
    {
        $pool = new RecentEntityProviderPool([]);
        $this->assertFalse($pool->hasProvider('customer'));
        $this->assertSame([], $pool->getRecent('customer'));
    }

    public function testLimitIsHonoredAndNonPositiveFallsBackToDefault(): void
    {
        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = ['id' => $i, 'label' => (string) $i];
        }
        $pool = new RecentEntityProviderPool([$this->provider('sales_order', $rows)]);

        $this->assertCount(5, $pool->getRecent('sales_order', 5));
        $this->assertCount(RecentEntityProviderPool::DEFAULT_LIMIT, $pool->getRecent('sales_order', 0));
    }

    public function testRejectsNonProviderEntries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RecentEntityProviderPool([new \stdClass()]);
    }
}
