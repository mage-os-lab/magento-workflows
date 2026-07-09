<?php
declare(strict_types=1);

namespace MageOS\WorkflowsWishlist\Test\Unit\Model\Rule\Hydrator;

use MageOS\WorkflowsScheduler\Test\Unit\Stub\FakeResourceConnection;
use MageOS\WorkflowsWishlist\Model\Rule\Hydrator\CustomerWishlistAggregateProvider;
use MageOS\WorkflowsWishlist\Test\Unit\Stub\FakeWishlistDb;
use PHPUnit\Framework\TestCase;

/**
 * CUS-C3 (wishlist half): CustomerWishlistAggregateProvider contributes
 * wishlist_items_count to the customer root. Unlike the order-history / birthday
 * aggregates, the count is ALWAYS PRESENT with a real value — a customer with no
 * wishlist genuinely has zero items, a knowable 0 that must MATCH
 * "wishlist_items_count = 0" (not be an absent, negative-only key). Pins that
 * contract, the count math, and the metadata.
 */
class CustomerWishlistAggregateProviderTest extends TestCase
{
    private function provider(FakeWishlistDb $db): CustomerWishlistAggregateProvider
    {
        return new CustomerWishlistAggregateProvider(new FakeResourceConnection($db));
    }

    public function testMetadataAdvertisesNumericCount(): void
    {
        $metadata = $this->provider(new FakeWishlistDb())->getAttributeMetadata();

        $this->assertArrayHasKey('wishlist_items_count', $metadata);
        $this->assertSame('numeric', $metadata['wishlist_items_count']['input_type']);
        $this->assertSame('Wishlist Items Count', $metadata['wishlist_items_count']['label']);
    }

    public function testCountsAllWishlistItemsForTheCustomer(): void
    {
        $db = new FakeWishlistDb();
        $db->addWishlistItem(customerId: 42, productId: 1);
        $db->addWishlistItem(customerId: 42, productId: 2);
        $db->addWishlistItem(customerId: 42, productId: 3);
        $db->addWishlistItem(customerId: 99, productId: 1); // other customer

        $aggregates = $this->provider($db)->getAggregates(42);

        $this->assertSame(['wishlist_items_count' => 3], $aggregates);
    }

    public function testCustomerWithNoWishlistIsPresentZeroNotAbsent(): void
    {
        $db = new FakeWishlistDb();
        $db->addWishlistItem(customerId: 99, productId: 1);

        $aggregates = $this->provider($db)->getAggregates(42);

        // Present, real 0 — so "= 0" matches. Absence would wrongly skip it.
        $this->assertArrayHasKey('wishlist_items_count', $aggregates);
        $this->assertSame(0, $aggregates['wishlist_items_count']);
    }

    public function testNonPositiveCustomerIdYieldsNoAggregatesWithoutQuerying(): void
    {
        $connection = new FakeResourceConnection(); // getConnection() throws if called
        $provider = new CustomerWishlistAggregateProvider($connection);

        $this->assertSame([], $provider->getAggregates(0));
        $this->assertSame(0, $connection->getConnectionCalls);
    }
}
