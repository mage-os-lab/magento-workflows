<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Ui\Component\Listing\Column;

use MageOS\Workflows\Api\EntityTypeMetadataProviderInterface;
use MageOS\Workflows\Model\Webapi\EntityTypeMetadata;
use MageOS\WorkflowsAdminUi\Model\Source\EntityType;
use MageOS\WorkflowsApprovals\Ui\Component\Listing\Column\ApprovalEntity;
use PHPUnit\Framework\TestCase;

/**
 * The approvals grid's Entity cell renders the denormalized entity_type as its
 * catalogue LABEL ("Order #142") rather than the stored code. The package already
 * requires mage-os/workflows-admin-ui, so it shares that module's EntityType
 * option source instead of growing a second, drift-prone list.
 *
 * The fallbacks matter more than the happy path here: an approval outlives the
 * pack that defined its entity type, and the reviewer still has to be able to see
 * WHAT they are approving.
 */
class ApprovalEntityTest extends TestCase
{
    public function testKnownEntityTypeRendersItsLabel(): void
    {
        $items = $this->render([['entity_type' => 'sales_order', 'entity_id' => 142]]);

        $this->assertSame('Order #142', $items[0]['entity_id']);
    }

    public function testUnknownEntityTypeRendersItsRawCode(): void
    {
        $items = $this->render([['entity_type' => 'b2b_quote', 'entity_id' => 5]]);

        $this->assertSame('b2b_quote #5', $items[0]['entity_id']);
    }

    public function testMissingEntityTypeLeavesTheBareId(): void
    {
        $items = $this->render([['entity_id' => 5], ['entity_type' => '', 'entity_id' => 6]]);

        $this->assertSame('5', $items[0]['entity_id']);
        $this->assertSame('6', $items[1]['entity_id']);
    }

    public function testADataSourceWithoutItemsIsReturnedUntouched(): void
    {
        $column = $this->column();

        $this->assertSame(['data' => []], $column->prepareDataSource(['data' => []]));
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

    private function column(): ApprovalEntity
    {
        $provider = new class implements EntityTypeMetadataProviderInterface {
            public function getEntityTypes(): array
            {
                return [new EntityTypeMetadata('sales_order', 'Order')];
            }
        };

        $column = new class extends ApprovalEntity {
            public function __construct()
            {
            }
        };
        $property = new \ReflectionProperty(ApprovalEntity::class, 'entityTypeSource');
        $property->setValue($column, new EntityType($provider));
        $column->setData('name', 'entity_id');

        return $column;
    }
}
