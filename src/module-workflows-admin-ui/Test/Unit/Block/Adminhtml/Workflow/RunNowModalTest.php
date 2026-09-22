<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Test\Unit\Block\Adminhtml\Workflow;

use MageOS\Workflows\Api\RecentEntityProviderInterface;
use MageOS\Workflows\Model\DryRun\RecentEntityProviderPool;
use MageOS\WorkflowsAdminUi\Block\Adminhtml\Workflow\RunNowModal;
use MageOS\WorkflowsAdminUi\Test\Unit\Stub\WorkflowStub;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

/**
 * Pins the "Run Now" modal block: the two gates it shares with RunNowButton
 * (a saved workflow + the manual-run ACL), the recent-entity projection, and
 * the run URL shape the JS appends 'entity_id/<n>/' to.
 *
 * RunNowModal extends Backend\Block\Template, whose real constructor is
 * layout-heavy and unavailable here, so the block is built as an anonymous
 * subclass with a no-op constructor overriding the one inherited helper these
 * methods call (getUrl), with its own promoted dependencies injected by
 * reflection — the posture InstallWidgetMappingTest documents. The pool is the
 * REAL RecentEntityProviderPool with a hand-written provider: it is a plain
 * array-configured collaborator, so doubling it would only hide the lookup.
 */
class RunNowModalTest extends TestCase
{
    public function testTheModalIsHiddenOnTheNewWorkflowForm(): void
    {
        $block = $this->block(null);

        $this->assertFalse($block->canRun());
        $this->assertSame('', $block->getRunUrl());
        $this->assertSame('', $block->getEntityType());
        $this->assertSame([], $block->getRecentEntities());
    }

    public function testTheModalIsHiddenForAnUnsavedWorkflowInTheRegistry(): void
    {
        // A workflow object with no id yet is the same case as no workflow.
        $block = $this->block(new WorkflowStub(['entity_type' => 'sales_order']));

        $this->assertFalse($block->canRun());
        $this->assertSame([], $block->getRecentEntities());
    }

    public function testTheModalIsHiddenWithoutTheManualRunAcl(): void
    {
        $block = $this->block($this->savedWorkflow(), false);

        $this->assertFalse($block->canRun());
        $this->assertSame('', $block->getRunUrl());
        $this->assertSame(
            [],
            $block->getRecentEntities(),
            'An admin who may not run the workflow may not enumerate its recent entities either.'
        );
    }

    public function testASavedWorkflowWithTheAclRendersTheModal(): void
    {
        $block = $this->block($this->savedWorkflow());

        $this->assertTrue($block->canRun());
        $this->assertSame('sales_order', $block->getEntityType());
    }

    /**
     * The URL carries the workflow id and the secret key and STOPS there: the
     * JS appends 'entity_id/<n>/' as a further path segment.
     */
    public function testTheRunUrlOmitsTheEntityIdAndEndsInASlash(): void
    {
        $url = $this->block($this->savedWorkflow())->getRunUrl();

        $this->assertSame('http://example.com/admin/mageos_workflows/workflow/run/workflow_id/7/key/abc/', $url);
        $this->assertStringNotContainsString('entity_id', $url);
        $this->assertSame('/', substr($url, -1), 'The JS concatenates path segments straight onto this.');
    }

    public function testRecentEntitiesAreProjectedFromTheSharedPool(): void
    {
        $block = $this->block($this->savedWorkflow(), true, [
            ['id' => 100000123, 'label' => 'Order #100000123 — Jane Doe'],
            ['id' => 100000122, 'label' => 'Order #100000122 — John Roe'],
        ]);

        $this->assertSame(
            [
                ['id' => 100000123, 'label' => 'Order #100000123 — Jane Doe'],
                ['id' => 100000122, 'label' => 'Order #100000122 — John Roe'],
            ],
            $block->getRecentEntities()
        );
    }

    public function testUnusableRowsAreDroppedAndBlankLabelsFallBackToTheId(): void
    {
        $block = $this->block($this->savedWorkflow(), true, [
            ['id' => 0, 'label' => 'never happened'],
            ['id' => -5, 'label' => 'nor this'],
            ['id' => 42, 'label' => '   '],
            ['id' => '43', 'label' => '  Order #43  '],
        ]);

        $this->assertSame(
            [
                ['id' => 42, 'label' => '42'],
                ['id' => 43, 'label' => 'Order #43'],
            ],
            $block->getRecentEntities()
        );
    }

    /**
     * An entity type with no registered provider is the common case (the picker
     * is a convenience): the modal still renders, with manual id entry only.
     */
    public function testAnEntityTypeWithNoProviderYieldsAnEmptyPickerButStillRuns(): void
    {
        $block = $this->block(new WorkflowStub(['workflow_id' => 7, 'entity_type' => 'customer']), true, [
            ['id' => 1, 'label' => 'Order #1'],
        ]);

        $this->assertTrue($block->canRun());
        $this->assertSame([], $block->getRecentEntities());
    }

    private function savedWorkflow(): WorkflowStub
    {
        return new WorkflowStub(['workflow_id' => 7, 'entity_type' => 'sales_order']);
    }

    /**
     * @param array<int, array<string, mixed>> $recent rows the sales_order
     *        provider returns
     */
    private function block(mixed $workflow, bool $allowed = true, array $recent = []): RunNowModal
    {
        $block = new class extends RunNowModal {
            // Skip the layout-heavy Template constructor entirely.
            public function __construct()
            {
            }

            public function getUrl($route = '', $params = [])
            {
                $url = 'http://example.com/admin/' . $route;
                foreach ($params as $key => $value) {
                    $url .= '/' . $key . '/' . $value;
                }
                // The URL builder appends the adminhtml secret key last.
                return $url . '/key/abc/';
            }
        };

        $registry = new Registry();
        if ($workflow !== null) {
            $registry->register('mageos_current_workflow', $workflow);
        }

        $this->inject($block, 'coreRegistry', $registry);
        $this->inject($block, 'authorization', new class ($allowed) implements AuthorizationInterface {
            public function __construct(private readonly bool $allowed)
            {
            }

            public function isAllowed($resource, $privilege = null)
            {
                return $resource === RunNowModal::ACL_MANUAL_RUN && $this->allowed;
            }
        });
        $this->inject($block, 'recentEntityProviderPool', new RecentEntityProviderPool([
            new class ($recent) implements RecentEntityProviderInterface {
                /**
                 * @param array<int, array<string, mixed>> $rows
                 */
                public function __construct(private readonly array $rows)
                {
                }

                public function getEntityType(): string
                {
                    return 'sales_order';
                }

                public function getRecent(int $limit): array
                {
                    /** @var array<int, array{id: int, label: string}> $rows */
                    $rows = array_slice($this->rows, 0, $limit);
                    return $rows;
                }
            },
        ]));

        return $block;
    }

    private function inject(object $block, string $property, object $value): void
    {
        (new \ReflectionProperty(RunNowModal::class, $property))->setValue($block, $value);
    }
}
