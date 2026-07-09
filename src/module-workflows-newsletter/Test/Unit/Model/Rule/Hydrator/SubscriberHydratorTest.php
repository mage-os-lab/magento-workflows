<?php
declare(strict_types=1);

namespace MageOS\WorkflowsNewsletter\Test\Unit\Model\Rule\Hydrator;

use Magento\Framework\DataObjectFactory;
use Magento\Newsletter\Model\Subscriber;
use MageOS\WorkflowsNewsletter\Model\Rule\Hydrator\SubscriberHydrationService;
use MageOS\WorkflowsNewsletter\Model\Rule\Hydrator\SubscriberHydrator;
use MageOS\WorkflowsNewsletter\Test\Unit\Stub\FakeSubscriber;
use MageOS\WorkflowsNewsletter\Test\Unit\Stub\FakeSubscriberFactory;
use PHPUnit\Framework\TestCase;

/**
 * SUB-C1 / SUB-T1: the flat subscriber snapshot shared by the async-event
 * payload (SubscriberHydrationService::getById) and phase-2 hydration
 * (SubscriberHydrator). Verifies the flat shape (incl. the derived is_customer
 * guest flag) and the missing-subscriber -> empty/null contract.
 */
class SubscriberHydratorTest extends TestCase
{
    private function service(Subscriber $subscriber): SubscriberHydrationService
    {
        return new SubscriberHydrationService(new FakeSubscriberFactory($subscriber));
    }

    public function testGetByIdBuildsFlatSnapshotForAccountHolder(): void
    {
        $subscriber = new FakeSubscriber([
            'subscriber_id' => 42,
            'subscriber_status' => Subscriber::STATUS_SUBSCRIBED,
            'subscriber_email' => 'buyer@example.com',
            'store_id' => 1,
            'customer_id' => 7,
            'change_status_at' => '2026-07-09 12:00:00',
        ]);

        $data = $this->service($subscriber)->getById(42);

        $this->assertSame(42, $data['entity_id']);
        $this->assertSame(42, $data['subscriber_id']);
        $this->assertSame('buyer@example.com', $data['subscriber_email']);
        $this->assertSame(Subscriber::STATUS_SUBSCRIBED, $data['subscriber_status']);
        $this->assertSame(1, $data['store_id']);
        $this->assertSame(7, $data['customer_id']);
        $this->assertTrue($data['is_customer']);
        $this->assertSame('2026-07-09 12:00:00', $data['change_status_at']);
    }

    public function testGetByIdMarksGuestSubscriber(): void
    {
        $subscriber = new FakeSubscriber([
            'subscriber_id' => 9,
            'subscriber_status' => Subscriber::STATUS_SUBSCRIBED,
            'subscriber_email' => 'guest@example.com',
            'store_id' => 2,
            'customer_id' => 0,
        ]);

        $data = $this->service($subscriber)->getById(9);

        $this->assertFalse($data['is_customer']);
        $this->assertSame(0, $data['customer_id']);
    }

    public function testGetByIdReturnsEmptyWhenSubscriberMissing(): void
    {
        $subscriber = new FakeSubscriber(['subscriber_id' => 0]);

        $this->assertSame([], $this->service($subscriber)->getById(999));
    }

    public function testHydratorWrapsSnapshotInDataObject(): void
    {
        $subscriber = new FakeSubscriber([
            'subscriber_id' => 42,
            'subscriber_status' => Subscriber::STATUS_UNSUBSCRIBED,
            'subscriber_email' => 'buyer@example.com',
            'store_id' => 1,
            'customer_id' => 7,
        ]);
        $hydrator = new SubscriberHydrator($this->service($subscriber), new DataObjectFactory());

        $entity = $hydrator->hydrate(42);

        $this->assertNotNull($entity);
        $this->assertSame(Subscriber::STATUS_UNSUBSCRIBED, $entity->getData('subscriber_status'));
        $this->assertSame('buyer@example.com', $entity->getData('subscriber_email'));
        $this->assertTrue($entity->getData('is_customer'));
    }

    public function testHydratorReturnsNullWhenSubscriberMissing(): void
    {
        $subscriber = new FakeSubscriber(['subscriber_id' => 0]);
        $hydrator = new SubscriberHydrator($this->service($subscriber), new DataObjectFactory());

        $this->assertNull($hydrator->hydrate(999));
    }
}
