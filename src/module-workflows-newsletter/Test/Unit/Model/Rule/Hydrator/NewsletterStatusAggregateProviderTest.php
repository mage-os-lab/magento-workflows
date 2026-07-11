<?php
declare(strict_types=1);

namespace MageOS\WorkflowsNewsletter\Test\Unit\Model\Rule\Hydrator;

use Magento\Newsletter\Model\Subscriber;
use MageOS\WorkflowsNewsletter\Model\Rule\Hydrator\NewsletterStatusAggregateProvider;
use MageOS\WorkflowsNewsletter\Test\Unit\Stub\FakeSubscriber;
use MageOS\WorkflowsNewsletter\Test\Unit\Stub\FakeSubscriberFactory;
use PHPUnit\Framework\TestCase;

/**
 * CUS-C1: the customer newsletter-status aggregate contributed to the customer
 * root. newsletter_status is the subscriber's status; is_newsletter_subscribed
 * is the boolean convenience. A never-subscribed customer contributes NEITHER
 * key (fail-toward-false), and an invalid customer id short-circuits.
 */
class NewsletterStatusAggregateProviderTest extends TestCase
{
    private function provider(Subscriber $subscriber): NewsletterStatusAggregateProvider
    {
        return new NewsletterStatusAggregateProvider(new FakeSubscriberFactory($subscriber));
    }

    public function testMetadataOffersBothAttributes(): void
    {
        $metadata = $this->provider(new FakeSubscriber())->getAttributeMetadata();

        $this->assertArrayHasKey('newsletter_status', $metadata);
        $this->assertArrayHasKey('is_newsletter_subscribed', $metadata);
        $this->assertSame('select', $metadata['newsletter_status']['input_type']);
        $this->assertSame('boolean', $metadata['is_newsletter_subscribed']['input_type']);
    }

    public function testSubscribedCustomerIsSubscribed(): void
    {
        $subscriber = new FakeSubscriber([
            'subscriber_id' => 3,
            'subscriber_status' => Subscriber::STATUS_SUBSCRIBED,
        ]);

        $aggregates = $this->provider($subscriber)->getAggregates(7);

        $this->assertSame(Subscriber::STATUS_SUBSCRIBED, $aggregates['newsletter_status']);
        $this->assertTrue($aggregates['is_newsletter_subscribed']);
    }

    public function testUnsubscribedCustomerIsNotSubscribed(): void
    {
        $subscriber = new FakeSubscriber([
            'subscriber_id' => 3,
            'subscriber_status' => Subscriber::STATUS_UNSUBSCRIBED,
        ]);

        $aggregates = $this->provider($subscriber)->getAggregates(7);

        $this->assertSame(Subscriber::STATUS_UNSUBSCRIBED, $aggregates['newsletter_status']);
        $this->assertFalse($aggregates['is_newsletter_subscribed']);
    }

    public function testNeverSubscribedContributesNothing(): void
    {
        // No subscriber row: getId() falsy.
        $subscriber = new FakeSubscriber(['subscriber_id' => 0]);

        $this->assertSame([], $this->provider($subscriber)->getAggregates(7));
    }

    public function testInvalidCustomerIdShortCircuits(): void
    {
        $subscriber = new FakeSubscriber([
            'subscriber_id' => 3,
            'subscriber_status' => Subscriber::STATUS_SUBSCRIBED,
        ]);

        $this->assertSame([], $this->provider($subscriber)->getAggregates(0));
    }
}
