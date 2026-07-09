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
 * Gap-fill publisher for the product-lifecycle async events, declared in
 * etc/async_events.xml and metadata'd in etc/workflow_triggers.xml. ONE
 * observer on 'catalog_product_save_after' distinguishes creation from update
 * and, for existing products, additionally emits the finer-grained price and
 * status transition events (PRD-T1/T2/T3 share this observer family — one
 * save is the single source of truth for all four events, so orig-vs-new is
 * read once).
 *
 * Emission rules from a single save:
 *   - NEW product (isObjectNew / no original entity_id): publish
 *     catalog.product.created and nothing else — creation subsumes the
 *     price/status "transitions" (there is no prior value to transition from),
 *     mirroring OrderStatusChangeObserver excluding brand-new orders.
 *   - EXISTING product: publish catalog.product.updated when the save actually
 *     changed data (hasDataChanges() true); a no-op re-save (hasDataChanges()
 *     false — cheaply detectable on the model) does NOT fire updated, so
 *     action re-saves and idempotent re-persists don't generate update noise.
 *     Independently, catalog.product.price_changed fires when orig `price` !=
 *     new `price`, and catalog.product.status_changed fires when orig `status`
 *     != new `status`. These carry their own orig-vs-new guards, so they never
 *     fire on a no-op save regardless of the hasDataChanges() gate.
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
 * UPSTREAM ASSUMPTION (VER-1 audit blocked — upstream repo not accessible):
 * these declarations assume `mageos-common-async-events` does NOT declare
 * catalog.product.created / catalog.product.updated (its coverage is
 * sales/customer documents). If upstream later declares these events, our
 * gap-fill declarations (etc/async_events.xml + this observer's created/updated
 * publishes) COLLIDE and ours should be dropped in favor of upstream's
 * metadata-only trigger entries; price_changed/status_changed have no upstream
 * equivalent and stay.
 */
class ProductSaveObserver implements ObserverInterface
{
    public const EVENT_CREATED = 'catalog.product.created';
    public const EVENT_UPDATED = 'catalog.product.updated';
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
            // Creation subsumes the price/status transitions.
            $this->safePublish(self::EVENT_CREATED, [
                // 'productId' hydrates via ProductRepositoryInterface::getById($productId)
                'productId' => $productId,
                'entity_id' => $productId,
                'sku' => $sku,
                'type_id' => (string) $product->getTypeId(),
                'store_id' => $storeId,
            ], $productId);
            return;
        }

        if ($product->hasDataChanges()) {
            $this->safePublish(self::EVENT_UPDATED, [
                'productId' => $productId,
                'entity_id' => $productId,
                'sku' => $sku,
                'type_id' => (string) $product->getTypeId(),
                'store_id' => $storeId,
            ], $productId);
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
