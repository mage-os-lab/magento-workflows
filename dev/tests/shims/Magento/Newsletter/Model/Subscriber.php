<?php
declare(strict_types=1);

namespace Magento\Newsletter\Model;

/**
 * Standalone-runner shim for Magento\Newsletter\Model\Subscriber. Only
 * needed as a return type for SubscriptionManagerInterface fakes; tests
 * subclass it with a no-argument constructor.
 */
class Subscriber
{
}
