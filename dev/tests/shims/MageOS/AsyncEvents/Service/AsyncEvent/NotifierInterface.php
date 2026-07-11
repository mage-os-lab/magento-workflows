<?php
declare(strict_types=1);

namespace MageOS\AsyncEvents\Service\AsyncEvent;

use CloudEvents\V1\CloudEventImmutable;
use MageOS\AsyncEvents\Api\Data\AsyncEventInterface;
use MageOS\AsyncEvents\Api\Data\ResultInterface;

/**
 * Standalone-runner shim for mage-os/mageos-async-events (4.x)
 * Service/AsyncEvent/NotifierInterface. Signature-faithful (returns the
 * ResultInterface contract; WorkflowNotifier narrows it to NotifierResult).
 */
interface NotifierInterface
{
    public function notify(AsyncEventInterface $asyncEvent, CloudEventImmutable $event): ResultInterface;
}
