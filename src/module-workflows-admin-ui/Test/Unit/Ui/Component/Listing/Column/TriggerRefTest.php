<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Test\Unit\Ui\Component\Listing\Column;

use MageOS\Workflows\Model\Trigger\TriggerRegistry;
use MageOS\WorkflowsAdminUi\Ui\Component\Listing\Column\TriggerRef;
use PHPUnit\Framework\TestCase;

/**
 * The workflows grid's Trigger column is LABEL-ONLY for declared events and RAW
 * for everything else. trigger_ref is polymorphic (event name / cron expression /
 * free-form), so "unresolvable" is the normal case, not an error case — the cell
 * must never blank out.
 *
 * Column extends Magento\Ui\...\Columns\Column, whose real constructor wants a UI
 * component context, so the column is built as an anonymous subclass with a no-op
 * constructor and its own promoted dependency injected by reflection (the posture
 * InstallWidgetMappingTest documents for admin blocks).
 */
class TriggerRefTest extends TestCase
{
    public function testDeclaredEventRendersItsLabel(): void
    {
        $items = $this->render([['trigger_ref' => 'sales.order.created']]);

        $this->assertSame('Order Created', $items[0]['trigger_ref']);
    }

    public function testCronExpressionRendersRaw(): void
    {
        $items = $this->render([['trigger_ref' => '0 3 * * *']]);

        $this->assertSame('0 3 * * *', $items[0]['trigger_ref']);
    }

    public function testUndeclaredEventRendersRaw(): void
    {
        $items = $this->render([['trigger_ref' => 'connectorpack.thing.happened']]);

        $this->assertSame('connectorpack.thing.happened', $items[0]['trigger_ref']);
    }

    public function testDeclaredEventWithoutALabelFallsBackToTheEventName(): void
    {
        $items = $this->render([['trigger_ref' => 'labelless.event']]);

        $this->assertSame('labelless.event', $items[0]['trigger_ref']);
    }

    public function testMissingOrEmptyRefStaysEmpty(): void
    {
        $items = $this->render([['trigger_ref' => ''], ['workflow_id' => 7]]);

        $this->assertSame('', $items[0]['trigger_ref']);
        $this->assertSame('', $items[1]['trigger_ref']);
    }

    public function testADataSourceWithoutItemsIsReturnedUntouched(): void
    {
        $this->assertSame(['data' => []], $this->column()->prepareDataSource(['data' => []]));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function render(array $items): array
    {
        $result = $this->column()->prepareDataSource(['data' => ['items' => $items]]);

        return $result['data']['items'];
    }

    private function column(): TriggerRef
    {
        $registry = new class extends TriggerRegistry {
            public function __construct()
            {
            }

            public function getByEvent(string $event): ?array
            {
                $triggers = [
                    'sales.order.created' => [
                        'event' => 'sales.order.created',
                        'entity' => 'sales_order',
                        'label' => 'Order Created',
                    ],
                    'labelless.event' => [
                        'event' => 'labelless.event',
                        'entity' => 'sales_order',
                        'label' => '',
                    ],
                ];

                return $triggers[$event] ?? null;
            }
        };

        $column = new class extends TriggerRef {
            public function __construct()
            {
            }
        };
        $property = new \ReflectionProperty(TriggerRef::class, 'triggerRegistry');
        $property->setValue($column, $registry);
        $column->setData('name', 'trigger_ref');

        return $column;
    }
}
