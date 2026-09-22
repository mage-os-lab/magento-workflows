<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Test\Unit\Block\Adminhtml\Template;

use MageOS\Workflows\Api\OptionSourceInterface;
use MageOS\Workflows\Model\Option\EntityOptionSourceRegistry;
use MageOS\Workflows\Model\Option\OptionSourcePool;
use MageOS\WorkflowsAdminUi\Block\Adminhtml\Template\Install;
use MageOS\WorkflowsAdminUi\Controller\Adminhtml\Template\Install as InstallController;
use Magento\Framework\App\Request\DataPersistorInterface;
use PHPUnit\Framework\TestCase;

/**
 * Pins the install form's widget mapping (issue #27): which control a
 * parameter renders as, and the four helpers the template asks the block for.
 *
 * The decision is the whole point of the class, so it is exercised through the
 * real block with the REAL OptionSourcePool / EntityOptionSourceRegistry (both
 * are plain array-configured collaborators — hand-written option sources are
 * all the doubling needed). Install extends Backend\Block\Template, whose real
 * constructor is layout-heavy and unavailable here, so the block is built as an
 * anonymous subclass with a no-op constructor overriding the two inherited
 * helpers these methods call (getUrl/getLocale), with Install's own promoted
 * dependencies injected by reflection — the posture MountApprovalsAvailableTest
 * takes in the canvas package.
 *
 * The contract the matrix protects: a source that is unregistered, uninstalled
 * or THROWING must degrade the field to a plain text input. The form is the
 * only way to install a template from the admin; it may not 500 because a
 * domain pack's collection blew up, and it may not render an empty select that
 * makes a required parameter unsatisfiable.
 */
class InstallWidgetMappingTest extends TestCase
{
    public function testInlineOptionsRenderASelect(): void
    {
        $parameter = [
            'key' => 'mode',
            'type' => 'select',
            'options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']],
        ];

        $this->assertSame('select', $this->block()->widget($parameter));
    }

    public function testInlineOptionsWinOverAnEntityTypeAndItsSource(): void
    {
        // Precedence: inline options > options_search > entity:* mapping.
        $parameter = [
            'key' => 'status',
            'type' => 'entity:order_status',
            'options' => [['value' => 'processing', 'label' => 'Processing']],
        ];
        $block = $this->block();

        $this->assertSame('select', $block->widget($parameter));
        $this->assertSame(
            [['value' => 'processing', 'label' => 'Processing']],
            $block->options($parameter)
        );
    }

    public function testSecretTypeRendersTheSecretPair(): void
    {
        $this->assertSame('secret', $this->block()->widget(['key' => 's', 'type' => 'secret']));
    }

    public function testDurationTypeRendersTheDurationComposite(): void
    {
        $this->assertSame('duration', $this->block()->widget(['key' => 'delay', 'type' => 'duration']));
    }

    public function testNumberTypeRendersANumberInput(): void
    {
        $this->assertSame('number', $this->block()->widget(['key' => 'qty', 'type' => 'number']));
    }

    public function testUrlTypeRendersAUrlInput(): void
    {
        $this->assertSame('url', $this->block()->widget(['key' => 'hook', 'type' => 'url']));
    }

    public function testOptionsSearchRendersASearchPicker(): void
    {
        $parameter = [
            'key' => 'rule',
            'type' => 'string',
            'options_search' => ['source' => 'cart_price_rules', 'min_chars' => 3],
        ];

        $this->assertSame('search', $this->block()->widget($parameter));
    }

    public function testOptionsSearchNamingAnUninstalledSourceDegradesToText(): void
    {
        $parameter = [
            'key' => 'rule',
            'type' => 'string',
            'options_search' => ['source' => 'not_installed', 'min_chars' => 3],
        ];

        $this->assertSame('text', $this->block()->widget($parameter));
    }

    public function testBoundedEntityRendersASelect(): void
    {
        $this->assertSame('select', $this->block()->widget(['key' => 'st', 'type' => 'entity:order_status']));
    }

    public function testUnboundedEntityRendersASearchPicker(): void
    {
        $this->assertSame('search', $this->block()->widget(['key' => 'r', 'type' => 'entity:salesrule']));
    }

    public function testUnregisteredEntityAliasDegradesToText(): void
    {
        $this->assertSame('text', $this->block()->widget(['key' => 'x', 'type' => 'entity:unicorn']));
    }

    public function testEntityAliasMappedToAnUninstalledSourceDegradesToText(): void
    {
        $this->assertSame('text', $this->block()->widget(['key' => 'x', 'type' => 'entity:orphan']));
    }

    public function testThrowingBoundedSourceDegradesToTextRatherThanFailingTheForm(): void
    {
        $parameter = ['key' => 'st', 'type' => 'entity:broken'];
        $block = $this->block();

        $this->assertSame('text', $block->widget($parameter));
        $this->assertSame([], $block->options($parameter));
    }

    public function testStringTypeWithNoSourceRendersText(): void
    {
        $this->assertSame('text', $this->block()->widget(['key' => 'n', 'type' => 'string']));
    }

    public function testBoundedEntityOptionsComeFromThePoolWithAPlaceholderWhenNothingIsChosen(): void
    {
        $options = $this->block()->options(['key' => 'st', 'type' => 'entity:order_status']);

        $this->assertSame(
            [
                ['value' => '', 'label' => '-- Please select --'],
                ['value' => 'pending', 'label' => 'Pending'],
                ['value' => 'processing', 'label' => 'Processing'],
            ],
            $options
        );
    }

    public function testBoundedEntityOptionsOmitThePlaceholderWhenTheParameterHasADefault(): void
    {
        $options = $this->block()->options([
            'key' => 'st',
            'type' => 'entity:order_status',
            'default' => 'pending',
        ]);

        $this->assertSame(
            [
                ['value' => 'pending', 'label' => 'Pending'],
                ['value' => 'processing', 'label' => 'Processing'],
            ],
            $options
        );
    }

    public function testBoundedEntityOptionsOmitThePlaceholderWhenAValueWasPersisted(): void
    {
        $block = $this->block(['param' => ['st' => 'processing']]);

        $this->assertSame(
            [
                ['value' => 'pending', 'label' => 'Pending'],
                ['value' => 'processing', 'label' => 'Processing'],
            ],
            $block->options(['key' => 'st', 'type' => 'entity:order_status'])
        );
    }

    /**
     * A bounded select renders from the source's FULL list — fetch()'s 50-row
     * cap protects the type-ahead endpoints and must not silently hide rows
     * from a server-rendered select.
     */
    public function testBoundedEntityLargerThanTheFetchCapRendersEveryRow(): void
    {
        $options = $this->block()->options(['key' => 'g', 'type' => 'entity:big', 'default' => '0']);

        $this->assertCount(60, $options);
        $this->assertSame('59', $options[59]['value']);
    }

    /**
     * A "bounded" alias whose list tops MAX_BOUNDED_SELECT degrades to the
     * search picker: never a truncated select, never an unusably long one. The
     * registry keeps min_chars at 0 for bounded aliases, so the degraded
     * picker searches from the first keystroke.
     */
    public function testOversizedBoundedEntityDegradesToTheSearchPicker(): void
    {
        $block = $this->block();
        $parameter = ['key' => 'h', 'type' => 'entity:huge'];

        $this->assertSame('search', $block->widget($parameter));
        $this->assertSame([], $block->options($parameter));

        $config = $block->searchConfig($parameter);
        $this->assertSame('huge_bounded', $config['source']);
        $this->assertSame(0, $config['min_chars']);
    }

    public function testSearchConfigCarriesTheAdminFeedTheSourceAndTheMinChars(): void
    {
        $config = $this->block()->searchConfig([
            'key' => 'rule',
            'type' => 'string',
            'options_search' => ['source' => 'cart_price_rules', 'min_chars' => 3],
        ]);

        $this->assertSame(
            [
                'url' => 'mageos_workflows/data/options',
                'source' => 'cart_price_rules',
                'min_chars' => 3,
            ],
            $config
        );
    }

    public function testSearchConfigFallsBackToTheRegistryMinCharsForAnEntityType(): void
    {
        $config = $this->block()->searchConfig(['key' => 'r', 'type' => 'entity:salesrule']);

        $this->assertSame('cart_price_rules', $config['source']);
        $this->assertSame(2, $config['min_chars']);
    }

    public function testCurrentValueLabelResolvesTheLabelAndTheId(): void
    {
        $block = $this->block(['param' => ['r' => '7']]);

        $this->assertSame(
            'Summer sale (7)',
            $block->currentValueLabel(['key' => 'r', 'type' => 'entity:salesrule'])
        );
    }

    public function testCurrentValueLabelIsEmptyWithoutAValue(): void
    {
        $this->assertSame('', $this->block()->currentValueLabel(['key' => 'r', 'type' => 'entity:salesrule']));
    }

    public function testCurrentValueLabelIsEmptyWhenTheSourceDoesNotKnowTheValue(): void
    {
        $block = $this->block(['param' => ['r' => '999']]);

        $this->assertSame('', $block->currentValueLabel(['key' => 'r', 'type' => 'entity:salesrule']));
    }

    public function testCurrentValueLabelIsEmptyWhenTheSourceThrows(): void
    {
        // Unbounded, so the source survives widget resolution (nothing is
        // fetched there) and blows up in the label lookup itself.
        $block = $this->block(['param' => ['r' => '7']]);

        $this->assertSame('search', $block->widget(['key' => 'r', 'type' => 'entity:broken_search']));
        $this->assertSame('', $block->currentValueLabel(['key' => 'r', 'type' => 'entity:broken_search']));
    }

    /**
     * Only the three intervals the composite control can represent EXACTLY
     * round-trip; everything else leaves the field a raw ISO input — the same
     * fallback a JavaScript-off render gets.
     */
    public function testDurationPartsRoundTripTheRepresentableIntervals(): void
    {
        $block = $this->block();

        $this->assertSame(['value' => 1, 'unit' => 'days'], $block->durationParts($this->duration('P1D')));
        $this->assertSame(['value' => 4, 'unit' => 'hours'], $block->durationParts($this->duration('PT4H')));
        $this->assertSame(['value' => 30, 'unit' => 'minutes'], $block->durationParts($this->duration('PT30M')));
    }

    public function testDurationPartsAreNullForAnythingTheCompositeCannotRepresent(): void
    {
        $block = $this->block();

        $this->assertNull($block->durationParts($this->duration('P1DT12H')), 'compound intervals stay raw');
        $this->assertNull($block->durationParts($this->duration('')), 'an empty value stays raw');
        $this->assertNull($block->durationParts($this->duration('tomorrow')), 'garbage stays raw');
        $this->assertNull($block->durationParts($this->duration('PT4H30M')));
        $this->assertNull($block->durationParts($this->duration('P1W')));
    }

    public function testNumberAttrsCarryOnlyTheDeclaredSubsetAsStrings(): void
    {
        $block = $this->block();

        $this->assertSame(
            ['min' => '1', 'max' => '10', 'step' => '0.5'],
            $block->numberAttrs(['key' => 'q', 'type' => 'number', 'min' => 1, 'max' => 10, 'step' => 0.5])
        );
        $this->assertSame(
            ['min' => '0'],
            $block->numberAttrs(['key' => 'q', 'type' => 'number', 'min' => 0])
        );
        $this->assertSame([], $block->numberAttrs(['key' => 'q', 'type' => 'number']));
    }

    public function testAuthoredNoteWinsOverTheDerivedOne(): void
    {
        $note = $this->block()->fieldNote([
            'key' => 'r',
            'type' => 'entity:salesrule',
            'note' => 'Pick the rule that grants the coupon.',
        ]);

        $this->assertSame('Pick the rule that grants the coupon.', (string) $note);
    }

    public function testAuthoredNoteIsResolvedFromALocalizedMap(): void
    {
        $note = $this->block()->fieldNote([
            'key' => 'r',
            'type' => 'string',
            'note' => ['en_US' => 'Authored help.', 'de_DE' => 'Hilfe.'],
        ]);

        $this->assertSame('Authored help.', (string) $note);
    }

    public function testDerivedNotesCoverTheWidgetsThatNeedOne(): void
    {
        $block = $this->block();

        $this->assertSame(
            'Search by name, or enter the record ID.',
            (string) $block->fieldNote(['key' => 'r', 'type' => 'entity:salesrule'])
        );
        $this->assertStringContainsString(
            'ISO-8601 duration',
            (string) $block->fieldNote(['key' => 'd', 'type' => 'duration'])
        );
        $this->assertStringContainsString(
            'Names the secret',
            (string) $block->fieldNote(['key' => 's', 'type' => 'secret'])
        );
    }

    public function testWidgetsWithoutADerivedNoteGetNone(): void
    {
        $block = $this->block();

        $this->assertNull($block->fieldNote(['key' => 'n', 'type' => 'number']));
        $this->assertNull($block->fieldNote(['key' => 'u', 'type' => 'url']));
        $this->assertNull($block->fieldNote(['key' => 't', 'type' => 'string']));
        $this->assertNull($block->fieldNote([
            'key' => 'm',
            'type' => 'select',
            'options' => [['value' => 'a', 'label' => 'A']],
        ]));
    }

    /**
     * The old note promised a picker "with the canvas package". The picker is
     * here; the promise must not outlive it.
     */
    public function testTheDerivedSearchNoteNoLongerPromisesALaterPackage(): void
    {
        $note = (string) $this->block()->fieldNote(['key' => 'r', 'type' => 'entity:salesrule']);

        $this->assertStringNotContainsString('canvas package', $note);
    }

    /**
     * @return array<string, mixed> a duration parameter carrying $value
     */
    private function duration(string $value): array
    {
        return ['key' => 'delay', 'type' => 'duration', 'default' => $value];
    }

    /**
     * @param array<string, mixed> $persisted the persistor payload, as the
     *        install controller writes it back after a failed install
     */
    private function block(array $persisted = []): Install
    {
        $block = new class extends Install {
            // Skip the layout-heavy Template constructor entirely.
            public function __construct()
            {
            }

            public function getUrl($route = '', $params = [])
            {
                return (string) $route;
            }

            public function getLocale(): string
            {
                return 'en_US';
            }
        };

        $dependencies = [
            'dataPersistor' => new class ($persisted) implements DataPersistorInterface {
                /**
                 * @param array<string, mixed> $data
                 */
                public function __construct(private readonly array $data)
                {
                }

                public function set($key, $value)
                {
                    throw new \LogicException('not exercised');
                }

                public function get($key)
                {
                    return $key === InstallController::PERSISTOR_KEY ? $this->data : null;
                }

                public function clear($key)
                {
                    throw new \LogicException('not exercised');
                }
            },
            'optionSourcePool' => new OptionSourcePool([
                'order_statuses' => $this->source('order_statuses', [
                    ['value' => 'pending', 'label' => 'Pending'],
                    ['value' => 'processing', 'label' => 'Processing'],
                ]),
                'cart_price_rules' => $this->source('cart_price_rules', [
                    ['value' => '7', 'label' => 'Summer sale'],
                ]),
                // Bounded, but bigger than fetch()'s 50-row cap: still a select.
                'big_bounded' => $this->source('big_bounded', $this->rows(60)),
                // "Bounded", but bigger than the block's select ceiling.
                'huge_bounded' => $this->source('huge_bounded', $this->rows(201)),
                'broken' => $this->throwingSource('broken'),
            ]),
            'entityRegistry' => new EntityOptionSourceRegistry([
                'order_status' => ['source' => 'order_statuses', 'bounded' => true],
                'salesrule' => ['source' => 'cart_price_rules'],
                'big' => ['source' => 'big_bounded', 'bounded' => true],
                'huge' => ['source' => 'huge_bounded', 'bounded' => true],
                // Registered, but its domain pack contributed no source.
                'orphan' => ['source' => 'never_registered'],
                // The same throwing source, bounded (fetched to render a
                // select) and unbounded (fetched only to label a picked value).
                'broken' => ['source' => 'broken', 'bounded' => true],
                'broken_search' => ['source' => 'broken'],
            ]),
        ];
        foreach ($dependencies as $property => $value) {
            (new \ReflectionProperty(Install::class, $property))->setValue($block, $value);
        }

        return $block;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function rows(int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['value' => (string) $i, 'label' => 'Row ' . $i];
        }
        return $rows;
    }

    /**
     * @param array<int, array{value: string, label: string}> $options
     */
    private function source(string $code, array $options): OptionSourceInterface
    {
        return new class ($code, $options) implements OptionSourceInterface {
            /**
             * @param array<int, array{value: string, label: string}> $options
             */
            public function __construct(private readonly string $code, private readonly array $options)
            {
            }

            public function getCode(): string
            {
                return $this->code;
            }

            public function fetch(?string $query = null): array
            {
                if ($query === null || $query === '') {
                    return $this->options;
                }
                return array_values(array_filter(
                    $this->options,
                    static fn (array $option): bool => stripos($option['value'], $query) !== false
                        || stripos($option['label'], $query) !== false
                ));
            }

            public function all(): array
            {
                return $this->options;
            }

            public function hasValue(string $value): bool
            {
                foreach ($this->options as $option) {
                    if ($option['value'] === $value) {
                        return true;
                    }
                }
                return false;
            }
        };
    }

    /**
     * A source whose backing collection blows up — a broken/misconfigured
     * domain pack, from the form's point of view.
     */
    private function throwingSource(string $code): OptionSourceInterface
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
                throw new \RuntimeException('the collection is unavailable');
            }

            public function all(): array
            {
                throw new \RuntimeException('the collection is unavailable');
            }

            public function hasValue(string $value): bool
            {
                throw new \RuntimeException('the collection is unavailable');
            }
        };
    }
}
