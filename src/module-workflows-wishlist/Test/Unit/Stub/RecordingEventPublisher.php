<?php
declare(strict_types=1);

namespace MageOS\WorkflowsWishlist\Test\Unit\Stub;

use MageOS\WorkflowsTriggersCore\Service\EventPublisher;

/**
 * EventPublisher stand-in: records publishes, optionally throwing to exercise
 * the observer's log-and-continue contract.
 */
class RecordingEventPublisher extends EventPublisher
{
    /** @var array<int, array{event: string, data: array}> */
    public array $published = [];

    public function __construct(private readonly ?\Throwable $throws = null)
    {
    }

    public function publish(string $eventName, array $data): void
    {
        $this->published[] = ['event' => $eventName, 'data' => $data];
        if ($this->throws !== null) {
            throw $this->throws;
        }
    }
}
