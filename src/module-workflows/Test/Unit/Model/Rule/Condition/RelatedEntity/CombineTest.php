<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Rule\Condition\RelatedEntity;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Rule\Model\Condition\Context;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Workflows\Api\RelationInterface;
use MageOS\Workflows\Model\Relation\RelationContext;
use MageOS\Workflows\Model\Relation\RelationPool;
use MageOS\Workflows\Model\Rule\Condition\RelatedEntity\Combine;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * EXISTS/NOT-EXISTS × one/many × cap semantics for the generic RelatedEntity
 * combine. All relation resolution runs through the real RelationContext (the
 * combine never calls resolveIds() directly), so these also exercise the
 * context boundary end to end.
 */
class CombineTest extends TestCase
{
    /** @var string[] captured warnings */
    private array $warnings = [];

    public function setUp(): void
    {
        $this->warnings = [];
    }

    private function capturingLogger(): NullLogger
    {
        $test = $this;
        return new class ($test) extends NullLogger {
            public function __construct(private readonly CombineTest $test)
            {
            }

            public function warning(string|\Stringable $message, array $context = []): void
            {
                $this->test->recordWarning((string) $message);
            }
        };
    }

    public function recordWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    /**
     * @param array<int, array<string, mixed>> $entities id => entity data
     */
    private function provider(array $entities): HydrationProviderInterface
    {
        return new class ($entities) implements HydrationProviderInterface {
            /** @param array<int, array<string, mixed>> $entities */
            public function __construct(private readonly array $entities)
            {
            }

            public function getEntity(string $entityType, int $entityId, bool $fresh = false): ?DataObject
            {
                return isset($this->entities[$entityId])
                    ? new DataObject($this->entities[$entityId])
                    : null;
            }
        };
    }

    private function relation(array|\Closure $ids, string $cardinality = RelationInterface::CARDINALITY_MANY): RelationInterface
    {
        return new class ($ids, $cardinality) implements RelationInterface {
            public function __construct(private readonly array|\Closure $ids, private readonly string $cardinality)
            {
            }

            public function getCode(): string
            {
                return 'rel';
            }

            public function getLabel(): string
            {
                return 'Related';
            }

            public function getSourceEntityType(): string
            {
                return HydrationProviderInterface::TYPE_ORDER;
            }

            public function getTargetEntityType(): string
            {
                return HydrationProviderInterface::TYPE_CUSTOMER;
            }

            public function getCardinality(): string
            {
                return $this->cardinality;
            }

            public function resolveIds(DataObject $source, ?int $websiteId): array
            {
                $ids = $this->ids;
                return $ids instanceof \Closure ? $ids() : $ids;
            }
        };
    }

    private function combine(RelationInterface $relation, array $config = [], string $relationCode = 'rel'): Combine
    {
        $pool = new RelationPool(['rel' => $relation]);
        $scopeConfig = new class ($config) implements ScopeConfigInterface {
            public function __construct(private readonly array $config)
            {
            }

            public function getValue($path, $scope = 'default', $scopeCode = null)
            {
                return $this->config[$path] ?? null;
            }

            public function isSetFlag($path, $scope = 'default', $scopeCode = null): bool
            {
                return (bool) ($this->config[$path] ?? false);
            }
        };
        $storeManager = new class implements StoreManagerInterface {
            public function getStore($storeId = null)
            {
                return new DataObject(['website_id' => 1]);
            }

            public function setIsSingleStoreModeAllowed($value)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function hasSingleStore()
            {
                return false;
            }

            public function isSingleStoreMode()
            {
                return false;
            }

            public function getStores($withDefault = false, $codeKey = false)
            {
                return [];
            }

            public function getWebsite($websiteId = null)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function getWebsites($withDefault = false, $codeKey = false)
            {
                return [];
            }

            public function reinitStores()
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function getDefaultStoreView()
            {
                return null;
            }

            public function getGroup($groupId = null)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function getGroups($withDefault = false)
            {
                return [];
            }

            public function setCurrentStore($store)
            {
                throw new \BadMethodCallException(__METHOD__);
            }
        };
        $logger = $this->capturingLogger();
        $relationContext = new RelationContext($pool, $storeManager, $scopeConfig, $logger);

        $combine = new Combine(new class extends Context {
            public function __construct()
            {
            }
        }, $relationContext, $pool, $logger, [], []);
        if ($relationCode !== '') {
            $combine->setData('relation', $relationCode);
        }
        return $combine;
    }

    /**
     * Recording leaf condition: validates the entity attribute against a target
     * and counts how many entities it was asked about.
     */
    private function child(string $attribute, mixed $wanted): object
    {
        return new class ($attribute, $wanted) {
            public int $calls = 0;

            public function __construct(private readonly string $attribute, private readonly mixed $wanted)
            {
            }

            public function validate(DataObject $model): bool
            {
                $this->calls++;
                return $model->getData($this->attribute) === $this->wanted;
            }
        };
    }

    private function orderModel(?HydrationProviderInterface $provider = null): DataObject
    {
        return new DataObject([
            'entity_id' => 42,
            HydrationProviderInterface::KEY_ENTITY_TYPE => HydrationProviderInterface::TYPE_ORDER,
            HydrationProviderInterface::KEY_ENTITY_ID => 42,
            HydrationProviderInterface::KEY_PROVIDER => $provider,
        ]);
    }

    public function testBareExistsMatchesWhenResolved(): void
    {
        $combine = $this->combine($this->relation([5], RelationInterface::CARDINALITY_ONE));
        $combine->setValue(Combine::EXISTS);

        $this->assertTrue($combine->validate($this->orderModel()));
    }

    public function testBareExistsFalseWhenNoneResolved(): void
    {
        $combine = $this->combine($this->relation([], RelationInterface::CARDINALITY_ONE));
        $combine->setValue(Combine::EXISTS);

        $this->assertFalse($combine->validate($this->orderModel()));
    }

    public function testBareNotExistsTrueWhenNoneResolved(): void
    {
        // The flagship guest check.
        $combine = $this->combine($this->relation([], RelationInterface::CARDINALITY_ONE));
        $combine->setValue(Combine::NOT_EXISTS);

        $this->assertTrue($combine->validate($this->orderModel()));
    }

    public function testBareNotExistsFalseWhenResolved(): void
    {
        $combine = $this->combine($this->relation([5], RelationInterface::CARDINALITY_ONE));
        $combine->setValue(Combine::NOT_EXISTS);

        $this->assertFalse($combine->validate($this->orderModel()));
    }

    public function testResolverExceptionFailsTowardFalse(): void
    {
        $throwing = $this->relation(static function (): array {
            throw new \RuntimeException('boom');
        }, RelationInterface::CARDINALITY_ONE);

        $exists = $this->combine($throwing);
        $exists->setValue(Combine::EXISTS);
        $this->assertFalse($exists->validate($this->orderModel()));

        $notExists = $this->combine($throwing);
        $notExists->setValue(Combine::NOT_EXISTS);
        $this->assertTrue($notExists->validate($this->orderModel()));
    }

    public function testChildrenNotEvaluatedWhenNothingResolved(): void
    {
        $combine = $this->combine($this->relation([]));
        $combine->setValue(Combine::EXISTS);
        $combine->setData('match_mode', Combine::MATCH_ANY);
        $child = $this->child('orders_count', 3);
        $combine->setConditions([$child]);

        $this->assertFalse($combine->validate($this->orderModel($this->provider([]))));
        $this->assertSame(0, $child->calls, 'children must not run when no entity resolves');
    }

    public function testExistsWithQualifyingChildAnyMatches(): void
    {
        $combine = $this->combine($this->relation([7, 8]));
        $combine->setValue(Combine::EXISTS);
        $combine->setData('match_mode', Combine::MATCH_ANY);
        $combine->setConditions([$this->child('orders_count', 3)]);

        $provider = $this->provider([7 => ['orders_count' => 1], 8 => ['orders_count' => 3]]);
        $this->assertTrue($combine->validate($this->orderModel($provider)));
    }

    public function testAllModeRequiresEveryEntityToMatch(): void
    {
        $provider = $this->provider([7 => ['orders_count' => 3], 8 => ['orders_count' => 1]]);

        $combine = $this->combine($this->relation([7, 8]));
        $combine->setValue(Combine::EXISTS);
        $combine->setData('match_mode', Combine::MATCH_ALL);
        $combine->setConditions([$this->child('orders_count', 3)]);

        $this->assertFalse($combine->validate($this->orderModel($provider)));
    }

    public function testNoneModeTrueWhenNoEntityMatches(): void
    {
        $provider = $this->provider([7 => ['orders_count' => 1], 8 => ['orders_count' => 2]]);

        $combine = $this->combine($this->relation([7, 8]));
        $combine->setValue(Combine::EXISTS);
        $combine->setData('match_mode', Combine::MATCH_NONE);
        $combine->setConditions([$this->child('orders_count', 3)]);

        $this->assertTrue($combine->validate($this->orderModel($provider)));
    }

    public function testAnyModeOverCapEvaluatesFirstN(): void
    {
        // 150 ids -> RelationContext caps to 100; a match among the first N is a
        // match, so ANY resolves true and does not warn about truncation.
        $provider = $this->provider(array_fill_keys(range(1, 150), ['orders_count' => 3]));
        $combine = $this->combine($this->relation(range(1, 150)));
        $combine->setValue(Combine::EXISTS);
        $combine->setData('match_mode', Combine::MATCH_ANY);
        $combine->setConditions([$this->child('orders_count', 3)]);

        $this->assertTrue($combine->validate($this->orderModel($provider)));
        // The context warns that it truncated to the cap, but ANY does NOT add
        // an "indeterminable" warning — a match in the first N is a real match.
        $this->assertCount(0, $this->indeterminableWarnings());
    }

    public function testAllModeOverCapFailsTowardFalseWithWarning(): void
    {
        // ALL over a truncated set is unknowable -> false + warning, even though
        // every evaluated entity would match.
        $provider = $this->provider(array_fill_keys(range(1, 150), ['orders_count' => 3]));
        $combine = $this->combine($this->relation(range(1, 150)));
        $combine->setValue(Combine::EXISTS);
        $combine->setData('match_mode', Combine::MATCH_ALL);
        $combine->setConditions([$this->child('orders_count', 3)]);

        $this->assertFalse($combine->validate($this->orderModel($provider)));
        $this->assertCount(1, $this->indeterminableWarnings());
    }

    /**
     * @return string[] warnings the combine raised about an unknowable ALL match
     */
    private function indeterminableWarnings(): array
    {
        return array_values(array_filter(
            $this->warnings,
            static fn (string $message): bool => str_contains($message, 'indeterminable')
        ));
    }

    public function testMissingRelationCodeFailsTowardFalse(): void
    {
        $combine = $this->combine($this->relation([5]), [], '');
        $combine->setValue(Combine::EXISTS);
        // no relation code set

        $this->assertFalse($combine->validate($this->orderModel()));
    }
}
