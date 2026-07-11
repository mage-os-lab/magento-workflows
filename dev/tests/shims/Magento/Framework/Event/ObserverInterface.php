<?php
declare(strict_types=1);

namespace Magento\Framework\Event;

/**
 * Standalone-runner shim for Magento\Framework\Event\ObserverInterface.
 */
interface ObserverInterface
{
    /**
     * @return void
     */
    public function execute(Observer $observer);
}
