<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Test\Unit\Ui\Component\Listing\Column;

use MageOS\Workflows\Api\EntityTypeMetadataProviderInterface;
use MageOS\Workflows\Model\Webapi\EntityTypeMetadata;
use MageOS\WorkflowsAdminUi\Model\Source\EntityType;
use MageOS\WorkflowsAdminUi\Ui\Component\Listing\Column\ExecutionEntity;
use PHPUnit\Framework\TestCase;

/**
 * The executions grid's Entity cell is now "<Entity-Type Label> #<id>". The
 * execution table has no entity_type column, so the type comes from the context
 * the dispatcher stamps (context.workflow.entity_type) — which this column
 * already decodes for the batch check, so the prefix costs no extra query.
 *
 * Two behaviours are pinned as much as the happy path: the batch variant
 * ("batch (N items)", never a misleading "0") survives the change, and a row
 * whose context names no/unknown entity type still renders SOMETHING.
 */
class ExecutionEntityTest extends TestCase
{
    public function testPerEntityRowRendersTheEntityTypeLabelAndId(): void
    {
        $items = $this->render([[
            'entity_id' => 142,
            'context' => $this->context('sales_order'),
        ]]);

        $this->assertSame('Order #142', $items[0]['entity_id']);
    }

    public function testBatchRowKeepsTheItemCountAndGainsTheLabel(): void
    {
        $items = $this->render([[
            'entity_id' => 0,
            'context' => json_encode([
                'trigger' => ['batch' => true, 'count' => 143],
                'workflow' => ['entity_type' => 'sales_order'],
            ]),
        ]]);

        $this->assertSame('Order batch (143 items)', $items[0]['entity_id']);
    }

    public function testUnknownEntityTypeRendersItsRawCode(): void
    {
        $items = $this->render([[
            'entity_id' => 7,
            'context' => $this->context('b2b_quote'),
        ]]);

        $this->assertSame('b2b_quote #7', $items[0]['entity_id']);
    }

    public function testContextWithoutAnEntityTypeKeepsTheBareId(): void
    {
        $items = $this->render([
            ['entity_id' => 9, 'context' => json_encode(['trigger' => []])],
            ['entity_id' => 9, 'context' => 'not json at all'],
            ['entity_id' => 9],
        ]);

        foreach ($items as $item) {
            $this->assertSame('9', $item['entity_id']);
        }
    }

    public function testANonBatchZeroEntityStaysZero(): void
    {
        $items = $this->render([[
            'entity_id' => 0,
            'context' => $this->context('sales_order'),
        ]]);

        $this->assertSame('0', $items[0]['entity_id']);
    }

    public function testAnAlreadyDecodedContextArrayIsAccepted(): void
    {
        $items = $this->render([[
            'entity_id' => 3,
            'context' => ['workflow' => ['entity_type' => 'customer']],
        ]]);

        $this->assertSame('Customer #3', $items[0]['entity_id']);
    }

    private function context(string $entityType): string
    {
        return (string) json_encode(['workflow' => ['entity_type' => $entityType]]);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function render(array $items): array
    {
        $column = new class extends ExecutionEntity {
            public function __construct()
            {
            }
        };
        $property = new \ReflectionProperty(ExecutionEntity::class, 'entityTypeSource');
        $property->setValue($column, $this->entityTypeSource());
        $column->setData('name', 'entity_id');

        $result = $column->prepareDataSource(['data' => ['items' => $items]]);

        return $result['data']['items'];
    }

    private function entityTypeSource(): EntityType
    {
        $provider = new class implements EntityTypeMetadataProviderInterface {
            public function getEntityTypes(): array
            {
                return [
                    new EntityTypeMetadata('sales_order', 'Order'),
                    new EntityTypeMetadata('customer', 'Customer'),
                ];
            }
        };

        return new EntityType($provider);
    }
}
