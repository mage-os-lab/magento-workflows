<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsWishlist\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Wishlist\Model\Item;
use Magento\Wishlist\Model\Wishlist;
use MageOS\WorkflowsTriggersCore\Service\EventPublisher;
use Psr\Log\LoggerInterface;

/**
 * Gap-fill publisher: publishes 'wishlist.item_added' on the canonical
 * storefront event 'wishlist_add_product' — the event Magento\Wishlist\Controller\Index\Add
 * dispatches after a product is added to a wishlist, carrying keys
 * wishlist / product / item. The added PRODUCT is the workflow entity
 * (entity = catalog_product, hydrated async by ProductRepositoryInterface::getById);
 * the wishlisting customer and wishlist/item context ride along as extra
 * payload keys.
 *
 * GUEST WISHLISTS: Magento wishlists require a logged-in customer. The add
 * controller (Magento\Wishlist\Controller\Index\Add, guarded by the
 * customer-account LoginPost/authenticated area) resolves the wishlist through
 * WishlistProviderInterface, which loads it by the session customer id and
 * redirects anonymous visitors to login — there is NO guest wishlist in core.
 * So customer_id is expected to be > 0; we still read it defensively and never
 * bail on a missing customer, because a store may add a product to a wishlist
 * programmatically (import, admin tooling) where the customer context differs.
 * We do NOT code a separate guest path because none exists to exercise.
 *
 * STORM / DEBOUNCE: wishlist adds are storefront-frequency events (a shopper
 * can add several products in seconds, or re-add the same product). This
 * observer publishes every add faithfully and adds no ad-hoc de-dup; collapsing
 * a burst is the engine's job — the Dispatcher's atomic per-(workflow, entity)
 * debounce window (mageos_workflows/guards/debounce_window_seconds, default 60s;
 * MageOS\Workflows\Model\Engine\Dispatcher::CONFIG_DEBOUNCE_WINDOW) releases at
 * most one execution per product per window, so rapid re-adds of the same
 * product collapse to one run. Publishing failures are logged, never allowed to
 * break the wishlist save.
 */
class WishlistItemAddedObserver implements ObserverInterface
{
    public const EVENT_NAME = 'wishlist.item_added';

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
        $item = $observer->getEvent()->getData('item');
        if (!$item instanceof Item) {
            return;
        }
        $productId = (int) $item->getProductId();
        if ($productId <= 0) {
            return;
        }

        $wishlist = $observer->getEvent()->getData('wishlist');
        $customerId = $wishlist instanceof Wishlist ? (int) $wishlist->getCustomerId() : 0;
        $wishlistId = $wishlist instanceof Wishlist
            ? (int) $wishlist->getId()
            : (int) $item->getWishlistId();

        $product = $observer->getEvent()->getData('product');
        $sku = $product instanceof DataObject ? (string) $product->getData('sku') : '';

        try {
            $this->eventPublisher->publish(self::EVENT_NAME, [
                // 'productId' hydrates via ProductRepositoryInterface::getById($productId)
                'productId' => $productId,
                'entity_id' => $productId,
                'customer_id' => $customerId,
                'wishlist_id' => $wishlistId,
                'item_id' => (int) $item->getId(),
                'qty' => (float) $item->getQty(),
                'store_id' => (int) $item->getStoreId(),
                'sku' => $sku,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf(
                    'Failed to publish %s for product #%d: %s',
                    self::EVENT_NAME,
                    $productId,
                    $exception->getMessage()
                ),
                ['exception' => $exception]
            );
        }
    }
}
