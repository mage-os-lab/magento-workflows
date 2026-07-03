<?php
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Observer;

use Magento\Customer\Model\Customer;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\WorkflowsTriggersCore\Service\EventPublisher;
use Psr\Log\LoggerInterface;

/**
 * Gap-fill publisher: detects customer group transitions on
 * 'customer_save_after' (customer model event) and publishes the
 * 'customer.group_changed' async event with from/to group ids.
 *
 * Newly created customers (no original group) are excluded:
 * 'customer.created' covers them. Publishing failures are logged, never
 * allowed to break the customer save.
 */
class CustomerGroupChangeObserver implements ObserverInterface
{
    public const EVENT_NAME = 'customer.group_changed';

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
        $customer = $observer->getEvent()->getData('customer')
            ?? $observer->getEvent()->getData('object');
        if (!$customer instanceof Customer || !$customer->getId()) {
            return;
        }

        $fromGroupId = $customer->getOrigData('group_id');
        $toGroupId = $customer->getGroupId();
        if ($fromGroupId === null || (int) $fromGroupId === (int) $toGroupId) {
            return;
        }

        try {
            $this->eventPublisher->publish(self::EVENT_NAME, [
                // 'customerId' hydrates via CustomerRepositoryInterface::getById($customerId)
                'customerId' => (int) $customer->getId(),
                'entity_id' => (int) $customer->getId(),
                'from_group_id' => (int) $fromGroupId,
                'to_group_id' => (int) $toGroupId,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf(
                    'Failed to publish %s for customer #%d: %s',
                    self::EVENT_NAME,
                    (int) $customer->getId(),
                    $exception->getMessage()
                ),
                ['exception' => $exception]
            );
        }
    }
}
