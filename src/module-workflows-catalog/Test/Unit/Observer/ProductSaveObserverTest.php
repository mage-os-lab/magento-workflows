<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsCatalog\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use MageOS\WorkflowsCatalog\Observer\ProductSaveObserver;
use MageOS\WorkflowsCatalog\Test\Unit\Stub\FakeProduct;
use MageOS\WorkflowsCatalog\Test\Unit\Stub\RecordingEventPublisher;
use MageOS\WorkflowsCatalog\Test\Unit\Stub\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Pins the product-lifecycle emission contract (PRD-T1/T2/T3): a single
 * catalog_product_save_after publishes nothing for new products (created/
 * updated are upstream events, VER-1) and independently
 * emits price_changed / status_changed on an orig-vs-new base-price / status
 * transition — reporting the save's own store scope. No-op re-saves stay
 * silent; publish failures are logged, never rethrown into the save.
 */
class ProductSaveObserverTest extends TestCase
{
    private function observerEvent(mixed $product): Observer
    {
        return new Observer(['event' => new Event($product === null ? [] : ['product' => $product])]);
    }

    private function existing(array $data, array $orig, bool $hasChanges = true, int $storeId = 0): FakeProduct
    {
        return new FakeProduct(
            id: 42,
            sku: 'HAT-1',
            data: $data,
            origData: ['entity_id' => 42] + $orig,
            isNew: false,
            hasChanges: $hasChanges,
            storeId: $storeId
        );
    }

    public function testNewProductPublishesNothing(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ProductSaveObserver($publisher, new RecordingLogger());

        $product = new FakeProduct(
            id: 7,
            sku: 'NEW-1',
            data: ['type_id' => 'configurable', 'price' => 20.0, 'status' => 1],
            origData: [],
            isNew: true,
            storeId: 3
        );
        $observer->execute($this->observerEvent($product));

        $this->assertSame([], $publisher->eventNames());
    }

    public function testExistingProductWithNonTransitionChangesIsSilent(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ProductSaveObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent(
            $this->existing(['price' => 10.0, 'status' => 1], ['price' => 10.0, 'status' => 1])
        ));

        $this->assertSame([], $publisher->eventNames());
    }

    public function testNoOpSaveIsSilent(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ProductSaveObserver($publisher, new RecordingLogger());

        // hasDataChanges() false and no price/status transition.
        $observer->execute($this->observerEvent(
            $this->existing(['price' => 10.0, 'status' => 1], ['price' => 10.0, 'status' => 1], hasChanges: false)
        ));

        $this->assertCount(0, $publisher->published);
    }

    public function testPriceChangeFiresPriceChanged(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ProductSaveObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent(
            $this->existing(['price' => 8.5, 'status' => 1], ['price' => 10.0, 'status' => 1], storeId: 2)
        ));

        $this->assertSame(
            ['catalog.product.price_changed'],
            $publisher->eventNames()
        );
        $priceEvent = $publisher->only('catalog.product.price_changed')[0]['data'];
        $this->assertSame(10.0, $priceEvent['from_price']);
        $this->assertSame(8.5, $priceEvent['to_price']);
        $this->assertSame(2, $priceEvent['store_id'], 'scoped price change reports the save store scope');
        $this->assertSame('HAT-1', $priceEvent['sku']);
    }

    public function testEqualPriceDoesNotFirePriceChanged(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ProductSaveObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent(
            $this->existing(['price' => 10.0, 'status' => 1], ['price' => 10.0, 'status' => 1])
        ));

        $this->assertCount(0, $publisher->only('catalog.product.price_changed'));
    }

    public function testStatusChangeFiresStatusChangedWithLabels(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ProductSaveObserver($publisher, new RecordingLogger());

        // status 1 (enabled) -> 2 (disabled)
        $observer->execute($this->observerEvent(
            $this->existing(['price' => 10.0, 'status' => 2], ['price' => 10.0, 'status' => 1])
        ));

        $statusEvent = $publisher->only('catalog.product.status_changed')[0]['data'];
        $this->assertSame('enabled', $statusEvent['from_status']);
        $this->assertSame('disabled', $statusEvent['to_status']);
    }

    public function testPriceAndStatusChangeFireBothTransitions(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ProductSaveObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent(
            $this->existing(['price' => 5.0, 'status' => 2], ['price' => 9.0, 'status' => 1])
        ));

        $this->assertSame(
            [
                'catalog.product.price_changed',
                'catalog.product.status_changed',
            ],
            $publisher->eventNames()
        );
    }

    public function testNewProductNeverFiresPriceOrStatusTransitions(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ProductSaveObserver($publisher, new RecordingLogger());

        $product = new FakeProduct(
            id: 7,
            sku: 'NEW-1',
            data: ['price' => 20.0, 'status' => 1],
            origData: [],
            isNew: true
        );
        $observer->execute($this->observerEvent($product));

        $this->assertCount(0, $publisher->only('catalog.product.price_changed'));
        $this->assertCount(0, $publisher->only('catalog.product.status_changed'));
    }

    public function testNoProductInEventIsIgnored(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ProductSaveObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent(null));

        $this->assertCount(0, $publisher->published);
    }

    public function testPublishFailureIsLoggedAndDoesNotBreakSave(): void
    {
        $publisher = new RecordingEventPublisher(throwsFor: ProductSaveObserver::EVENT_PRICE_CHANGED);
        $logger = new RecordingLogger();
        $observer = new ProductSaveObserver($publisher, $logger);

        // price_changed publish throws; status_changed must still fire afterward.
        $observer->execute($this->observerEvent(
            $this->existing(['price' => 8.0, 'status' => 2], ['price' => 10.0, 'status' => 1])
        ));

        $this->assertStringContainsString('error:', $logger->allMessages());
        $this->assertStringContainsString('catalog.product.price_changed', $logger->allMessages());
        $this->assertCount(1, $publisher->only('catalog.product.status_changed'));
    }
}
