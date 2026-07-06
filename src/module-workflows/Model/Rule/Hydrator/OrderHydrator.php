<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule\Hydrator;

use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * sales_order hydrator: flat order data enriched with `items`, `payment`,
 * a flat `payment_method` and flat billing_/shipping_ address basics
 * (country, region name, postcode, city) — mirroring the trigger snapshot
 * shape.
 */
class OrderHydrator implements EntityHydratorInterface
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EntityDataConverter $dataConverter,
        private readonly DataObjectFactory $dataObjectFactory
    ) {
    }

    public function hydrate(int $entityId): ?DataObject
    {
        try {
            $order = $this->orderRepository->get($entityId);
        } catch (NoSuchEntityException | LocalizedException) {
            return null;
        }

        $data = $this->dataConverter->toFlatArray($order, OrderInterface::class);
        $items = [];
        foreach ($order->getItems() ?: [] as $item) {
            $items[] = $this->dataConverter->toFlatArray($item, OrderItemInterface::class);
        }
        $data['items'] = $items;
        $payment = $order->getPayment();
        if ($payment !== null) {
            $data['payment'] = $this->dataConverter->toFlatArray($payment, OrderPaymentInterface::class);
            $data['payment_method'] = $payment->getMethod();
        }

        $billing = $order->getBillingAddress();
        if ($billing !== null) {
            $data['billing_country'] = $billing->getCountryId();
            $data['billing_region'] = $billing->getRegion();
            $data['billing_postcode'] = $billing->getPostcode();
            $data['billing_city'] = $billing->getCity();
        }
        $shipping = $order instanceof Order ? $order->getShippingAddress() : null;
        if ($shipping !== null) {
            $data['shipping_country'] = $shipping->getCountryId();
            $data['shipping_region'] = $shipping->getRegion();
            $data['shipping_postcode'] = $shipping->getPostcode();
            $data['shipping_city'] = $shipping->getCity();
        }

        return $this->dataObjectFactory->create(['data' => $data]);
    }
}
