<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCatalog\Observer;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\WorkflowsTriggersCore\Service\EventPublisher;
use Psr\Log\LoggerInterface;

/**
 * Gap-fill publisher for the product price/status transition async events,
 * declared in etc/async_events.xml and metadata'd in etc/workflow_triggers.xml
 * (PRD-T2/T3). ONE observer on 'catalog_product_save_after' emits the
 * finer-grained transition events for existing products; orig-vs-new is read
 * once.
 *
 * Emission rules from a single save:
 *   - NEW product (no original entity_id): publish NOTHING — creation is
 *     upstream's catalog.product.created, and there is no prior value to
 *     transition from.
 *   - EXISTING product: catalog.product.price_changed fires when orig `price`
 *     != new `price`, and catalog.product.status_changed fires when orig
 *     `status` != new `status`. Both carry their own orig-vs-new guards, so
 *     they never fire on a no-op save.
 *
 * Scoped saves: the payload reports the save's own store scope
 * ($product->getStoreId()) as store_id. A website/store-scoped price or status
 * override save therefore fires price_changed / status_changed with that
 * non-default store_id — scoped price changes fire with the scope they were
 * written in, and subscribers can branch on store_id. special_price windows
 * are deliberately NOT this event: scheduled workflows + relative-date
 * conditions already express "special price active from X to Y" (core-coverage
 * §Catalog "special-price windows & new-from dates = scheduled trigger +
 * relative-date conditions"), so price_changed tracks only the base `price`.
 *
 * Loop-guard / debounce interaction: publishing rides
 * 'catalog_product_save_after' with no additional flag table — the per-event
 * orig-vs-new guards (and hasDataChanges() for updated) are themselves the
 * debounce, and the engine's per-(workflow_id, entity_id, time_bucket) atomic
 * insert (Dispatcher) collapses any duplicate downstream. Import storm: a bulk
 * import or mass-action fires 'catalog_product_save_after' per row at volume,
 * so these events would detonate a per-entity engine. That is handled OUTSIDE
 * this observer by the optional `workflows-import-suppression` module
 * (WorkflowSuppression::scope(callable) + honored bin/magento flag + config
 * toggle for known bulk paths like `catalog_product_import`) which suppresses
 * dispatch for the storm; aggregate triggers turn the same storm into a
 * fire-once-per-batch feature (docs/07-actions.md "Bulk-operation
 * suppression"). Publishing failures are logged and never break the save.
 *
 * VER-1 reconciliation (July 2026, audited against mageos-common-async-events):
 * upstream DOES declare and publish catalog.product.created and
 * catalog.product.updated (ProductSaveAfterObserver on
 * 'catalog_product_save_commit_after'), so this observer's created/updated
 * publishes and declarations were removed — those two triggers are now
 * metadata-only over the upstream events. Upstream quirk worth knowing:
 * upstream fires BOTH created and updated for a brand-new product (its
 * updated check is hasDataChanges() with no created-exclusion), so "Product
 * Updated" workflows also run at creation; guard with a condition when that
 * matters. price_changed/status_changed have no upstream equivalent and stay
 * gap-filled here.
 */
class ProductSaveObserver implements ObserverInterface
{
    public const EVENT_PRICE_CHANGED = 'catalog.product.price_changed';
    public const EVENT_STATUS_CHANGED = 'catalog.product.status_changed';

    public function __construct(
        private readonly EventPublisher $eventPublisher,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getData('product');
        if (!$product instanceof Product || !$product->getId()) {
            return;
        }

        $productId = (int) $product->getId();
        $storeId = (int) $product->getStoreId();
        $sku = (string) $product->getSku();

        if ($this->isNew($product)) {
            // Creation is upstream's catalog.product.created; nothing to do here.
            return;
        }

        $this->maybePublishPriceChanged($product, $productId, $sku, $storeId);
        $this->maybePublishStatusChanged($product, $productId, $sku, $storeId);
    }

    private function maybePublishPriceChanged(Product $product, int $productId, string $sku, int $storeId): void
    {
        $origPrice = $product->getOrigData('price');
        if ($origPrice === null) {
            return;
        }
        $from = (float) $origPrice;
        $to = (float) $product->getData('price');
        if ($from === $to) {
            return;
        }
        $this->safePublish(self::EVENT_PRICE_CHANGED, [
            'productId' => $productId,
            'entity_id' => $productId,
            'sku' => $sku,
            'from_price' => $from,
            'to_price' => $to,
            'store_id' => $storeId,
        ], $productId);
    }

    private function maybePublishStatusChanged(Product $product, int $productId, string $sku, int $storeId): void
    {
        $origStatus = $product->getOrigData('status');
        if ($origStatus === null) {
            return;
        }
        $from = (int) $origStatus;
        $to = (int) $product->getStatus();
        if ($from === $to) {
            return;
        }
        $this->safePublish(self::EVENT_STATUS_CHANGED, [
            'productId' => $productId,
            'entity_id' => $productId,
            'sku' => $sku,
            'from_status' => $this->statusLabel($from),
            'to_status' => $this->statusLabel($to),
            'store_id' => $storeId,
        ], $productId);
    }

    /**
     * New iff the model flags itself new or carries no original identity yet.
     */
    private function isNew(Product $product): bool
    {
        return $product->isObjectNew() || $product->getOrigData('entity_id') === null;
    }

    private function statusLabel(int $status): string
    {
        return $status === Status::STATUS_ENABLED ? 'enabled' : 'disabled';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function safePublish(string $eventName, array $data, int $productId): void
    {
        try {
            $this->eventPublisher->publish($eventName, $data);
        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf(
                    'Failed to publish %s for product #%d: %s',
                    $eventName,
                    $productId,
                    $exception->getMessage()
                ),
                ['exception' => $exception]
            );
        }
    }
}
