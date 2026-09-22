<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsWishlist\Test\Unit\Model\Relation\Resolver;

use Magento\Framework\DataObject;
use MageOS\Workflows\Api\RelationInterface;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\FakeResourceConnection;
use MageOS\WorkflowsWishlist\Model\Relation\Resolver\ProductWishlistedCustomers;
use MageOS\WorkflowsWishlist\Test\Unit\Stub\FakeWishlistDb;
use PHPUnit\Framework\TestCase;

/**
 * WSH-C1: product.wishlisted_customers resolves the customers who hold a
 * product in a wishlist (the fan-out set for price-drop / back-in-stock). Pins
 * the resolveIds shape, dedupe of a customer with the product in several
 * wishlists, guest/empty-row exclusion, the empty-set edges, and the relation
 * metadata (source product, target customer, cardinality many). The cap is the
 * RelationContext's job (covered in the engine's RelationContextTest); the
 * resolver deliberately returns the full set for the context to bound.
 */
class ProductWishlistedCustomersTest extends TestCase
{
    private function resolver(FakeWishlistDb $db): ProductWishlistedCustomers
    {
        return new ProductWishlistedCustomers(new FakeResourceConnection($db));
    }

    private function product(int $productId): DataObject
    {
        return new DataObject(['entity_id' => $productId]);
    }

    public function testMetadataDeclaresProductToCustomerManyRelation(): void
    {
        $resolver = $this->resolver(new FakeWishlistDb());

        $this->assertSame('product.wishlisted_customers', $resolver->getCode());
        $this->assertSame(HydrationProviderInterface::TYPE_PRODUCT, $resolver->getSourceEntityType());
        $this->assertSame(HydrationProviderInterface::TYPE_CUSTOMER, $resolver->getTargetEntityType());
        $this->assertSame(RelationInterface::CARDINALITY_MANY, $resolver->getCardinality());
    }

    public function testResolvesCustomersWhoWishlistedTheProduct(): void
    {
        $db = new FakeWishlistDb();
        $db->addWishlistItem(customerId: 10, productId: 55);
        $db->addWishlistItem(customerId: 20, productId: 55);
        $db->addWishlistItem(customerId: 30, productId: 99); // other product

        $ids = $this->resolver($db)->resolveIds($this->product(55), null);

        $this->assertSame([10, 20], $ids);
    }

    public function testDedupesCustomerWithProductInMultipleWishlistItems(): void
    {
        $db = new FakeWishlistDb();
        $db->addWishlistItem(customerId: 10, productId: 55, storeId: 1);
        $db->addWishlistItem(customerId: 10, productId: 55, storeId: 2);

        $ids = $this->resolver($db)->resolveIds($this->product(55), null);

        $this->assertSame([10], $ids);
    }

    public function testExcludesGuestOrCustomerlessRows(): void
    {
        $db = new FakeWishlistDb();
        $db->addWishlistItem(customerId: 0, productId: 55);
        $db->addWishlistItem(customerId: 15, productId: 55);

        $ids = $this->resolver($db)->resolveIds($this->product(55), null);

        $this->assertSame([15], $ids);
    }

    public function testNoWishlistsForProductYieldsEmpty(): void
    {
        $db = new FakeWishlistDb();
        $db->addWishlistItem(customerId: 10, productId: 99);

        $this->assertSame([], $this->resolver($db)->resolveIds($this->product(55), null));
    }

    public function testNonPositiveProductIdYieldsEmptyWithoutQuerying(): void
    {
        // A source with no product id must not touch the database at all.
        $connection = new FakeResourceConnection(); // getConnection() throws if called
        $resolver = new ProductWishlistedCustomers($connection);

        $this->assertSame([], $resolver->resolveIds(new DataObject(['entity_id' => 0]), null));
        $this->assertSame(0, $connection->getConnectionCalls);
    }

    public function testReadsProductIdFromProductIdKeyFallback(): void
    {
        $db = new FakeWishlistDb();
        $db->addWishlistItem(customerId: 10, productId: 55);

        $ids = $this->resolver($db)->resolveIds(new DataObject(['product_id' => 55]), null);

        $this->assertSame([10], $ids);
    }
}
