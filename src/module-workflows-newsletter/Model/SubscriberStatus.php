<?php
declare(strict_types=1);

namespace MageOS\WorkflowsNewsletter\Model;

use Magento\Newsletter\Model\Subscriber;

/**
 * Single source of truth for the four Magento newsletter subscriber statuses
 * and their human labels, shared by everything in this pack that speaks
 * "status": the newsletter_subscriber condition root's subscriber_status leaf
 * (SUB-C1), the customer root's newsletter_status aggregate (CUS-C1) and the
 * subscription-changed trigger's readable from/to labels (SUB-T1).
 *
 * Codes are the Magento\Newsletter\Model\Subscriber::STATUS_* integer
 * constants (SUBSCRIBED=1, NOT_ACTIVE=2, UNSUBSCRIBED=3, UNCONFIRMED=4);
 * labels match the store-admin newsletter grid vocabulary.
 */
class SubscriberStatus
{
    /**
     * Status code => raw (untranslated) label. __()-wrapped by consumers that
     * render it in the UI; passed through verbatim into event payloads.
     *
     * @var array<int, string>
     */
    public const LABELS = [
        Subscriber::STATUS_SUBSCRIBED => 'Subscribed',
        Subscriber::STATUS_NOT_ACTIVE => 'Not Active',
        Subscriber::STATUS_UNSUBSCRIBED => 'Unsubscribed',
        Subscriber::STATUS_UNCONFIRMED => 'Unconfirmed',
    ];

    /**
     * Readable label for a status code ('Unknown' for anything unmapped).
     */
    public static function label(int $status): string
    {
        return self::LABELS[$status] ?? 'Unknown';
    }

    /**
     * Value/label option rows for a select condition/attribute, labels
     * __()-wrapped for the admin form.
     *
     * @return array<int, array{value: int, label: \Magento\Framework\Phrase}>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::LABELS as $value => $label) {
            $options[] = ['value' => $value, 'label' => __($label)];
        }
        return $options;
    }
}
