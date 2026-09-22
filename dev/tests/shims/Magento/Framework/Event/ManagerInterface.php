<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Event;

/**
 * Minimal shim for Magento\Framework\Event\ManagerInterface — the single
 * dispatch() method the workflow engine calls. Tests supply a capturing or
 * no-op double.
 */
interface ManagerInterface
{
    /**
     * @param string $eventName
     * @param array $data
     * @return void
     */
    public function dispatch($eventName, array $data = []);
}
