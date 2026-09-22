<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsNewsletter\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Newsletter\Model\Subscriber;
use MageOS\WorkflowsNewsletter\Model\SubscriberStatus;
use MageOS\WorkflowsTriggersCore\Service\EventPublisher;
use Psr\Log\LoggerInterface;

/**
 * Gap-fill publisher: detects newsletter subscription status transitions on
 * 'newsletter_subscriber_save_after' and publishes 'newsletter.subscription_changed'
 * (declared in etc/async_events.xml) with from/to status — the one event that
 * covers subscribe, unsubscribe and every intermediate transition, for guests
 * and account holders alike (SUB-T1).
 *
 * Event verification: Magento\Newsletter\Model\Subscriber extends
 * Magento\Framework\Model\AbstractModel; a standard model save dispatches
 * '{_eventPrefix}_save_after' = 'newsletter_subscriber_save_after', carrying the
 * saved model under the AbstractModel event-data keys ('data_object'/'object',
 * plus 'subscriber' where the model sets _eventObject). We read all three
 * defensively, exactly as the sales/customer/review gap-fill observers do for
 * their entities.
 *
 * Fires when subscriber_status CHANGED (getOrigData vs. current), AND on first
 * save/creation — a brand-new subscriber has null orig status, which is a
 * genuine subscribe/opt-in moment (guest welcome flows depend on it), so it
 * publishes with from_status = null. A save that leaves the status untouched
 * (email edit, store move) does NOT fire.
 *
 * Payload extras beyond the id: from_status/to_status as int codes AND readable
 * labels (SubscriberStatus), subscriber_email, store_id, customer_id (0 for
 * guests). The id (subscriberId) hydrates the full snapshot via
 * SubscriberHydrationService::getById.
 *
 * LOOP-GUARD INTERACTION (the classic action→event loop): the customer.newsletter
 * ACTION (this pack) mutates subscriber state through SubscriptionManagerInterface,
 * whose save re-enters 'newsletter_subscriber_save_after' and re-fires THIS
 * trigger — a subscribe action inside a workflow can therefore trigger a
 * subscription_changed workflow, which could act again. This is exactly the loop
 * the engine's chain-depth loop guard exists for
 * (WorkflowExecutionInterface::CHAIN_DEPTH, Dispatcher's loop_guard_depth):
 * each dispatch carries chain_depth, and a re-fire beyond the configured depth is
 * skipped (and logged), so an action→event→action chain terminates. This observer
 * adds no ad-hoc de-dup of its own; it always publishes the real transition and
 * defers cycle-breaking to that guard, keeping one authoritative mechanism.
 * Publishing failures are logged, never allowed to break the subscriber save.
 */
class SubscriptionChangeObserver implements ObserverInterface
{
    public const EVENT_NAME = 'newsletter.subscription_changed';

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
        $subscriber = $observer->getEvent()->getData('subscriber')
            ?? $observer->getEvent()->getData('data_object')
            ?? $observer->getEvent()->getData('object');
        if (!$subscriber instanceof Subscriber || !$subscriber->getId()) {
            return;
        }

        $origStatus = $subscriber->getOrigData('subscriber_status');
        $toStatus = (int)$subscriber->getSubscriberStatus();
        // Unchanged status on an existing subscriber: not a subscription event.
        if ($origStatus !== null && (int)$origStatus === $toStatus) {
            return;
        }
        $fromStatus = $origStatus === null ? null : (int)$origStatus;

        try {
            $this->eventPublisher->publish(self::EVENT_NAME, [
                // 'subscriberId' hydrates the snapshot via SubscriberHydrationService::getById
                'subscriberId' => (int)$subscriber->getId(),
                'entity_id' => (int)$subscriber->getId(),
                'from_status' => $fromStatus,
                'from_status_label' => $fromStatus === null ? null : SubscriberStatus::label($fromStatus),
                'to_status' => $toStatus,
                'to_status_label' => SubscriberStatus::label($toStatus),
                'subscriber_email' => (string)$subscriber->getSubscriberEmail(),
                'store_id' => (int)$subscriber->getStoreId(),
                'customer_id' => (int)$subscriber->getCustomerId(),
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf(
                    'Failed to publish %s for subscriber #%d: %s',
                    self::EVENT_NAME,
                    (int)$subscriber->getId(),
                    $exception->getMessage()
                ),
                ['exception' => $exception]
            );
        }
    }
}
