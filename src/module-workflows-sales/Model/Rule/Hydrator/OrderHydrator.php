<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Model\Rule\Hydrator;

use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use MageOS\Workflows\Model\Rule\AggregateProviderPool;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;
use MageOS\Workflows\Model\Rule\Hydrator\EntityDataConverter;
use MageOS\Workflows\Model\Rule\Hydrator\EntityHydratorInterface;

/**
 * sales_order hydrator: flat order data enriched with `items`, `payment`,
 * a flat `payment_method` and flat billing_/shipping_ address basics
 * (country, region name, postcode, city) — mirroring the trigger snapshot
 * shape — plus the lifecycle-flag aggregates (can_invoice, can_ship,
 * can_creditmemo, is_virtual, invoice_count, shipment_count) contributed to
 * the order root through AggregateProviderPool (E2 / ORD-C1). The aggregates
 * exist ONLY on hydrated orders — trigger snapshots never carry them — so
 * conditions on them always classify as needs_hydration and resolve in phase 2.
 */
class OrderHydrator implements EntityHydratorInterface
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EntityDataConverter $dataConverter,
        private readonly DataObjectFactory $dataObjectFactory,
        private readonly AggregateProviderPool $aggregateProviderPool
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

        $data = array_merge(
            $data,
            $this->aggregateProviderPool->getAggregates(HydrationProviderInterface::TYPE_ORDER, $entityId)
        );

        return $this->dataObjectFactory->create(['data' => $data]);
    }
}
