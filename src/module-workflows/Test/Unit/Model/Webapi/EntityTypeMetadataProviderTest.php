<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Webapi;

use MageOS\Workflows\Model\Webapi\EntityTypeMetadataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GET /V1/workflows/meta/entity-types projects the DI-registered map.
 */
class EntityTypeMetadataProviderTest extends TestCase
{
    public function testProjectsCodeLabelMap(): void
    {
        $provider = new EntityTypeMetadataProvider([
            'sales_order' => 'Order',
            'customer' => 'Customer',
        ]);

        $items = $provider->getEntityTypes();

        $this->assertCount(2, $items);
        $this->assertSame('sales_order', $items[0]->getCode());
        $this->assertSame('Order', $items[0]->getLabel());
        $this->assertSame('customer', $items[1]->getCode());
    }

    public function testEmptyMapYieldsEmptyList(): void
    {
        $this->assertSame([], (new EntityTypeMetadataProvider([]))->getEntityTypes());
    }
}
