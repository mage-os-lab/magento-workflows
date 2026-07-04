<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Relation;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Workflows\Api\RelationInterface;
use MageOS\Workflows\Model\Relation\RelationContext;
use MageOS\Workflows\Model\Relation\RelationPool;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

class RelationContextTest extends TestCase
{
    /** @var int how many times the stub resolver ran */
    private int $resolveCalls = 0;

    /** @var ?int website id the stub resolver received */
    private ?int $seenWebsiteId = null;

    private function relation(array|\Closure $ids, string $source = 'sales_order'): RelationInterface
    {
        $test = $this;
        return new class ($ids, $source, $test) implements RelationInterface {
            public function __construct(
                private readonly array|\Closure $ids,
                private readonly string $source,
                private readonly RelationContextTest $test
            ) {
            }

            public function getCode(): string
            {
                return 'test_relation';
            }

            public function getLabel(): string
            {
                return 'Test relation';
            }

            public function getSourceEntityType(): string
            {
                return $this->source;
            }

            public function getTargetEntityType(): string
            {
                return 'customer';
            }

            public function getCardinality(): string
            {
                return self::CARDINALITY_MANY;
            }

            public function resolveIds(DataObject $source, ?int $websiteId): array
            {
                $this->test->recordResolve($websiteId);
                $ids = $this->ids;
                return $ids instanceof \Closure ? $ids($source, $websiteId) : $ids;
            }
        };
    }

    public function recordResolve(?int $websiteId): void
    {
        $this->resolveCalls++;
        $this->seenWebsiteId = $websiteId;
    }

    private function context(
        ?RelationInterface $relation = null,
        array $config = [],
        int $storeWebsiteId = 7
    ): RelationContext {
        $scopeConfig = new class ($config) implements ScopeConfigInterface {
            public function __construct(private readonly array $config)
            {
            }

            public function getValue(string $path, string $scope = 'default', ?string $scopeCode = null)
            {
                return $this->config[$path] ?? null;
            }

            public function isSetFlag(string $path, string $scope = 'default', ?string $scopeCode = null): bool
            {
                return (bool) ($this->config[$path] ?? false);
            }
        };
        $storeManager = new class ($storeWebsiteId) implements StoreManagerInterface {
            public function __construct(private readonly int $websiteId)
            {
            }

            public function getStore($storeId = null)
            {
                return new DataObject(['website_id' => $this->websiteId]);
            }
        };

        return new RelationContext(
            new RelationPool($relation !== null ? ['test_relation' => $relation] : []),
            $storeManager,
            $scopeConfig,
            new NullLogger()
        );
    }

    public function setUp(): void
    {
        $this->resolveCalls = 0;
        $this->seenWebsiteId = null;
    }

    public function testResolveReturnsNormalizedUniqueIds(): void
    {
        $context = $this->context($this->relation(['5', 5, 9, '9', 12]));

        $ids = $context->resolve('test_relation', new DataObject(['entity_id' => 3]));

        $this->assertSame([5, 9, 12], $ids);
    }

    public function testUnknownRelationFailsTowardFalse(): void
    {
        $context = $this->context();

        $this->assertSame([], $context->resolve('nope', new DataObject(['entity_id' => 3])));
    }

    public function testResolverExceptionFailsTowardFalse(): void
    {
        $context = $this->context($this->relation(static function (): array {
            throw new \RuntimeException('resolver exploded');
        }));

        $this->assertSame([], $context->resolve('test_relation', new DataObject(['entity_id' => 3])));
    }

    public function testMemoizesOnRelationAndEntityId(): void
    {
        $context = $this->context($this->relation([1, 2]));
        $source = new DataObject(['entity_id' => 3]);

        $context->resolve('test_relation', $source);
        $context->resolve('test_relation', $source);

        $this->assertSame(1, $this->resolveCalls);
    }

    public function testSkipsMemoWhenEntityIdNotPositive(): void
    {
        $context = $this->context($this->relation([1]));
        $source = new DataObject(['entity_id' => 0]);

        $context->resolve('test_relation', $source);
        $context->resolve('test_relation', $source);

        $this->assertSame(2, $this->resolveCalls);
    }

    public function testFreshFlagBypassesMemo(): void
    {
        $context = $this->context($this->relation([1]));
        $source = new DataObject(['entity_id' => 3]);

        $context->resolve('test_relation', $source);
        $context->setFresh(true);
        $this->assertTrue($context->isFresh());
        $context->resolve('test_relation', $source);

        $this->assertSame(2, $this->resolveCalls);
    }

    public function testResetClearsMemoAndFreshFlag(): void
    {
        $context = $this->context($this->relation([1]));
        $source = new DataObject(['entity_id' => 3]);

        $context->resolve('test_relation', $source);
        $context->setFresh(true);
        $context->reset();

        $this->assertFalse($context->isFresh());
        $context->resolve('test_relation', $source);
        $this->assertSame(2, $this->resolveCalls);
    }

    public function testCapAppliedFromConfigWithDefault(): void
    {
        $context = $this->context($this->relation(range(1, 250)));

        $ids = $context->resolve('test_relation', new DataObject(['entity_id' => 3]));

        $this->assertCount(RelationContext::DEFAULT_RELATION_CAP, $ids);
    }

    public function testConfiguredCapWins(): void
    {
        $context = $this->context(
            $this->relation(range(1, 50)),
            [RelationContext::CONFIG_RELATION_CAP => 10]
        );

        $ids = $context->resolve('test_relation', new DataObject(['entity_id' => 3]));

        $this->assertSame(range(1, 10), $ids);
    }

    public function testWebsiteIdFromExplicitColumn(): void
    {
        $context = $this->context($this->relation([1]));

        $context->resolve('test_relation', new DataObject(['entity_id' => 3, 'website_id' => '4']));

        $this->assertSame(4, $this->seenWebsiteId);
    }

    public function testWebsiteIdDerivedFromStoreId(): void
    {
        $context = $this->context($this->relation([1]), [], 7);

        $context->resolve('test_relation', new DataObject(['entity_id' => 3, 'store_id' => 2]));

        $this->assertSame(7, $this->seenWebsiteId);
    }

    public function testWebsiteIdNullWhenIndeterminable(): void
    {
        $context = $this->context($this->relation([1]));

        $context->resolve('test_relation', new DataObject(['entity_id' => 3]));

        $this->assertNull($this->seenWebsiteId);
    }

    public function testCustomerUnderGlobalAccountSharingIsUnscoped(): void
    {
        $context = $this->context(
            $this->relation([1], 'customer'),
            ['customer/account_share/scope' => 0]
        );
        $source = new DataObject([
            'entity_id' => 3,
            'website_id' => 4,
            HydrationProviderInterface::KEY_ENTITY_TYPE => HydrationProviderInterface::TYPE_CUSTOMER,
        ]);

        $context->resolve('test_relation', $source);

        $this->assertNull($this->seenWebsiteId);
    }

    public function testCustomerUnderPerWebsiteSharingStaysScoped(): void
    {
        $context = $this->context(
            $this->relation([1], 'customer'),
            ['customer/account_share/scope' => 1]
        );
        $source = new DataObject([
            'entity_id' => 3,
            'website_id' => 4,
            HydrationProviderInterface::KEY_ENTITY_TYPE => HydrationProviderInterface::TYPE_CUSTOMER,
        ]);

        $context->resolve('test_relation', $source);

        $this->assertSame(4, $this->seenWebsiteId);
    }

    public function testPoolRejectsNonRelationEntries(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RelationPool(['bad' => new \stdClass()]);
    }
}
