<?php
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Test\Unit\Model;

use CloudEvents\V1\CloudEventImmutable;
use MageOS\AsyncEvents\Helper\NotifierResult;
use MageOS\Workflows\Model\Engine\FanOutResult;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsTriggersCore\Model\WorkflowNotifier;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\FakeAsyncEvent;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\FakeDispatcher;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\FakeFanOutExpander;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\FakeNotifierResultFactory;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Pins the retryable classification docs/05-triggers.md builds the retry
 * story on: the engine inherits async-events' backoff retry/dead-letter
 * machinery, so ONLY unexpected exceptions may report unsuccessful+retryable.
 * An intentional skip (workflow disabled meanwhile, debounce, loop guard) is
 * successful and terminal — it must never re-enter the retry path. Also pins
 * recipient parsing: "workflow:<id>" dispatches, "workflow:<id>:wait:<event>"
 * resumes parked executions, and a malformed recipient degrades to a
 * terminal skip instead of a fatal.
 */
class WorkflowNotifierTest extends TestCase
{
    private FakeDispatcher $dispatcher;

    private FakeFanOutExpander $fanOutExpander;

    private RecordingLogger $logger;

    public function setUp(): void
    {
        $this->dispatcher = new FakeDispatcher();
        $this->fanOutExpander = new FakeFanOutExpander();
        $this->logger = new RecordingLogger();
    }

    private function notifier(): WorkflowNotifier
    {
        return new WorkflowNotifier(
            $this->dispatcher,
            new FakeNotifierResultFactory(),
            $this->fanOutExpander,
            $this->logger
        );
    }

    private function subscription(string $recipient, int $subscriptionId = 33): FakeAsyncEvent
    {
        $subscription = new FakeAsyncEvent();
        $subscription->setSubscriptionId($subscriptionId);
        $subscription->setRecipientUrl($recipient);
        $subscription->setEventName('sales.order.created');
        $subscription->setMetadata(WorkflowNotifier::NOTIFIER_NAME);
        $subscription->setStatus(true);
        return $subscription;
    }

    /**
     * @param mixed $data
     */
    private function event($data = ['entity_id' => 7], string $id = 'trace-uuid-1'): CloudEventImmutable
    {
        return new CloudEventImmutable($id, 'mageos/workflows-test', 'sales.order.created', $data);
    }

    public function testWorkflowRecipientRoutesToDispatch(): void
    {
        $execution = new WorkflowExecutionStub('exec-uuid-9');
        $execution->setExecutionId(77);
        $this->dispatcher->dispatchResult = $execution;

        $result = $this->notifier()->notify($this->subscription('workflow:42'), $this->event());

        $this->assertCount(1, $this->dispatcher->dispatchCalls);
        $this->assertSame(42, $this->dispatcher->dispatchCalls[0]['workflowId']);
        $this->assertSame(['entity_id' => 7], $this->dispatcher->dispatchCalls[0]['payload']);
        $this->assertSame('event', $this->dispatcher->dispatchCalls[0]['triggerType']);
        $this->assertTrue($result->getIsSuccessful());
        $this->assertFalse($result->getIsRetryable());
        $this->assertStringContainsString('dispatched', $result->getResponseData());
        $this->assertStringContainsString('exec-uuid-9', $result->getResponseData());
    }

    public function testIntentionalSkipIsSuccessfulAndNotRetryable(): void
    {
        $this->dispatcher->dispatchResult = null; // suppressed on purpose

        $result = $this->notifier()->notify($this->subscription('workflow:42'), $this->event());

        $this->assertTrue($result->getIsSuccessful(), 'a suppressed dispatch must not look like a failure');
        $this->assertFalse($result->getIsRetryable(), 'a suppressed dispatch must never be redelivered');
        $this->assertStringContainsString('skipped', $result->getResponseData());
    }

    public function testUnexpectedDispatchExceptionIsUnsuccessfulAndRetryable(): void
    {
        $this->dispatcher->dispatchThrows = new \RuntimeException('database gone away');

        $result = $this->notifier()->notify($this->subscription('workflow:42'), $this->event());

        $this->assertFalse($result->getIsSuccessful());
        $this->assertTrue($result->getIsRetryable(), 'unexpected failures must re-enter the retry path');
        $this->assertStringContainsString('database gone away', $result->getResponseData());
        $this->assertStringContainsString('error:', $this->logger->allMessages());
        $this->assertStringContainsString('dispatch failed', $this->logger->allMessages());
    }

    public function testWaitRecipientRoutesToResumeWaitingNotDispatch(): void
    {
        $this->dispatcher->resumeResult = 2;

        $result = $this->notifier()->notify(
            $this->subscription('workflow:42:wait:sales.order.shipped'),
            $this->event()
        );

        $this->assertCount(0, $this->dispatcher->dispatchCalls);
        $this->assertCount(1, $this->dispatcher->resumeCalls);
        $this->assertSame(42, $this->dispatcher->resumeCalls[0]['workflowId']);
        $this->assertSame('sales.order.shipped', $this->dispatcher->resumeCalls[0]['event']);
        $this->assertSame(['entity_id' => 7], $this->dispatcher->resumeCalls[0]['payload']);
        $this->assertTrue($result->getIsSuccessful());
        $this->assertFalse($result->getIsRetryable());
        $this->assertStringContainsString('wait_resumed', $result->getResponseData());
    }

    public function testWaitResumeWithZeroMatchesIsStillSuccessful(): void
    {
        $this->dispatcher->resumeResult = 0; // nothing was parked: normal outcome

        $result = $this->notifier()->notify(
            $this->subscription('workflow:42:wait:sales.order.shipped'),
            $this->event()
        );

        $this->assertTrue($result->getIsSuccessful());
        $this->assertFalse($result->getIsRetryable());
    }

    public function testWaitResumeExceptionIsUnsuccessfulAndRetryable(): void
    {
        $this->dispatcher->resumeThrows = new \RuntimeException('lock wait timeout');

        $result = $this->notifier()->notify(
            $this->subscription('workflow:42:wait:sales.order.shipped'),
            $this->event()
        );

        $this->assertFalse($result->getIsSuccessful());
        $this->assertTrue($result->getIsRetryable());
        $this->assertStringContainsString('lock wait timeout', $result->getResponseData());
        $this->assertStringContainsString('wait resume failed', $this->logger->allMessages());
    }

    public function testMalformedWorkflowIdRecipientSkipsWithoutDispatchOrFatal(): void
    {
        $result = $this->notifier()->notify($this->subscription('workflow:abc'), $this->event());

        $this->assertCount(0, $this->dispatcher->dispatchCalls);
        $this->assertCount(0, $this->dispatcher->resumeCalls);
        $this->assertTrue($result->getIsSuccessful(), 'a never-dispatchable subscription must not retry forever');
        $this->assertFalse($result->getIsRetryable());
        $this->assertStringContainsString('skipped', $result->getResponseData());
        $this->assertStringContainsString('workflow:abc', $result->getResponseData());
    }

    public function testForeignRecipientSkipsWithoutDispatch(): void
    {
        $result = $this->notifier()->notify(
            $this->subscription('https://example.com/webhook'),
            $this->event()
        );

        $this->assertCount(0, $this->dispatcher->dispatchCalls);
        $this->assertTrue($result->getIsSuccessful());
        $this->assertFalse($result->getIsRetryable());
        $this->assertStringContainsString('skipped', $result->getResponseData());
    }

    public function testWaitRecipientWithEmptyEventDegradesToTerminalSkip(): void
    {
        $result = $this->notifier()->notify($this->subscription('workflow:5:wait:'), $this->event());

        $this->assertCount(0, $this->dispatcher->resumeCalls);
        $this->assertCount(0, $this->dispatcher->dispatchCalls);
        $this->assertTrue($result->getIsSuccessful());
        $this->assertFalse($result->getIsRetryable());
    }

    public function testJsonStringPayloadIsDecodedIntoSnapshot(): void
    {
        $this->dispatcher->dispatchResult = null;

        $this->notifier()->notify($this->subscription('workflow:42'), $this->event('{"entity_id":9}'));

        $this->assertSame(['entity_id' => 9], $this->dispatcher->dispatchCalls[0]['payload']);
    }

    public function testNonArrayPayloadDegradesToEmptySnapshot(): void
    {
        $this->dispatcher->dispatchResult = null;

        $this->notifier()->notify($this->subscription('workflow:42'), $this->event(null));

        $this->assertSame([], $this->dispatcher->dispatchCalls[0]['payload']);
    }

    public function testResultCarriesSubscriptionId(): void
    {
        $this->dispatcher->dispatchResult = null;

        $result = $this->notifier()->notify($this->subscription('workflow:42', 33), $this->event());

        $this->assertInstanceOf(NotifierResult::class, $result);
        $this->assertSame(33, $result->getSubscriptionId());
    }

    public function testFanOutResultReportsSuccessWithoutSingleDispatch(): void
    {
        $this->fanOutExpander->result = new FanOutResult(3, 1, true);

        $result = $this->notifier()->notify($this->subscription('workflow:42'), $this->event());

        $this->assertCount(0, $this->dispatcher->dispatchCalls, 'fanned-out events must not also single-dispatch');
        $this->assertTrue($result->getIsSuccessful());
        $this->assertFalse($result->getIsRetryable(), 'per-child skips are recorded, never retried');
        $this->assertStringContainsString('fanned_out', $result->getResponseData());
        $this->assertStringContainsString('"dispatched":3', $result->getResponseData());
        $this->assertStringContainsString('"skipped":1', $result->getResponseData());
    }

    public function testFanOutExpansionFailureIsRetryable(): void
    {
        $this->fanOutExpander->throws = new \RuntimeException('relation resolution failed');

        $result = $this->notifier()->notify($this->subscription('workflow:42'), $this->event());

        $this->assertFalse($result->getIsSuccessful());
        $this->assertTrue($result->getIsRetryable(), 'pre-expansion failure must redeliver');
        $this->assertStringContainsString('fan-out expansion failed', $this->logger->allMessages());
    }

    public function testExpanderReceivesEventNameAndTraceUuid(): void
    {
        $this->dispatcher->dispatchResult = null;

        $this->notifier()->notify(
            $this->subscription('workflow:42'),
            $this->event(['entity_id' => 7], 'trace-uuid-1')
        );

        $this->assertCount(1, $this->fanOutExpander->calls);
        $this->assertSame(42, $this->fanOutExpander->calls[0]['workflowId']);
        $this->assertSame('sales.order.created', $this->fanOutExpander->calls[0]['eventName']);
        $this->assertSame('trace-uuid-1', $this->fanOutExpander->calls[0]['traceUuid']);
    }
}
