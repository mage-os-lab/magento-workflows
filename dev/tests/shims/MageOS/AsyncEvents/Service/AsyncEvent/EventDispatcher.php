<?php
declare(strict_types=1);

namespace MageOS\AsyncEvents\Service\AsyncEvent;

/**
 * Standalone-runner shim for mageos-async-events' delivery dispatcher.
 * Signature verified against mage-os/mageos-async-events @ b249976
 * (Service/AsyncEvent/EventDispatcher.php:49). Throws unless a test double
 * overrides it — there is no delivery pipeline in the standalone runner.
 */
class EventDispatcher
{
    /**
     * @param mixed $output
     */
    public function dispatch(string $eventName, $output, int $storeId = 0): void
    {
        throw new \RuntimeException(
            'EventDispatcher::dispatch() is not available in the standalone runner; override it in a test double.'
        );
    }
}
