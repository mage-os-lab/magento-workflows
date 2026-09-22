<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsWishlist\Test\Unit\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use MageOS\WorkflowsWishlist\Observer\WishlistItemAddedObserver;
use MageOS\WorkflowsWishlist\Test\Unit\Stub\FakeWishlist;
use MageOS\WorkflowsWishlist\Test\Unit\Stub\FakeWishlistItem;
use MageOS\WorkflowsWishlist\Test\Unit\Stub\RecordingEventPublisher;
use MageOS\WorkflowsWishlist\Test\Unit\Stub\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Pins the WSH-T1 contract: adding a product to a wishlist publishes
 * 'wishlist.item_added' with the product as the workflow entity and the
 * wishlisting customer + wishlist/item context as extras. The event never
 * fires without a valid product on the item, and publish failures are logged
 * rather than rethrown into the wishlist save.
 */
class WishlistItemAddedObserverTest extends TestCase
{
    private function observerEvent(array $eventData): Observer
    {
        return new Observer(['event' => new Event($eventData)]);
    }

    public function testFiresWithProductAndCustomerContext(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new WishlistItemAddedObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent([
            'item' => new FakeWishlistItem(itemId: 88, productId: 55, wishlistId: 7, qty: 2.0, storeId: 3),
            'wishlist' => new FakeWishlist(wishlistId: 7, customerId: 42),
            'product' => new DataObject(['sku' => 'WIDGET-1']),
        ]));

        $this->assertCount(1, $publisher->published);
        $payload = $publisher->published[0];
        $this->assertSame('wishlist.item_added', $payload['event']);
        // Product hydration key + entity id.
        $this->assertSame(55, $payload['data']['productId']);
        $this->assertSame(55, $payload['data']['entity_id']);
        // Customer + wishlist/item context.
        $this->assertSame(42, $payload['data']['customer_id']);
        $this->assertSame(7, $payload['data']['wishlist_id']);
        $this->assertSame(88, $payload['data']['item_id']);
        $this->assertSame(2.0, $payload['data']['qty']);
        $this->assertSame(3, $payload['data']['store_id']);
        $this->assertSame('WIDGET-1', $payload['data']['sku']);
    }

    public function testWishlistIdFallsBackToItemWhenWishlistMissing(): void
    {
        // No 'wishlist' key: customer_id is unknown (0) and wishlist_id comes
        // from the item. Core never omits it, but the observer degrades cleanly.
        $publisher = new RecordingEventPublisher();
        $observer = new WishlistItemAddedObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent([
            'item' => new FakeWishlistItem(itemId: 88, productId: 55, wishlistId: 9),
        ]));

        $this->assertCount(1, $publisher->published);
        $this->assertSame(0, $publisher->published[0]['data']['customer_id']);
        $this->assertSame(9, $publisher->published[0]['data']['wishlist_id']);
        $this->assertSame('', $publisher->published[0]['data']['sku']);
    }

    public function testDoesNotFireWithoutItemInEvent(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new WishlistItemAddedObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent(['wishlist' => new FakeWishlist(7, 42)]));

        $this->assertCount(0, $publisher->published);
    }

    public function testDoesNotFireWithoutProductId(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new WishlistItemAddedObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent([
            'item' => new FakeWishlistItem(itemId: 88, productId: 0),
            'wishlist' => new FakeWishlist(7, 42),
        ]));

        $this->assertCount(0, $publisher->published);
    }

    public function testGuestlikeRowWithoutCustomerStillPublishesWithZeroCustomer(): void
    {
        // Magento has no guest wishlists (add requires login); a programmatic
        // row with customer_id 0 must still publish (product is the entity),
        // carrying customer_id 0 rather than being silently dropped.
        $publisher = new RecordingEventPublisher();
        $observer = new WishlistItemAddedObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent([
            'item' => new FakeWishlistItem(itemId: 1, productId: 55, wishlistId: 7),
            'wishlist' => new FakeWishlist(wishlistId: 7, customerId: 0),
        ]));

        $this->assertCount(1, $publisher->published);
        $this->assertSame(0, $publisher->published[0]['data']['customer_id']);
    }

    public function testPublishFailureIsLoggedAndDoesNotBreakWishlistSave(): void
    {
        $publisher = new RecordingEventPublisher(new \RuntimeException('amqp connection refused'));
        $logger = new RecordingLogger();
        $observer = new WishlistItemAddedObserver($publisher, $logger);

        // Must not throw.
        $observer->execute($this->observerEvent([
            'item' => new FakeWishlistItem(itemId: 88, productId: 55, wishlistId: 7),
            'wishlist' => new FakeWishlist(7, 42),
        ]));

        $this->assertStringContainsString('error:', $logger->allMessages());
        $this->assertStringContainsString('wishlist.item_added', $logger->allMessages());
        $this->assertStringContainsString('amqp connection refused', $logger->allMessages());
    }
}
