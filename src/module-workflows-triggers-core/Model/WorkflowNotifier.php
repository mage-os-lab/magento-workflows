<?php
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Model;

use CloudEvents\V1\CloudEventImmutable;
use MageOS\AsyncEvents\Api\Data\AsyncEventInterface;
use MageOS\AsyncEvents\Helper\NotifierResult;
use MageOS\AsyncEvents\Helper\NotifierResultFactory;
use MageOS\AsyncEvents\Service\AsyncEvent\NotifierInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\DispatcherInterface;
use MageOS\Workflows\Model\Engine\FanOutExpander;
use MageOS\Workflows\Model\Engine\FanOutResult;
use Psr\Log\LoggerInterface;

/**
 * Async-events notifier delivering events to the workflow engine.
 *
 * Registered in the async-events NotifierFactory pool under the name "workflow"
 * (see etc/di.xml, argument `notifierClasses`); hidden subscriptions created by
 * SubscriptionManager carry metadata = "workflow" so the delivery consumer
 * resolves this notifier. The trigger snapshot ($data) is the CloudEvent
 * payload (`$event->getData()`), and the async-events trace UUID is the
 * CloudEvent id (`$event->getId()`), stamped onto each fanned-out child's
 * origin (F1).
 *
 * A dispatch suppressed on purpose (workflow disabled meanwhile, debounce,
 * loop guard, bulk suppression) is reported as successful and NOT retryable:
 * it must not enter the async-events retry/dead-letter path. Only unexpected
 * exceptions are reported as unsuccessful + retryable and thereby redelivered
 * with backoff.
 *
 * Contract (verified against mage-os/mageos-async-events >= 4.0):
 * - NotifierInterface::notify(AsyncEventInterface, CloudEventImmutable): NotifierResult
 * - NotifierResult exposes setSubscriptionId(int), setIsSuccessful(bool),
 *   setIsRetryable(bool), setResponseData(string).
 */
class WorkflowNotifier implements NotifierInterface
{
    /**
     * Notifier pool name / subscription metadata value.
     */
    public const NOTIFIER_NAME = 'workflow';

    /**
     * Recipient URL prefix marking a workflow-owned subscription
     * ("workflow:<workflow_id>"), doubling as the ownership marker
     * per docs/10-security.md#subscription-ownership.
     */
    public const RECIPIENT_PREFIX = 'workflow:';

    /**
     * Wait-subscription recipient infix: "workflow:<id>:wait:<event>" marks
     * a subscription created for a wait step rather than the workflow's own
     * trigger. Deliveries on it resume parked executions instead of
     * dispatching new ones.
     */
    public const WAIT_INFIX = ':wait:';

    public function __construct(
        private readonly DispatcherInterface $dispatcher,
        private readonly NotifierResultFactory $notifierResultFactory,
        private readonly FanOutExpander $fanOutExpander,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function notify(AsyncEventInterface $asyncEvent, CloudEventImmutable $event): NotifierResult
    {
        $data = $this->extractPayload($event);

        $waitTarget = $this->extractWaitTarget($asyncEvent);
        if ($waitTarget !== null) {
            return $this->notifyWait($asyncEvent, $waitTarget[0], $waitTarget[1], $data);
        }

        $workflowId = $this->extractWorkflowId($asyncEvent);
        if ($workflowId === null) {
            // Misconfigured subscription: never dispatchable, so do not retry.
            return $this->buildResult($asyncEvent, true, [
                'status' => 'skipped',
                'reason' => sprintf(
                    'Subscription recipient "%s" does not carry a workflow id',
                    (string) $asyncEvent->getRecipientUrl()
                ),
            ]);
        }

        // Trigger-level fan-out (F1): a workflow carrying a fan_out clause
        // expands this one event into per-target executions. The expander
        // returns null for the overwhelming majority — workflows without a
        // fan_out clause — which fall through to the ordinary single dispatch
        // below (the early-exit branch).
        try {
            $fanOut = $this->fanOutExpander->expand(
                $workflowId,
                $data,
                (string) $asyncEvent->getEventName(),
                $this->extractTraceUuid($event)
            );
        } catch (\Throwable $exception) {
            // Pre-expansion failure (relation resolution threw before any child
            // dispatched): report failure so async-events redelivers. Re-expansion
            // is safe — per-child debounce collapses anything already dispatched.
            $this->logger->error(
                sprintf('Workflow #%d fan-out expansion failed: %s', $workflowId, $exception->getMessage()),
                ['exception' => $exception, 'event_name' => (string) $asyncEvent->getEventName()]
            );

            return $this->buildResult($asyncEvent, false, [
                'status' => 'error',
                'workflow_id' => $workflowId,
                'error' => $exception->getMessage(),
            ]);
        }

        if ($fanOut instanceof FanOutResult) {
            // The relation resolved: SUCCESS regardless of per-child skips (those
            // are recorded, not retried — see the mid-expansion failure policy).
            return $this->buildResult($asyncEvent, true, [
                'status' => 'fanned_out',
                'workflow_id' => $workflowId,
                'dispatched' => $fanOut->getDispatched(),
                'skipped' => $fanOut->getSkipped(),
                'truncated' => $fanOut->isTruncated(),
            ]);
        }

        try {
            $execution = $this->dispatcher->dispatch(
                $workflowId,
                $data,
                WorkflowInterface::TRIGGER_TYPE_EVENT
            );
        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf('Workflow #%d dispatch failed: %s', $workflowId, $exception->getMessage()),
                ['exception' => $exception, 'event_name' => (string) $asyncEvent->getEventName()]
            );

            return $this->buildResult($asyncEvent, false, [
                'status' => 'error',
                'workflow_id' => $workflowId,
                'error' => $exception->getMessage(),
            ]);
        }

        if ($execution instanceof WorkflowExecutionInterface) {
            return $this->buildResult($asyncEvent, true, [
                'status' => 'dispatched',
                'workflow_id' => $workflowId,
                'execution_id' => $execution->getExecutionId(),
                'execution_uuid' => $execution->getUuid(),
            ]);
        }

        // Intentional suppression (disabled, scope mismatch, debounce, loop guard).
        return $this->buildResult($asyncEvent, true, [
            'status' => 'skipped',
            'workflow_id' => $workflowId,
        ]);
    }

    /**
     * Delivery on a wait subscription: resume parked executions instead of
     * dispatching a new one. Zero matches is a normal outcome (nothing was
     * waiting) and must never enter the retry path.
     *
     * @param array<string, mixed> $data
     */
    private function notifyWait(
        AsyncEventInterface $asyncEvent,
        int $workflowId,
        string $event,
        array $data
    ): NotifierResult {
        try {
            $resumed = $this->dispatcher->resumeWaiting($workflowId, $event, $data);
        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf('Workflow #%d wait resume failed: %s', $workflowId, $exception->getMessage()),
                ['exception' => $exception, 'event_name' => $event]
            );

            return $this->buildResult($asyncEvent, false, [
                'status' => 'error',
                'workflow_id' => $workflowId,
                'wait_event' => $event,
                'error' => $exception->getMessage(),
            ]);
        }

        return $this->buildResult($asyncEvent, true, [
            'status' => 'wait_resumed',
            'workflow_id' => $workflowId,
            'wait_event' => $event,
            'resumed' => $resumed,
        ]);
    }

    /**
     * The trigger snapshot is the CloudEvent payload. Accept an associative
     * array verbatim, decode a JSON-string payload, and treat anything else
     * (null/scalar) as an empty snapshot rather than failing the delivery.
     *
     * @return array<string, mixed>
     */
    private function extractPayload(CloudEventImmutable $event): array
    {
        $payload = $event->getData();
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        return is_array($payload) ? $payload : [];
    }

    /**
     * Resolves the workflow id from the hidden subscription's recipient URL
     * ("workflow:<id>"). The recipient doubles as the ownership marker, so no
     * extra metadata field is needed.
     */
    private function extractWorkflowId(AsyncEventInterface $asyncEvent): ?int
    {
        $recipient = (string) $asyncEvent->getRecipientUrl();
        if (!str_starts_with($recipient, self::RECIPIENT_PREFIX)) {
            return null;
        }
        $id = substr($recipient, strlen(self::RECIPIENT_PREFIX));

        return ctype_digit($id) && (int) $id > 0 ? (int) $id : null;
    }

    /**
     * The async-events trace UUID of this delivery is the CloudEvent id; it is
     * stamped onto each fanned-out child's origin (F1). An empty id degrades to
     * null and origin_uuid is simply omitted — the rest of origin still travels.
     */
    private function extractTraceUuid(CloudEventImmutable $event): ?string
    {
        $id = $event->getId();

        return $id !== '' ? $id : null;
    }

    /**
     * Parses a wait recipient "workflow:<id>:wait:<event>".
     *
     * @return array{0: int, 1: string}|null [workflow id, event] or null when not a wait recipient
     */
    private function extractWaitTarget(AsyncEventInterface $asyncEvent): ?array
    {
        $recipient = (string) $asyncEvent->getRecipientUrl();
        if (!str_starts_with($recipient, self::RECIPIENT_PREFIX)) {
            return null;
        }
        $rest = substr($recipient, strlen(self::RECIPIENT_PREFIX));
        $infixPos = strpos($rest, self::WAIT_INFIX);
        if ($infixPos === false) {
            return null;
        }
        $id = substr($rest, 0, $infixPos);
        $event = substr($rest, $infixPos + strlen(self::WAIT_INFIX));
        if (!ctype_digit($id) || (int) $id <= 0 || $event === '') {
            return null;
        }

        return [(int) $id, $event];
    }

    /**
     * @param array<string, mixed> $responseData
     */
    private function buildResult(
        AsyncEventInterface $asyncEvent,
        bool $success,
        array $responseData
    ): NotifierResult {
        /** @var NotifierResult $result */
        $result = $this->notifierResultFactory->create();
        $result->setSubscriptionId((int) $asyncEvent->getSubscriptionId());
        $result->setIsSuccessful($success);
        // Only genuine failures (unexpected exceptions) re-enter the async-events
        // retry/backoff path; intentional skips are successful and terminal.
        $result->setIsRetryable(!$success);
        $result->setResponseData((string) json_encode($responseData));

        return $result;
    }
}
