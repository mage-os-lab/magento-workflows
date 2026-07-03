<?php
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Model;

use MageOS\AsyncEvents\Api\Data\AsyncEventDisplayInterface;
use MageOS\AsyncEvents\Service\AsyncEvent\NotifierInterface;
use MageOS\AsyncEvents\Service\AsyncEvent\NotifierResult;
use MageOS\AsyncEvents\Service\AsyncEvent\NotifierResultFactory;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\DispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Async-events notifier delivering events to the workflow engine.
 *
 * Registered in the async-events NotifierFactory pool under the name
 * "workflow" (see etc/di.xml); hidden subscriptions created by
 * SubscriptionManager carry metadata = "workflow" so the delivery consumer
 * resolves this notifier. $data arrives pre-hydrated by the event's declared
 * service class and becomes the execution's trigger snapshot.
 *
 * A dispatch suppressed on purpose (workflow disabled meanwhile, debounce,
 * loop guard, bulk suppression) is reported as SUCCESS: it must not enter
 * the async-events retry/dead-letter path. Only unexpected exceptions are
 * reported as failure and thereby retried with backoff.
 *
 * Async-events API assumptions (centralized here, adjust in one place if the
 * installed version differs):
 * - NotifierInterface::notify(AsyncEventDisplayInterface, array): NotifierResult
 * - NotifierResult exposes setSuccess(bool), setSubscriptionId(int),
 *   setResponseData(string). If your version instead exposes
 *   setUuid()/setNotificationData(), adapt buildResult() only.
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

    public function __construct(
        private readonly DispatcherInterface $dispatcher,
        private readonly NotifierResultFactory $notifierResultFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function notify(AsyncEventDisplayInterface $asyncEvent, array $data): NotifierResult
    {
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
     * Resolves the workflow id from the hidden subscription's recipient URL
     * ("workflow:<id>"). The recipient doubles as the ownership marker, so no
     * extra metadata field is needed.
     */
    private function extractWorkflowId(AsyncEventDisplayInterface $asyncEvent): ?int
    {
        $recipient = (string) $asyncEvent->getRecipientUrl();
        if (!str_starts_with($recipient, self::RECIPIENT_PREFIX)) {
            return null;
        }
        $id = substr($recipient, strlen(self::RECIPIENT_PREFIX));

        return ctype_digit($id) && (int) $id > 0 ? (int) $id : null;
    }

    /**
     * @param array<string, mixed> $responseData
     */
    private function buildResult(
        AsyncEventDisplayInterface $asyncEvent,
        bool $success,
        array $responseData
    ): NotifierResult {
        /** @var NotifierResult $result */
        $result = $this->notifierResultFactory->create();
        $result->setSuccess($success);
        $result->setSubscriptionId((int) $asyncEvent->getSubscriptionId());
        $result->setResponseData((string) json_encode($responseData));

        return $result;
    }
}
