<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Rule;

use Magento\Framework\DataObject;
use Magento\Framework\ObjectManagerInterface;
use Magento\Rule\Model\Condition\AbstractCondition;
use Magento\Rule\Model\Condition\Combine;
use MageOS\Workflows\Api\RelationInterface;
use MageOS\Workflows\Model\Relation\RelationPool;
use MageOS\Workflows\Model\Rule\Condition\RelatedEntity\Combine as RelatedEntityCombine;
use MageOS\Workflows\Model\Rule\Condition\TriggerData;
use MageOS\Workflows\Model\Rule\ConditionCombinePool;
use MageOS\Workflows\Model\Rule\ConditionMetaProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The condition-metadata projection behind the condition builder. What is under
 * test is that the provider asks the CONDITION CLASSES and reports their
 * answers — never its own opinion — and that a request-supplied node type can
 * only ever be a class the entity root itself offers.
 *
 * The real root combines depend on heavyweight EAV/config collaborators that are
 * out of reach of the standalone runner, so the tree here is built from local
 * fixture conditions that reproduce every node shape reachable from the shipped
 * roots (see sales Order\Combine): ungrouped subtree options, the
 * `FQCN|attribute` optgroup composites, the ItemsFound/product-leaf hop, the
 * cross-entity cycle, the generic TriggerData leaf and the RelatedEntity
 * combine (the real class, since it is the one whose relation/match-mode
 * metadata is being projected).
 */
class ConditionMetaProviderTest extends TestCase
{
    private const ENTITY_TYPE = 'sales_order';

    private MetaFixtureLogger $logger;

    public function setUp(): void
    {
        $this->logger = new MetaFixtureLogger();
    }

    private function provider(): ConditionMetaProvider
    {
        $objectManager = new MetaFixtureObjectManager([
            MetaFixtureRootCombine::class => static fn (): object => new MetaFixtureRootCombine(),
            MetaFixtureItemsCombine::class => static fn (): object => new MetaFixtureItemsCombine(),
            MetaFixtureLeaf::class => static fn (): object => new MetaFixtureLeaf(),
            MetaFixtureProductLeaf::class => static fn (): object => new MetaFixtureProductLeaf(),
            MetaFixtureCustomerLeaf::class => static fn (): object => new MetaFixtureCustomerLeaf(),
            MetaFixtureUnreachableLeaf::class => static fn (): object => new MetaFixtureUnreachableLeaf(),
            // Reflection-constructed for the same reason the fixtures above
            // bypass their constructors (TriggerData's takes a live rule
            // Context), then given the two constructor effects the meta walk
            // reads: its own type, and the option data the real
            // getOperatorSelectOptions() iterates.
            TriggerData::class => static function (): object {
                $node = (new \ReflectionClass(TriggerData::class))->newInstanceWithoutConstructor();
                $node->setType(TriggerData::class);
                $node->loadAttributeOptions()->loadOperatorOptions()->loadValueOptions();
                return $node;
            },
            RelatedEntityCombine::class => fn (): object => $this->relatedEntityCombine(),
            // A class whose DI wiring is broken: the walk must survive it.
            MetaFixtureBrokenCombine::class => static function (): object {
                throw new \RuntimeException('missing dependency');
            },
        ]);

        return new ConditionMetaProvider(
            $objectManager,
            new ConditionCombinePool($objectManager, [self::ENTITY_TYPE => MetaFixtureRootCombine::class]),
            new RelationPool([
                'order.customer_by_email' => new MetaFixtureRelation(
                    'order.customer_by_email',
                    'a customer account matching the order email',
                    self::ENTITY_TYPE,
                    'customer',
                    RelationInterface::CARDINALITY_ONE
                ),
                'order.orders_by_email' => new MetaFixtureRelation(
                    'order.orders_by_email',
                    'the customer\'s other orders',
                    self::ENTITY_TYPE,
                    self::ENTITY_TYPE,
                    RelationInterface::CARDINALITY_MANY
                ),
                // Sourced from another entity: must not leak into the order tree.
                'customer.open_orders' => new MetaFixtureRelation(
                    'customer.open_orders',
                    'the customer\'s open orders',
                    'customer',
                    self::ENTITY_TYPE,
                    RelationInterface::CARDINALITY_MANY
                ),
            ]),
            $this->logger
        );
    }

    /**
     * The real relation combine, minus the resolution collaborators the
     * metadata path never touches (peer convention: the pack condition tests
     * build their condition without its constructor).
     */
    private function relatedEntityCombine(): RelatedEntityCombine
    {
        $reflection = new \ReflectionClass(RelatedEntityCombine::class);
        /** @var RelatedEntityCombine $combine */
        $combine = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('relationPool')->setValue($combine, new RelationPool([]));
        $reflection->getProperty('targetChildConditions')
            ->setValue($combine, ['customer' => new MetaFixtureCustomerLeaf()]);
        return $combine;
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(string $nodeType): array
    {
        return $this->provider()->getMetaForNode(self::ENTITY_TYPE, $nodeType);
    }

    // --- root resolution -----------------------------------------------------

    public function testRootTypeComesFromTheCombinePool(): void
    {
        $this->assertSame(MetaFixtureRootCombine::class, $this->provider()->getRootType(self::ENTITY_TYPE));
    }

    public function testUnregisteredEntityTypeHasNoRoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('catalog_product');
        $this->provider()->getRootType('catalog_product');
    }

    public function testRootNodeIsResolvableWithoutNamingItsClass(): void
    {
        $provider = $this->provider();

        $meta = $provider->getMetaForNode(self::ENTITY_TYPE, $provider->getRootType(self::ENTITY_TYPE));

        $this->assertSame(MetaFixtureRootCombine::class, $meta['type']);
        $this->assertSame(ConditionMetaProvider::KIND_COMBINE, $meta['kind']);
        $this->assertSame('Conditions', $meta['label']);
    }

    // --- child options -------------------------------------------------------

    public function testChildOptionsAreGroupedAndKeepTheNativeCompositeValues(): void
    {
        $groups = $this->meta(MetaFixtureRootCombine::class)['new_children'];

        $byLabel = [];
        foreach ($groups as $group) {
            $byLabel[$group['label']] = array_column($group['options'], 'value');
        }

        // Ungrouped subtree options are collected into one leading group...
        $this->assertSame('Condition Types', $groups[0]['label']);
        $this->assertTrue(in_array(MetaFixtureItemsCombine::class, $byLabel['Condition Types'], true));
        $this->assertTrue(in_array(TriggerData::class, $byLabel['Condition Types'], true));
        // ...and the attribute optgroup keeps the FQCN|attribute composites verbatim.
        $this->assertSame(
            [MetaFixtureLeaf::class . '|status', MetaFixtureLeaf::class . '|grand_total'],
            $byLabel['Order Attribute']
        );
    }

    public function testThePlaceholderAndEmptyGroupsAreDropped(): void
    {
        $groups = $this->meta(MetaFixtureRootCombine::class)['new_children'];

        foreach ($groups as $group) {
            // The core "please choose a condition to add" entry has an empty
            // value and is not a selectable node type.
            $this->assertFalse(in_array('', array_column($group['options'], 'value'), true));
            $this->assertTrue($group['options'] !== [], 'An option-less group would render an empty optgroup');
        }
        // The absent domain pack's attribute group ("Product Attribute" with an
        // empty option list) never reaches the client.
        $this->assertFalse(in_array('Product Attribute', array_column($groups, 'label'), true));
    }

    public function testLeafNodesHaveNoChildOptionsOrAggregators(): void
    {
        $meta = $this->meta(MetaFixtureLeaf::class);

        $this->assertSame([], $meta['new_children']);
        $this->assertSame([], $meta['aggregators']);
        $this->assertSame([], $meta['value_options']);
    }

    // --- per-attribute metadata ---------------------------------------------

    public function testAttributeMetadataIsAskedOfTheLeafPerAttribute(): void
    {
        $attributes = $this->meta(MetaFixtureLeaf::class)['attributes'];

        $this->assertArrayHasKey('status', $attributes);
        $this->assertSame('Order Status', $attributes['status']['label']);
        $this->assertSame('select', $attributes['status']['input_type']);
        $this->assertSame('select', $attributes['status']['value_element']);
        $this->assertSame('numeric', $attributes['grand_total']['input_type']);
        $this->assertSame('text', $attributes['grand_total']['value_element']);
        $this->assertSame('date', $attributes['created_at']['input_type']);
        $this->assertSame('date', $attributes['created_at']['value_element']);
    }

    public function testOperatorsAreFilteredByTheAttributeInputType(): void
    {
        $attributes = $this->meta(MetaFixtureLeaf::class)['attributes'];

        // Core's operator sets per input type, projected verbatim — including
        // core's '<=>' ("is undefined") on select/boolean, which the widget
        // offers alongside is/is not.
        $this->assertSame(['==', '!=', '<=>'], array_column($attributes['status']['operators'], 'value'));
        $this->assertSame(
            ['==', '!=', '>=', '<=', '>', '<', '()', '!()'],
            array_column($attributes['grand_total']['operators'], 'value')
        );
        $this->assertSame(['==', '>=', '<='], array_column($attributes['created_at']['operators'], 'value'));
        // Labels travel with the operators — the client renders server strings.
        $this->assertSame('is', $attributes['status']['operators'][0]['label']);
    }

    public function testInlineValueOptionsAreServedPerAttributeWithoutBleedingBetweenThem(): void
    {
        $attributes = $this->meta(MetaFixtureLeaf::class)['attributes'];

        $this->assertSame(['pending', 'complete'], array_column($attributes['status']['value_options'], 'value'));
        // A leaf memoizes its resolved option list per instance, and `status`
        // resolved first: `flagged` showing pending/complete instead of its own
        // Yes/No enum is exactly the bleed this asserts against.
        $this->assertSame(['1', '0'], array_column($attributes['flagged']['value_options'], 'value'));
        $this->assertSame(['Yes', 'No'], array_column($attributes['flagged']['value_options'], 'label'));
        // Text and date elements render no choice list, so no option source is
        // consulted for them at all.
        $this->assertSame([], $attributes['grand_total']['value_options']);
        $this->assertSame([], $attributes['created_at']['value_options']);
    }

    public function testGroupedValueOptionsKeepTheirHeadingAndTrimIndentation(): void
    {
        $attributes = $this->meta(MetaFixtureLeaf::class)['attributes'];
        $options = $attributes['ship_via']['value_options'];

        // Nested optgroups survive projection as a `group` key on each leaf —
        // "Ground" is ambiguous without "UPS" — and the leading-space
        // indentation some core sources emit (a flat-<select> visual hack that
        // HTML collapses) is trimmed in favour of the real grouping.
        $this->assertSame(
            [
                ['value' => 'ups_GND', 'label' => 'Ground', 'group' => 'UPS'],
                ['value' => 'ups_1DA', 'label' => 'Next Day Air', 'group' => 'UPS'],
                ['value' => 'pickup', 'label' => 'Store Pickup'],
            ],
            $options
        );
        // Ungrouped enums stay exactly as they were: no group key appears.
        $this->assertSame(
            [['value' => 'pending', 'label' => 'Pending'], ['value' => 'complete', 'label' => 'Complete']],
            $attributes['status']['value_options']
        );
    }

    // --- FQCN validation -----------------------------------------------------

    public function testUnreachableConditionClassIsRejected(): void
    {
        // A genuine condition class that no combine in this tree offers.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not reachable');
        $this->meta(MetaFixtureUnreachableLeaf::class);
    }

    public function testNonConditionClassIsRejectedEvenWhenReachable(): void
    {
        // Offered by the root (a third-party mis-registration), but not a
        // condition — it must never be instantiated.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must extend');
        $this->meta(MetaFixtureNotACondition::class);
    }

    public function testUnknownClassNameIsRejectedWithoutTouchingTheClassLoader(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not reachable');
        $this->meta('Totally\\Made\\Up\\ClassName');
    }

    // --- reachability walk ---------------------------------------------------

    public function testTransitivelyOfferedNodesAreReachable(): void
    {
        // Product leaf: root -> items subtree -> product attribute optgroup.
        $meta = $this->meta(MetaFixtureProductLeaf::class);

        $this->assertSame(ConditionMetaProvider::KIND_LEAF, $meta['kind']);
        $this->assertArrayHasKey('sku', $meta['attributes']);
        // The label a node carries is the one its parent offered it under.
        $this->assertSame('Product Attribute', $meta['label']);
    }

    public function testCyclesInTheChildOptionGraphTerminate(): void
    {
        // The items subtree offers the root back (the shipped analogue is the
        // order root offering the customer subtree, which offers its own
        // subtrees): the walk must complete rather than recurse forever.
        $meta = $this->meta(MetaFixtureItemsCombine::class);

        $this->assertSame('Order Items', $meta['label']);
        $this->assertSame(ConditionMetaProvider::KIND_COMBINE, $meta['kind']);
    }

    public function testAnUninstantiableNodeDoesNotBreakTheRestOfTheTree(): void
    {
        $meta = $this->meta(MetaFixtureRootCombine::class);

        $this->assertTrue($meta['new_children'] !== [], 'The root metadata still resolves');
        $this->assertCount(1, $this->logger->warnings);
        $this->assertStringContainsString(MetaFixtureBrokenCombine::class, $this->logger->warnings[0]);
    }

    // --- kinds ---------------------------------------------------------------

    public function testTriggerDataIsItsOwnKindWithAFreeAttributeAndStringOperators(): void
    {
        $meta = $this->meta(TriggerData::class);

        $this->assertSame(ConditionMetaProvider::KIND_TRIGGER_DATA, $meta['kind']);
        // Free dot-path by design: no attribute option list to choose from.
        $this->assertSame([], $meta['attributes']);
        $this->assertSame('string', $meta['input_type']);
        $this->assertSame('text', $meta['value_element']);
        $this->assertSame(
            ['==', '!=', '>=', '<=', '>', '<', '{}', '!{}', '()', '!()'],
            array_column($meta['operators'], 'value')
        );
    }

    public function testRelatedEntityNodeServesRelationsMatchModesAndTheExistsEnum(): void
    {
        $meta = $this->meta(RelatedEntityCombine::class);

        $this->assertSame(ConditionMetaProvider::KIND_RELATED, $meta['kind']);
        // Only relations SOURCED from this entity type, keyed by the pool code
        // the node stores in `relation`.
        $this->assertSame(
            ['order.customer_by_email', 'order.orders_by_email'],
            array_column($meta['relations'], 'value')
        );
        $this->assertSame('a customer account matching the order email', $meta['relations'][0]['label']);
        // Cardinality travels along: the match mode only means something for a
        // to-many relation.
        $this->assertSame(RelationInterface::CARDINALITY_ONE, $meta['relations'][0]['cardinality']);
        $this->assertSame(RelationInterface::CARDINALITY_MANY, $meta['relations'][1]['cardinality']);
        $this->assertSame(['any', 'all', 'none'], array_column($meta['match_modes'], 'value'));
        // EXISTS / NOT EXISTS comes from the node's own loadValueOptions().
        $this->assertSame(['1', '0'], array_column($meta['value_options'], 'value'));
        $this->assertSame(['EXISTS', 'NOT EXISTS'], array_column($meta['value_options'], 'label'));
    }

    public function testOnlyRelatedNodesCarryRelationsAndMatchModes(): void
    {
        $root = $this->meta(MetaFixtureRootCombine::class);

        $this->assertSame([], $root['relations']);
        $this->assertSame([], $root['match_modes']);
    }

    // --- combine metadata ----------------------------------------------------

    public function testAggregatorsAndValueOptionsComeFromTheCombineItself(): void
    {
        $root = $this->meta(MetaFixtureRootCombine::class);

        $this->assertSame(['all', 'any'], array_column($root['aggregators'], 'value'));
        $this->assertSame(['ALL', 'ANY'], array_column($root['aggregators'], 'label'));
        // A plain combine validates its children against TRUE / FALSE...
        $this->assertSame(['TRUE', 'FALSE'], array_column($root['value_options'], 'label'));
        // ...while a subtree combine overrides the enum with its own wording.
        $items = $this->meta(MetaFixtureItemsCombine::class);
        $this->assertSame(['FOUND', 'NOT FOUND'], array_column($items['value_options'], 'label'));
    }

    public function testCombineNodesExposeNoAttributeMetadata(): void
    {
        $root = $this->meta(MetaFixtureRootCombine::class);

        $this->assertSame([], $root['attributes']);
        $this->assertNull($root['input_type']);
        $this->assertNull($root['value_element']);
        $this->assertSame([], $root['operators']);
    }
}

/**
 * ObjectManagerInterface stand-in driven by a class => factory map, so a
 * fixture can also be made to fail construction.
 */
final class MetaFixtureObjectManager implements ObjectManagerInterface
{
    /**
     * @param array<string, callable(): object> $factories
     */
    public function __construct(private readonly array $factories)
    {
    }

    public function create($type, array $arguments = [])
    {
        if (!isset($this->factories[$type])) {
            throw new \RuntimeException(sprintf('No fixture factory registered for "%s"', $type));
        }
        return ($this->factories[$type])();
    }

    public function get($type)
    {
        return $this->create($type);
    }

    public function configure(array $configuration)
    {
    }
}

/**
 * Records the warnings the reachability walk emits for unusable classes.
 */
final class MetaFixtureLogger implements LoggerInterface
{
    /** @var string[] */
    public array $warnings = [];

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->warnings[] = (string)$message;
    }

    public function emergency(string|\Stringable $message, array $context = []): void
    {
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
    }
}

/**
 * Entity root: reproduces every child-option shape the shipped sales root emits
 * (see MageOS\WorkflowsSales\Model\Rule\Condition\Order\Combine).
 */
final class MetaFixtureRootCombine extends Combine
{
    // Bypass the parent constructor: the real AbstractCondition/Combine
    // require a live Context on a full install (peer convention: construct
    // conditions without their constructor). The option-loading trio the real
    // constructor runs is replayed by hand — it needs no Context, and the real
    // getOperatorSelectOptions()/getValueSelectOptions() read exactly the data
    // it populates (skip it and the real one foreaches over a null
    // `operator_option`, which is a fatal on an install).
    public function __construct()
    {
        $this->loadAttributeOptions()->loadOperatorOptions()->loadValueOptions();
    }

    /**
     * @return array
     */
    public function getNewChildSelectOptions()
    {
        return [
            // Core's leading placeholder.
            ['value' => '', 'label' => 'Please choose a condition to add.'],
            ['value' => self::class, 'label' => 'Conditions Combination'],
            ['value' => MetaFixtureItemsCombine::class, 'label' => 'Order Items'],
            ['value' => TriggerData::class, 'label' => 'Trigger Data (advanced)'],
            ['value' => RelatedEntityCombine::class, 'label' => 'Related Entity (exists / not exists)'],
            ['value' => MetaFixtureBrokenCombine::class, 'label' => 'Broken Subtree'],
            ['value' => MetaFixtureNotACondition::class, 'label' => 'Mis-registered'],
            ['label' => 'Order Attribute', 'value' => [
                ['value' => MetaFixtureLeaf::class . '|status', 'label' => 'Order Status'],
                ['value' => MetaFixtureLeaf::class . '|grand_total', 'label' => 'Grand Total'],
            ]],
            // A domain pack that is not installed contributes no options.
            ['label' => 'Product Attribute', 'value' => []],
        ];
    }
}

/**
 * Items subtree: FOUND / NOT FOUND value enum, a cross-pack product leaf and a
 * cycle back to the root.
 */
final class MetaFixtureItemsCombine extends Combine
{
    // Bypass the parent constructor: the real AbstractCondition/Combine
    // require a live Context on a full install (peer convention: construct
    // conditions without their constructor). The option-loading trio the real
    // constructor runs is replayed by hand — it needs no Context, and the real
    // getOperatorSelectOptions()/getValueSelectOptions() read exactly the data
    // it populates (skip it and the real one foreaches over a null
    // `operator_option`, which is a fatal on an install).
    public function __construct()
    {
        $this->loadAttributeOptions()->loadOperatorOptions()->loadValueOptions();
    }

    /**
     * @return $this
     */
    public function loadValueOptions()
    {
        $this->setValueOption([1 => __('FOUND'), 0 => __('NOT FOUND')]);
        return $this;
    }

    /**
     * @return array
     */
    public function getNewChildSelectOptions()
    {
        return [
            ['value' => MetaFixtureRootCombine::class, 'label' => 'Conditions Combination'],
            ['label' => 'Product Attribute', 'value' => [
                ['value' => MetaFixtureProductLeaf::class . '|sku', 'label' => 'SKU'],
            ]],
        ];
    }
}

/**
 * Attribute leaf with one attribute per input type, memoizing its resolved
 * value options exactly the way the shipped leaves do.
 */
final class MetaFixtureLeaf extends AbstractCondition
{
    // Bypass the parent constructor: the real AbstractCondition/Combine
    // require a live Context on a full install (peer convention: construct
    // conditions without their constructor). The option-loading trio the real
    // constructor runs is replayed by hand — it needs no Context, and the real
    // getOperatorSelectOptions()/getValueSelectOptions() read exactly the data
    // it populates (skip it and the real one foreaches over a null
    // `operator_option`, which is a fatal on an install).
    public function __construct()
    {
        $this->loadAttributeOptions()->loadOperatorOptions()->loadValueOptions();
    }

    /**
     * @return $this
     */
    public function loadAttributeOptions()
    {
        $this->setAttributeOption([
            'status' => __('Order Status'),
            'grand_total' => __('Grand Total'),
            'created_at' => __('Created At'),
            'flagged' => __('Flagged'),
            'ship_via' => __('Shipping Method'),
        ]);
        return $this;
    }

    /**
     * @return string
     */
    public function getInputType()
    {
        return match ((string)$this->getAttribute()) {
            'status', 'ship_via' => 'select',
            'grand_total' => 'numeric',
            'created_at' => 'date',
            'flagged' => 'boolean',
            default => 'string',
        };
    }

    /**
     * @return string
     */
    public function getValueElementType()
    {
        return match ($this->getInputType()) {
            'date' => 'date',
            'select', 'boolean' => 'select',
            default => 'text',
        };
    }

    /**
     * @return array
     */
    public function getValueSelectOptions()
    {
        if (!$this->hasData('value_select_options')) {
            $this->setData('value_select_options', match ((string)$this->getAttribute()) {
                'status' => [
                    ['value' => 'pending', 'label' => __('Pending')],
                    ['value' => 'complete', 'label' => __('Complete')],
                ],
                'flagged' => [
                    ['value' => 1, 'label' => __('Yes')],
                    ['value' => 0, 'label' => __('No')],
                ],
                // The core Allmethods / Store::getStoreValuesForForm() shape:
                // nested option groups, with the leading-space indentation some
                // sources emit for flat-select rendering.
                'ship_via' => [
                    ['label' => __('UPS'), 'value' => [
                        ['value' => 'ups_GND', 'label' => __('    Ground')],
                        ['value' => 'ups_1DA', 'label' => __('Next Day Air')],
                    ]],
                    ['value' => 'pickup', 'label' => __('Store Pickup')],
                ],
                default => [],
            });
        }
        return $this->getData('value_select_options');
    }

    public function validate(DataObject $model)
    {
        return true;
    }
}

/**
 * Cross-pack leaf reached through the items subtree.
 */
final class MetaFixtureProductLeaf extends AbstractCondition
{
    // Bypass the parent constructor: the real AbstractCondition/Combine
    // require a live Context on a full install (peer convention: construct
    // conditions without their constructor). The option-loading trio the real
    // constructor runs is replayed by hand — it needs no Context, and the real
    // getOperatorSelectOptions()/getValueSelectOptions() read exactly the data
    // it populates (skip it and the real one foreaches over a null
    // `operator_option`, which is a fatal on an install).
    public function __construct()
    {
        $this->loadAttributeOptions()->loadOperatorOptions()->loadValueOptions();
    }

    /**
     * @return $this
     */
    public function loadAttributeOptions()
    {
        $this->setAttributeOption(['sku' => __('SKU'), 'price' => __('Price')]);
        return $this;
    }

    public function validate(DataObject $model)
    {
        return true;
    }
}

/**
 * Relation-target leaf offered by the RelatedEntity combine.
 */
final class MetaFixtureCustomerLeaf extends AbstractCondition
{
    // Bypass the parent constructor: the real AbstractCondition/Combine
    // require a live Context on a full install (peer convention: construct
    // conditions without their constructor). The option-loading trio the real
    // constructor runs is replayed by hand — it needs no Context, and the real
    // getOperatorSelectOptions()/getValueSelectOptions() read exactly the data
    // it populates (skip it and the real one foreaches over a null
    // `operator_option`, which is a fatal on an install).
    public function __construct()
    {
        $this->loadAttributeOptions()->loadOperatorOptions()->loadValueOptions();
    }

    /**
     * @return $this
     */
    public function loadAttributeOptions()
    {
        $this->setAttributeOption(['email' => __('Email')]);
        return $this;
    }

    public function validate(DataObject $model)
    {
        return true;
    }
}

/**
 * A real condition class that no combine in the fixture tree offers.
 */
final class MetaFixtureUnreachableLeaf extends AbstractCondition
{
    // Bypass the parent constructor: the real AbstractCondition/Combine
    // require a live Context on a full install (peer convention: construct
    // conditions without their constructor). The option-loading trio the real
    // constructor runs is replayed by hand — it needs no Context, and the real
    // getOperatorSelectOptions()/getValueSelectOptions() read exactly the data
    // it populates (skip it and the real one foreaches over a null
    // `operator_option`, which is a fatal on an install).
    public function __construct()
    {
        $this->loadAttributeOptions()->loadOperatorOptions()->loadValueOptions();
    }

    public function validate(DataObject $model)
    {
        return true;
    }
}

/**
 * Offered by the root but impossible to construct (broken DI wiring).
 */
final class MetaFixtureBrokenCombine extends Combine
{
    public function __construct()
    {
        throw new \RuntimeException('missing dependency');
    }
}

/**
 * Offered by the root but not a condition at all.
 */
final class MetaFixtureNotACondition
{
}

/**
 * @see \MageOS\Workflows\Api\RelationInterface
 */
final class MetaFixtureRelation implements RelationInterface
{
    public function __construct(
        private readonly string $code,
        private readonly string $label,
        private readonly string $sourceEntityType,
        private readonly string $targetEntityType,
        private readonly string $cardinality
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getSourceEntityType(): string
    {
        return $this->sourceEntityType;
    }

    public function getTargetEntityType(): string
    {
        return $this->targetEntityType;
    }

    public function getCardinality(): string
    {
        return $this->cardinality;
    }

    public function resolveIds(DataObject $source, ?int $websiteId): array
    {
        return [];
    }
}
