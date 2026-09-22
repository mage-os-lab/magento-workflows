<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsNewsletter\Model\Rule\Hydrator;

use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriberFactory;

/**
 * Async-event payload builder for newsletter.subscription_changed (SUB-T1).
 *
 * Why a bespoke service instead of a repository reference in async_events.xml:
 * the newsletter subscriber has NO Api repository interface (unlike order /
 * customer / product / quote, whose async events name
 * OrderRepositoryInterface::get and friends). So this pack ships its own
 * hydrating service; async_events.xml declares
 * `<service class="…\SubscriberHydrationService" method="getById"/>`, and the
 * async-events dispatcher matches the published `subscriberId` key to this
 * method's parameter by name — the same "hydrate the delivered payload from the
 * id in the published data" contract the repository-backed declarations rely
 * on (see the async_events.xml docblocks in the sales/customer/review packs and
 * MageOS\WorkflowsTriggersCore\Service\EventPublisher). Publisher-supplied extra
 * keys (from_status/to_status/…) ride alongside in the message.
 *
 * Loads through SubscriberFactory (the only read path core exposes — there is
 * no SubscriberRepositoryInterface), returning a flat DTO-ish array so the
 * newsletter_subscriber condition leaves (SUB-C1) are snapshot-servable without
 * a phase-2 hydration.
 */
class SubscriberHydrationService
{
    public function __construct(
        private readonly SubscriberFactory $subscriberFactory
    ) {
    }

    /**
     * Flat subscriber snapshot keyed by subscriber id, or an empty array when
     * the subscriber no longer exists (the notifier treats an empty snapshot as
     * a no-op delivery, not a failure).
     *
     * @return array<string, mixed>
     */
    public function getById(int $subscriberId): array
    {
        $subscriber = $this->subscriberFactory->create();
        $subscriber->load($subscriberId);
        if (!$subscriber->getId()) {
            return [];
        }
        return self::toFlatArray($subscriber);
    }

    /**
     * Map a loaded Subscriber to the flat snapshot shape shared by the trigger
     * payload and SubscriberHydrator. is_customer is the derived guest-vs-account
     * flag (customer_id > 0) the is_customer condition leaf reads directly.
     *
     * @return array<string, mixed>
     */
    public static function toFlatArray(Subscriber $subscriber): array
    {
        $subscriberId = (int)$subscriber->getId();
        $customerId = (int)$subscriber->getCustomerId();
        return [
            'entity_id' => $subscriberId,
            'subscriber_id' => $subscriberId,
            'subscriber_email' => (string)$subscriber->getSubscriberEmail(),
            'subscriber_status' => (int)$subscriber->getSubscriberStatus(),
            'store_id' => (int)$subscriber->getStoreId(),
            'customer_id' => $customerId,
            'is_customer' => $customerId > 0,
            'change_status_at' => $subscriber->getChangeStatusAt(),
        ];
    }
}
