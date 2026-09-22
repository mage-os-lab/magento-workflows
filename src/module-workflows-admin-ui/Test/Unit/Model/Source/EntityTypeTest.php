<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Test\Unit\Model\Source;

use MageOS\Workflows\Api\EntityTypeMetadataProviderInterface;
use MageOS\Workflows\Model\Webapi\EntityTypeMetadata;
use MageOS\WorkflowsAdminUi\Model\Source\EntityType;
use PHPUnit\Framework\TestCase;

/**
 * EntityType option source is now re-based onto the core catalogue
 * (EntityTypeMetadataProviderInterface): each metadata entry becomes one
 * {value: code, label: __(label)} option, in provider order.
 */
class EntityTypeTest extends TestCase
{
    private function provider(EntityTypeMetadata ...$entityTypes): EntityTypeMetadataProviderInterface
    {
        return new class($entityTypes) implements EntityTypeMetadataProviderInterface {
            /**
             * @param EntityTypeMetadata[] $entityTypes
             */
            public function __construct(private array $entityTypes)
            {
            }

            public function getEntityTypes(): array
            {
                return $this->entityTypes;
            }
        };
    }

    public function testMapsProviderMetadataToOptionArrays(): void
    {
        $source = new EntityType($this->provider(
            new EntityTypeMetadata('sales_order', 'Order'),
            new EntityTypeMetadata('customer', 'Customer'),
        ));

        $options = $source->toOptionArray();

        $this->assertCount(2, $options);
        $this->assertSame('sales_order', $options[0]['value']);
        $this->assertSame('Order', (string) $options[0]['label']);
        $this->assertSame('customer', $options[1]['value']);
        $this->assertSame('Customer', (string) $options[1]['label']);
    }

    public function testEmptyCatalogueYieldsNoOptions(): void
    {
        $source = new EntityType($this->provider());

        $this->assertSame([], $source->toOptionArray());
    }
}
