<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Newsletter\Model\ResourceModel\Subscriber;

/**
 * Minimal shim for the newsletter-subscriber collection factory (core ships no
 * SubscriberRepositoryInterface, so the dry-run recent-subscriber provider is
 * collection-backed). Real Magento returns a DB-backed collection; the
 * standalone runner only needs the class to exist so the provider that
 * type-hints it can be constructed — tests inject a double whose create()
 * returns a fake collection.
 */
class CollectionFactory
{
    // Signature matches the real generated factory so the standalone runner
    // catches create() overrides that would be incompatible on a live install.
    public function create(array $data = [])
    {
        return null;
    }
}
