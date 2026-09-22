<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Sales\Model\Order;

/**
 * Standalone-runner shim for Magento\Sales\Model\Order\Config (the order
 * state machine). The real lookups need DB-backed status collections, so
 * everything throws unless a test subclass overrides it.
 */
class Config
{
    /**
     * Statuses assigned to the given state (status code => label)
     *
     * @param string|string[] $state
     * @param bool $addLabels
     * @return array
     */
    public function getStateStatuses($state, $addLabels = true)
    {
        throw new \RuntimeException('getStateStatuses() not implemented in shim');
    }

    /**
     * All order statuses (status code => label)
     *
     * @return array
     */
    public function getStatuses()
    {
        throw new \RuntimeException('getStatuses() not implemented in shim');
    }
}
