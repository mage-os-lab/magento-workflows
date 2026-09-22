<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsNewsletter\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Newsletter\Model\Subscriber;
use MageOS\WorkflowsNewsletter\Observer\SubscriptionChangeObserver;
use MageOS\WorkflowsNewsletter\Test\Unit\Stub\FakeSubscriber;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\RecordingEventPublisher;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Pins the SUB-T1 gap-fill contract: 'newsletter.subscription_changed' fires
 * with from/to status (codes + labels) on a real status transition and on
 * first subscribe (from null), never on a no-op save, and works for guests
 * (customer_id 0). Publish failures are logged, never rethrown into the save.
 */
class SubscriptionChangeObserverTest extends TestCase
{
    /**
     * @param array<string, mixed> $eventData
     */
    private function observerEvent(array $eventData): Observer
    {
        return new Observer(['event' => new Event($eventData)]);
    }

    public function testPublishesFromAndToStatusOnRealChange(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new SubscriptionChangeObserver($publisher, new RecordingLogger());

        $subscriber = new FakeSubscriber([
            'subscriber_id' => 42,
            'subscriber_status' => Subscriber::STATUS_UNSUBSCRIBED,
            'subscriber_email' => 'buyer@example.com',
            'store_id' => 1,
            'customer_id' => 7,
        ], Subscriber::STATUS_SUBSCRIBED);

        $observer->execute($this->observerEvent(['subscriber' => $subscriber]));

        $this->assertCount(1, $publisher->published);
        $data = $publisher->published[0]['data'];
        $this->assertSame('newsletter.subscription_changed', $publisher->published[0]['event']);
        $this->assertSame(42, $data['subscriberId']);
        $this->assertSame(42, $data['entity_id']);
        $this->assertSame(Subscriber::STATUS_SUBSCRIBED, $data['from_status']);
        $this->assertSame('Subscribed', $data['from_status_label']);
        $this->assertSame(Subscriber::STATUS_UNSUBSCRIBED, $data['to_status']);
        $this->assertSame('Unsubscribed', $data['to_status_label']);
        $this->assertSame('buyer@example.com', $data['subscriber_email']);
        $this->assertSame(1, $data['store_id']);
        $this->assertSame(7, $data['customer_id']);
    }

    public function testPublishesOnFirstSubscribeWithNullFromStatus(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new SubscriptionChangeObserver($publisher, new RecordingLogger());

        // Brand-new subscriber: no original status -> first opt-in moment.
        $subscriber = new FakeSubscriber([
            'subscriber_id' => 9,
            'subscriber_status' => Subscriber::STATUS_SUBSCRIBED,
            'subscriber_email' => 'guest@example.com',
            'store_id' => 2,
            'customer_id' => 0,
        ], null);

        $observer->execute($this->observerEvent(['subscriber' => $subscriber]));

        $this->assertCount(1, $publisher->published);
        $data = $publisher->published[0]['data'];
        $this->assertNull($data['from_status']);
        $this->assertNull($data['from_status_label']);
        $this->assertSame(Subscriber::STATUS_SUBSCRIBED, $data['to_status']);
        $this->assertSame('Subscribed', $data['to_status_label']);
    }

    public function testGuestSubscriberWorks(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new SubscriptionChangeObserver($publisher, new RecordingLogger());

        $subscriber = new FakeSubscriber([
            'subscriber_id' => 100,
            'subscriber_status' => Subscriber::STATUS_UNSUBSCRIBED,
            'subscriber_email' => 'guest@example.com',
            'store_id' => 1,
            'customer_id' => 0,
        ], Subscriber::STATUS_SUBSCRIBED);

        $observer->execute($this->observerEvent(['subscriber' => $subscriber]));

        $this->assertCount(1, $publisher->published);
        $this->assertSame(0, $publisher->published[0]['data']['customer_id']);
    }

    public function testDoesNotFireWhenStatusUnchanged(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new SubscriptionChangeObserver($publisher, new RecordingLogger());

        $subscriber = new FakeSubscriber([
            'subscriber_id' => 42,
            'subscriber_status' => Subscriber::STATUS_SUBSCRIBED,
        ], Subscriber::STATUS_SUBSCRIBED);

        $observer->execute($this->observerEvent(['subscriber' => $subscriber]));

        $this->assertCount(0, $publisher->published);
    }

    public function testDoesNotFireWhenStatusUnchangedAcrossTypeJuggling(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new SubscriptionChangeObserver($publisher, new RecordingLogger());

        // Orig data often carries strings from the DB: '1' -> 1 is not a change.
        $subscriber = new FakeSubscriber([
            'subscriber_id' => 42,
            'subscriber_status' => Subscriber::STATUS_SUBSCRIBED,
        ], '1');

        $observer->execute($this->observerEvent(['subscriber' => $subscriber]));

        $this->assertCount(0, $publisher->published);
    }

    public function testReadsSubscriberFromObjectKeyFallback(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new SubscriptionChangeObserver($publisher, new RecordingLogger());

        $subscriber = new FakeSubscriber([
            'subscriber_id' => 5,
            'subscriber_status' => Subscriber::STATUS_UNSUBSCRIBED,
        ], Subscriber::STATUS_SUBSCRIBED);

        $observer->execute($this->observerEvent(['data_object' => $subscriber]));

        $this->assertCount(1, $publisher->published);
    }

    public function testDoesNotFireWithoutSubscriberInEvent(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new SubscriptionChangeObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent([]));
        $observer->execute($this->observerEvent(['subscriber' => new \stdClass()]));

        $this->assertCount(0, $publisher->published);
    }

    public function testPublishFailureIsLoggedAndDoesNotBreakSave(): void
    {
        $publisher = new RecordingEventPublisher(new \RuntimeException('amqp connection refused'));
        $logger = new RecordingLogger();
        $observer = new SubscriptionChangeObserver($publisher, $logger);

        $subscriber = new FakeSubscriber([
            'subscriber_id' => 42,
            'subscriber_status' => Subscriber::STATUS_UNSUBSCRIBED,
        ], Subscriber::STATUS_SUBSCRIBED);

        // Must not throw.
        $observer->execute($this->observerEvent(['subscriber' => $subscriber]));

        $this->assertStringContainsString('error:', $logger->allMessages());
        $this->assertStringContainsString('newsletter.subscription_changed', $logger->allMessages());
        $this->assertStringContainsString('amqp connection refused', $logger->allMessages());
    }
}
