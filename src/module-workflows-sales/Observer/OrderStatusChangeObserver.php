<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use MageOS\WorkflowsTriggersCore\Service\EventPublisher;
use Psr\Log\LoggerInterface;

/**
 * Gap-fill publisher: detects order status transitions on
 * 'sales_order_save_after' and publishes the 'sales.order.status_changed'
 * async event (declared in etc/async_events.xml) with the from/to statuses
 * in the payload — data mageos-common-async-events' plain
 * 'sales.order.updated' cannot carry.
 *
 * New orders (no original status) are excluded: 'sales.order.created'
 * covers them. Publishing failures are logged, never allowed to break the
 * order save.
 */
class OrderStatusChangeObserver implements ObserverInterface
{
    public const EVENT_NAME = 'sales.order.status_changed';

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
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof Order || !$order->getEntityId()) {
            return;
        }

        $fromStatus = $order->getOrigData('status');
        $toStatus = $order->getStatus();
        if ($fromStatus === null || $fromStatus === $toStatus) {
            return;
        }

        try {
            $this->eventPublisher->publish(self::EVENT_NAME, [
                // 'id' hydrates the payload via OrderRepositoryInterface::get($id)
                'id' => (int) $order->getEntityId(),
                'entity_id' => (int) $order->getEntityId(),
                'from_status' => (string) $fromStatus,
                'to_status' => (string) $toStatus,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf(
                    'Failed to publish %s for order #%s: %s',
                    self::EVENT_NAME,
                    $order->getIncrementId(),
                    $exception->getMessage()
                ),
                ['exception' => $exception]
            );
        }
    }
}
