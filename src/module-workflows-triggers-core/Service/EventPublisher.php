<?php
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Service;

use MageOS\AsyncEvents\Service\AsyncEvent\EventDispatcher;

/**
 * Single seam onto the async-events publishing API for the gap-fill events
 * this module emits (sales.order.status_changed, customer.group_changed,
 * catalog.product.review_submitted).
 *
 * Async-events seam (the ONLY place it is made for publishing). Verified
 * against mage-os/mageos-async-events @ b249976:
 * Service/AsyncEvent/EventDispatcher.php:49 declares
 *   dispatch(string $eventName, mixed $output, int $storeId = 0): void
 * so our two-arg call is signature-valid ($output accepts our array; storeId
 * defaults to 0). $data carries the arguments for the event's declared service
 * class method (matched by parameter name, e.g. ['id' => $orderId] for
 * OrderRepositoryInterface::get) plus extra context keys (from_status/to_status,
 * ...) that ride along verbatim as the CloudEvent payload.
 *
 * DESIGN NOTE — seam divergence flagged for the maintainer (see report/
 * docs/14-risks.md): EventDispatcher is upstream's *delivery* dispatcher,
 * invoked by the queue consumer AsyncEventTriggerHandler::process AFTER
 * dequeue and async_events.xml service resolution — it is NOT the *publish*
 * entry point. The canonical programmatic publisher (mageos-common-async-events
 * Service/PublishingService.php, and upstream's own Model/AsyncEventPublisher)
 * enqueues via Api/AsyncEventPublisherInterface::publish(string, array, int),
 * pushing [eventName, json(data), storeId] onto QueueMetadataInterface::EVENT_QUEUE.
 * Calling EventDispatcher directly is a deliberate trade-off: it keeps the rich
 * publisher payload (the queue path would resolve it down to the id-hydrated
 * entity and DROP from_status/to_status), but it delivers synchronously in the
 * caller's thread. Switching to the queue publisher is a behavior change, not a
 * class-name fix, hence left to a maintainer decision.
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
