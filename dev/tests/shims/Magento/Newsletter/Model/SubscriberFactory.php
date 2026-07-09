<?php
declare(strict_types=1);

namespace Magento\Newsletter\Model;

/**
 * Standalone-runner shim for Magento\Newsletter\Model\SubscriberFactory. Tests
 * pass their own fake factory (a subclass whose create() returns a prepared
 * fake Subscriber), so this base only needs to exist for type-hinting. Real
 * Magento installs load the generated factory instead.
 */
class SubscriberFactory
{
    public function create(array $data = []): Subscriber
    {
        return new Subscriber();
    }
}
