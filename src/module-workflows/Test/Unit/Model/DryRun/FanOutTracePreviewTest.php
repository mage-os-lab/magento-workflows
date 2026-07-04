<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\DryRun;

use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use MageOS\Workflows\Api\RelationInterface;
use MageOS\Workflows\Model\DryRun\FanOutTracePreview;
use MageOS\Workflows\Model\Relation\RelationContext;
use MageOS\Workflows\Model\Relation\RelationPool;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;
use MageOS\Workflows\Test\Unit\Stub\StubHydrationProvider;
use MageOS\Workflows\Test\Unit\Stub\StubScopeConfig;
use MageOS\Workflows\Test\Unit\Stub\StubStoreManager;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * Dry-run fan-out preview: the "would dispatch N (first 3: …)" node built from
 * a live relation resolution, and the graceful no-op / note paths.
 */
class FanOutTracePreviewTest extends TestCase
{
    /**
     * @param int[] $ids
     */
    private function relation(array $ids): RelationInterface
    {
        return new class ($ids) implements RelationInterface {
            /** @param int[] $ids */
            public function __construct(private readonly array $ids)
            {
            }

            public function getCode(): string
            {
                return 'customer.open_orders';
            }

            public function getLabel(): string
            {
                return "the customer's open orders";
            }

            public function getSourceEntityType(): string
            {
                return HydrationProviderInterface::TYPE_CUSTOMER;
            }

            public function getTargetEntityType(): string
            {
                return HydrationProviderInterface::TYPE_ORDER;
            }

            public function getCardinality(): string
            {
                return self::CARDINALITY_MANY;
            }

            public function resolveIds(DataObject $source, ?int $websiteId): array
            {
                return $this->ids;
            }
        };
    }

    private function preview(RelationInterface $relation, array $config = []): FanOutTracePreview
    {
        $pool = new RelationPool(['customer.open_orders' => $relation]);
        $source = new DataObject(['entity_id' => 7]);
        $entities = [HydrationProviderInterface::TYPE_CUSTOMER . ':7' => $source];

        return new FanOutTracePreview(
            $pool,
            new RelationContext($pool, new StubStoreManager(), new StubScopeConfig($config), new NullLogger()),
            new StubHydrationProvider($entities),
            new DataObjectFactory(),
            new StubScopeConfig($config)
        );
    }

    private function fanOut(string $relation = 'customer.open_orders', ?int $cap = null): string
    {
        $config = ['relation' => $relation];
        if ($cap !== null) {
            $config['cap'] = $cap;
        }
        return (string) json_encode($config);
    }

    public function testNullWhenNoFanOut(): void
    {
        $this->assertNull($this->preview($this->relation([]))->build(null, 7));
    }

    public function testReportsWouldDispatchAndSample(): void
    {
        $node = $this->preview($this->relation([101, 102, 103, 104]))->build($this->fanOut(), 7);

        $this->assertSame('customer.open_orders', $node['relation']);
        $this->assertSame(HydrationProviderInterface::TYPE_CUSTOMER, $node['source_type']);
        $this->assertSame(HydrationProviderInterface::TYPE_ORDER, $node['target_type']);
        $this->assertSame(4, $node['would_dispatch']);
        $this->assertSame([101, 102, 103], $node['sample_target_ids']);
        $this->assertFalse($node['truncated']);
    }

    public function testTruncatesToCap(): void
    {
        $node = $this->preview($this->relation(range(1, 40)))
            ->build($this->fanOut('customer.open_orders', 5), 7);

        $this->assertSame(5, $node['would_dispatch']);
        $this->assertTrue($node['truncated']);
        $this->assertSame([1, 2, 3], $node['sample_target_ids']);
    }

    public function testUnknownRelationNote(): void
    {
        $node = $this->preview($this->relation([1]))->build($this->fanOut('no.such'), 7);

        $this->assertSame('no.such', $node['relation']);
        $this->assertSame(0, $node['would_dispatch']);
        $this->assertSame('unknown_relation', $node['note']);
    }

    public function testSourceNotFoundNote(): void
    {
        $node = $this->preview($this->relation([1]))->build($this->fanOut(), 999);

        $this->assertSame(0, $node['would_dispatch']);
        $this->assertSame('source_not_found', $node['note']);
    }
}
