<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsNewsletter\Model\Rule\Hydrator;

use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriberFactory;
use MageOS\Workflows\Model\Rule\AggregateProviderInterface;

/**
 * Customer newsletter-status aggregates (CUS-C1), contributed to the CUSTOMER
 * condition root through AggregateProviderPool under entity type 'customer' —
 * the pool does the contribution, so "…and is subscribed" becomes a customer-root
 * guard with ZERO changes to workflows-customer.
 *
 *  - newsletter_status         the customer's subscriber status
 *                              (Subscriber::STATUS_*), ABSENT when the customer
 *                              was never subscribed (no subscriber row)
 *  - is_newsletter_subscribed  boolean convenience: status == SUBSCRIBED; the
 *                              flagship "is subscribed" marketing guard
 *
 * Absence semantics: a customer with no subscriber row yields NEITHER key —
 * absent attributes only match the negative operators (fail-toward-false,
 * AbstractWorkflowCondition::validateAttribute()), so "newsletter_status is
 * Unsubscribed" and "is_newsletter_subscribed = Yes" both correctly miss a
 * never-subscribed customer.
 *
 * Resolves through SubscriberFactory::loadByCustomerId — the same read path the
 * pack's SubscriberHydrator/SubscriberHydrationService use, and the only one
 * core exposes (no SubscriberRepositoryInterface). Consistent with the pack's
 * customer.newsletter action / anonymize plugin, which speak
 * SubscriptionManagerInterface for MUTATION; reads go through the factory.
 *
 * KNOWN LIMITATION (a general AggregateProviderInterface gap, not
 * newsletter-specific): the interface carries only label + input_type, no value
 * options, and the customer root's aggregate path supplies no select options
 * for contributed attributes. So newsletter_status evaluates correctly against a
 * status code but its picker shows no pre-filled Subscribed/Unsubscribed labels
 * in v1; is_newsletter_subscribed (boolean) is the ergonomic guard. Extending
 * the aggregate contract with an options hook is a follow-up, deliberately not
 * solved here to keep workflows-customer untouched.
 */
class NewsletterStatusAggregateProvider implements AggregateProviderInterface
{
    private const ATTRIBUTE_METADATA = [
        'newsletter_status' => ['label' => 'Newsletter Status', 'input_type' => 'select'],
        'is_newsletter_subscribed' => ['label' => 'Is Newsletter Subscribed', 'input_type' => 'boolean'],
    ];

    public function __construct(
        private readonly SubscriberFactory $subscriberFactory
    ) {
    }

    /**
     * @return array<string, array{label: string, input_type: string}>
     */
    public function getAttributeMetadata(): array
    {
        return self::ATTRIBUTE_METADATA;
    }

    /**
     * @return array{newsletter_status?: int, is_newsletter_subscribed?: bool}
     */
    public function getAggregates(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }
        $subscriber = $this->subscriberFactory->create();
        $subscriber->loadByCustomerId($customerId);
        if (!$subscriber->getId()) {
            // Never subscribed: contribute nothing (fail-toward-false).
            return [];
        }
        $status = (int)$subscriber->getSubscriberStatus();
        return [
            'newsletter_status' => $status,
            'is_newsletter_subscribed' => $status === Subscriber::STATUS_SUBSCRIBED,
        ];
    }
}
