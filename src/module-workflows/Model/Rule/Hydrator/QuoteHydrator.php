<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule\Hydrator;

use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\CartItemInterface;
use Magento\Quote\Model\Quote;

/**
 * quote hydrator: flat quote data enriched with `items` (full flat item
 * data — sku, name, qty, price, product_id, ...) and minimal flat address
 * basics (billing_country, billing_postcode, shipping_country,
 * shipping_postcode) — mirroring the trigger snapshot shape.
 */
class QuoteHydrator implements EntityHydratorInterface
{
    public function __construct(
        private readonly CartRepositoryInterface $cartRepository,
        private readonly EntityDataConverter $dataConverter,
        private readonly DataObjectFactory $dataObjectFactory
    ) {
    }

    public function hydrate(int $entityId): ?DataObject
    {
        try {
            $quote = $this->cartRepository->get($entityId);
        } catch (NoSuchEntityException | LocalizedException) {
            return null;
        }

        $data = $this->dataConverter->toFlatArray($quote, CartInterface::class);
        $items = [];
        foreach ($quote->getItems() ?: [] as $item) {
            $items[] = $this->dataConverter->toFlatArray($item, CartItemInterface::class);
        }
        $data['items'] = $items;

        $billing = $quote->getBillingAddress();
        if ($billing !== null) {
            $data['billing_country'] = $billing->getCountryId();
            $data['billing_postcode'] = $billing->getPostcode();
        }
        if ($quote instanceof Quote) {
            $shipping = $quote->getShippingAddress();
            if ($shipping !== null) {
                $data['shipping_country'] = $shipping->getCountryId();
                $data['shipping_postcode'] = $shipping->getPostcode();
            }
        }

        return $this->dataObjectFactory->create(['data' => $data]);
    }
}
