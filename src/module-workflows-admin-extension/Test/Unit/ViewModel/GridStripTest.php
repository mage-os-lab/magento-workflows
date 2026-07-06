<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminExtension\Test\Unit\ViewModel;

use MageOS\Workflows\Test\Unit\Stub\StubScopeConfig;
use MageOS\WorkflowsAdminExtension\Model\WorkflowCountProvider;
use MageOS\WorkflowsAdminExtension\Test\Unit\Stub\FakeAuthorization;
use MageOS\WorkflowsAdminExtension\Test\Unit\Stub\FakeEntityTypeMetadataProvider;
use MageOS\WorkflowsAdminExtension\Test\Unit\Stub\RecordingUrlBuilder;
use MageOS\WorkflowsAdminExtension\ViewModel\GridStrip;
use PHPUnit\Framework\TestCase;

/**
 * GridStrip is the render gate and link builder for the grid strip. The gating
 * matrix is the load-bearing behavior: a feature bolted onto native admin pages
 * must stay invisible when the toggle is off, when the admin can't view
 * workflows, or when there is nothing worth showing. The link builder must
 * produce the filters_modifier deep link the workflow grid understands.
 */
class GridStripTest extends TestCase
{
    private const CONFIG_EXPOSE = 'mageos_workflows/general/expose_on_entity_grids';

    /**
     * @param array{enabled:int, total:int} $counts
     */
    private function countProvider(array $counts): WorkflowCountProvider
    {
        return new class ($counts) extends WorkflowCountProvider {
            /**
             * @param array{enabled:int, total:int} $counts
             */
            public function __construct(private readonly array $counts)
            {
                // Deliberately bypasses the parent constructor: this stub never
                // queries, it just returns the counts the test fixed.
            }

            public function getCounts(string $entityType): array
            {
                return $this->counts;
            }
        };
    }

    /**
     * @param array{enabled:int, total:int} $counts
     * @param array<string, bool> $acl
     */
    private function gridStrip(
        array $counts,
        array $acl,
        bool $toggleOn = true,
        ?RecordingUrlBuilder $url = null
    ): GridStrip {
        return new GridStrip(
            ['sales_order_index' => 'sales_order'],
            $this->countProvider($counts),
            new FakeEntityTypeMetadataProvider(['sales_order' => 'Orders', 'customer' => 'Customers']),
            new FakeAuthorization($acl),
            new StubScopeConfig([self::CONFIG_EXPOSE => $toggleOn]),
            $url ?? new RecordingUrlBuilder()
        );
    }

    public function testHiddenWhenToggleOff(): void
    {
        $strip = $this->gridStrip(
            ['enabled' => 3, 'total' => 5],
            ['MageOS_Workflows::view' => true, 'MageOS_Workflows::manage' => true],
            false
        );

        $this->assertFalse($strip->shouldRender('sales_order'));
    }

    public function testHiddenWithoutViewAcl(): void
    {
        $strip = $this->gridStrip(
            ['enabled' => 3, 'total' => 5],
            ['MageOS_Workflows::view' => false, 'MageOS_Workflows::manage' => true]
        );

        $this->assertFalse($strip->shouldRender('sales_order'));
    }

    public function testHiddenWhenZeroCountsAndCannotManage(): void
    {
        $strip = $this->gridStrip(
            ['enabled' => 0, 'total' => 0],
            ['MageOS_Workflows::view' => true, 'MageOS_Workflows::manage' => false]
        );

        $this->assertFalse($strip->shouldRender('sales_order'), 'Nothing to view and nothing to create => hidden');
    }

    public function testShownWhenZeroCountsButCanManage(): void
    {
        $strip = $this->gridStrip(
            ['enabled' => 0, 'total' => 0],
            ['MageOS_Workflows::view' => true, 'MageOS_Workflows::manage' => true]
        );

        // The discoverability case: no workflows yet, but the admin can create one.
        $this->assertTrue($strip->shouldRender('sales_order'));
    }

    public function testShownWhenNonzeroWithViewOnly(): void
    {
        $strip = $this->gridStrip(
            ['enabled' => 2, 'total' => 4],
            ['MageOS_Workflows::view' => true, 'MageOS_Workflows::manage' => false]
        );

        $this->assertTrue($strip->shouldRender('sales_order'));
        $this->assertFalse($strip->canManage());
        $this->assertSame(2, $strip->getEnabledCount('sales_order'));
        $this->assertSame(4, $strip->getTotalCount('sales_order'));
    }

    public function testViewUrlCarriesFiltersModifierForEntityType(): void
    {
        $url = new RecordingUrlBuilder();
        $strip = $this->gridStrip(
            ['enabled' => 1, 'total' => 1],
            ['MageOS_Workflows::view' => true],
            true,
            $url
        );

        $strip->getViewUrl('sales_order');

        $call = $url->calls[0];
        $this->assertSame('mageos_workflows/workflow/index', $call['route']);
        $modifier = $call['params']['filters_modifier']['entity_type'];
        $this->assertSame('eq', $modifier['condition_type']);
        $this->assertSame('sales_order', $modifier['value']);
    }

    public function testCreateUrlPassesEntityTypeParam(): void
    {
        $url = new RecordingUrlBuilder();
        $strip = $this->gridStrip(
            ['enabled' => 0, 'total' => 0],
            ['MageOS_Workflows::view' => true, 'MageOS_Workflows::manage' => true],
            true,
            $url
        );

        $strip->getCreateUrl('customer');

        $call = $url->calls[0];
        $this->assertSame('mageos_workflows/workflow/edit', $call['route']);
        $this->assertSame('customer', $call['params']['entity_type']);
    }

    public function testEntityLabelFromCatalogueWithCodeFallback(): void
    {
        $strip = $this->gridStrip(
            ['enabled' => 1, 'total' => 1],
            ['MageOS_Workflows::view' => true]
        );

        $this->assertSame('Orders', $strip->getEntityLabel('sales_order'));
        $this->assertSame('unknown_type', $strip->getEntityLabel('unknown_type'), 'Unknown code falls back to itself');
    }
}
