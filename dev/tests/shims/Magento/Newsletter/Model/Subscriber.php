<?php
declare(strict_types=1);

namespace Magento\Newsletter\Model;

/**
 * Standalone-runner shim for Magento\Newsletter\Model\Subscriber. Carries the
 * four status constants (real values from Magento core) so
 * MageOS\WorkflowsNewsletter\Model\SubscriberStatus can build its label map and
 * the observer/aggregate provider can compare against them; subclassed by the
 * pack's test Fakes (which override the accessors they exercise). Real Magento
 * installs load the real class instead.
 */
class Subscriber
{
    public const STATUS_SUBSCRIBED = 1;
    public const STATUS_NOT_ACTIVE = 2;
    public const STATUS_UNSUBSCRIBED = 3;
    public const STATUS_UNCONFIRMED = 4;
}
