<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Webapi;

use Magento\Framework\DataObject;
use MageOS\Workflows\Api\RelationInterface;
use MageOS\Workflows\Model\Relation\RelationPool;
use MageOS\Workflows\Model\Webapi\RelationMetadataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GET /V1/workflows/meta/relations projects the pool to metadata DTOs.
 */
class RelationMetadataProviderTest extends TestCase
{
    private function relation(string $code, string $source, string $target, string $card): RelationInterface
    {
        return new class ($code, $source, $target, $card) implements RelationInterface {
            public function __construct(
                private readonly string $code,
                private readonly string $source,
                private readonly string $target,
                private readonly string $card
            ) {
            }

            public function getCode(): string
            {
                return $this->code;
            }

            public function getLabel(): string
            {
                return 'Label ' . $this->code;
            }

            public function getSourceEntityType(): string
            {
                return $this->source;
            }

            public function getTargetEntityType(): string
            {
                return $this->target;
            }

            public function getCardinality(): string
            {
                return $this->card;
            }

            public function resolveIds(DataObject $source, ?int $websiteId): array
            {
                return [];
            }
        };
    }

    public function testListsEveryRegisteredRelationAsMetadata(): void
    {
        $pool = new RelationPool([
            'order.customer_by_email' => $this->relation('order.customer_by_email', 'sales_order', 'customer', 'one'),
            'customer.open_orders' => $this->relation('customer.open_orders', 'customer', 'sales_order', 'many'),
        ]);

        $relations = (new RelationMetadataProvider($pool))->getRelations();

        $this->assertCount(2, $relations);
        $this->assertSame('order.customer_by_email', $relations[0]->getCode());
        $this->assertSame('Label order.customer_by_email', $relations[0]->getLabel());
        $this->assertSame('sales_order', $relations[0]->getSourceEntityType());
        $this->assertSame('customer', $relations[0]->getTargetEntityType());
        $this->assertSame('one', $relations[0]->getCardinality());
        $this->assertSame('many', $relations[1]->getCardinality());
    }

    public function testEmptyPoolYieldsEmptyList(): void
    {
        $this->assertSame([], (new RelationMetadataProvider(new RelationPool([])))->getRelations());
    }
}
