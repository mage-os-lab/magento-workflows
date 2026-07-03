<?php
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Service;

use MageOS\AsyncEvents\Service\AsyncEvent\EventDispatcher;

/**
 * Single seam onto the async-events publishing API for the gap-fill events
 * this module emits (sales.order.status_changed, customer.group_changed,
 * catalog.product.review_submitted).
 *
 * Async-events API assumption (the ONLY place it is made for publishing):
 * MageOS\AsyncEvents\Service\AsyncEvent\EventDispatcher::dispatch(string
 * $eventName, array $data). $data carries the arguments for the event's
 * declared service class method (matched by parameter name, e.g.
 * ['id' => $orderId] for OrderRepositoryInterface::get) plus any extra
 * context keys (from_status/to_status, ...) that ride along in the message.
 * If your async-events version publishes through a different class (e.g. a
 * queue Publisher), change this wrapper only.
 */
class EventPublisher
{
    public function __construct(
        private readonly EventDispatcher $eventDispatcher
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function publish(string $eventName, array $data): void
    {
        $this->eventDispatcher->dispatch($eventName, $data);
    }
}
