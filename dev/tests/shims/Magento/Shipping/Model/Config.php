<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Shipping\Model;

/**
 * Standalone-runner shim for Magento\Shipping\Model\Config (the shipping
 * carrier registry). The real lookup reads store config and instantiates
 * carrier models, so it throws unless a test subclass overrides it — which is
 * also the degrade path order.add_tracking's config form must survive.
 */
class Config
{
    /**
     * Active carriers, carrier code => carrier model
     *
     * @param null|int|string $store
     * @return array
     */
    public function getActiveCarriers($store = null)
    {
        throw new \RuntimeException('getActiveCarriers() not implemented in shim');
    }
}
