<?php
declare(strict_types=1);

namespace MageOS\WorkflowsNewsletter\Test\Unit\Stub;

use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriberFactory;

/**
 * SubscriberFactory stand-in: create() returns a caller-supplied prepared
 * FakeSubscriber, so tests control exactly what a load()/loadByCustomerId()
 * yields (including the "not found" case: a subscriber with no id).
 */
class FakeSubscriberFactory extends SubscriberFactory
{
    public function __construct(private readonly Subscriber $subscriber)
    {
    }

    public function create(array $data = []): Subscriber
    {
        return $this->subscriber;
    }
}
