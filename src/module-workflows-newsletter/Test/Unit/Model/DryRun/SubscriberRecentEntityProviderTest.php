<?php
declare(strict_types=1);

namespace MageOS\WorkflowsNewsletter\Test\Unit\Model\DryRun;

use Magento\Newsletter\Model\ResourceModel\Subscriber\CollectionFactory;
use Magento\Newsletter\Model\Subscriber;
use MageOS\WorkflowsNewsletter\Model\DryRun\SubscriberRecentEntityProvider;
use MageOS\WorkflowsNewsletter\Test\Unit\Stub\FakeSubscriber;
use PHPUnit\Framework\TestCase;

/**
 * Dry-run entity picker (03), newsletter_subscriber provider: projection shape
 * (email + status word), newest-first ordering + page size handed to the
 * collection, and the empty-result contract.
 */
class SubscriberRecentEntityProviderTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $applied = [];

    public function setUp(): void
    {
        $this->applied = [];
    }

    /**
     * @param array<int, FakeSubscriber> $subscribers
     */
    private function provider(array $subscribers): SubscriberRecentEntityProvider
    {
        $test = $this;
        $factory = new class ($subscribers, $test) extends CollectionFactory {
            /** @param array<int, FakeSubscriber> $subscribers */
            public function __construct(
                private readonly array $subscribers,
                private readonly SubscriberRecentEntityProviderTest $test
            ) {
            }

            public function create(array $data = [])
            {
                return new class ($this->subscribers, $this->test) {
                    /** @param array<int, FakeSubscriber> $subscribers */
                    public function __construct(
                        private readonly array $subscribers,
                        private readonly SubscriberRecentEntityProviderTest $test
                    ) {
                    }

                    public function setOrder($field, $direction = 'DESC')
                    {
                        $this->test->record('order', [$field, $direction]);
                        return $this;
                    }

                    public function setPageSize($size)
                    {
                        $this->test->record('page_size', $size);
                        return $this;
                    }

                    public function setCurPage($page)
                    {
                        $this->test->record('cur_page', $page);
                        return $this;
                    }

                    public function getItems(): array
                    {
                        return $this->subscribers;
                    }
                };
            }
        };

        return new SubscriberRecentEntityProvider($factory);
    }

    /**
     * @param mixed $value
     */
    public function record(string $key, $value): void
    {
        $this->applied[$key] = $value;
    }

    private function subscriber(int $id, string $email, int $status): FakeSubscriber
    {
        return new FakeSubscriber([
            'subscriber_id' => $id,
            'subscriber_email' => $email,
            'subscriber_status' => $status,
        ]);
    }

    public function testEntityType(): void
    {
        $this->assertSame('newsletter_subscriber', $this->provider([])->getEntityType());
    }

    public function testProjectsIdAndEmailWithStatusWord(): void
    {
        $rows = $this->provider([
            $this->subscriber(4, 'reader@example.com', Subscriber::STATUS_SUBSCRIBED),
        ])->getRecent(20);

        $this->assertCount(1, $rows);
        $this->assertSame(4, $rows[0]['id']);
        $this->assertTrue(is_int($rows[0]['id']));
        $this->assertSame('reader@example.com — Subscribed', $rows[0]['label']);
        $this->assertTrue($rows[0]['label'] !== '');
    }

    public function testNewestFirstAndLimitAreHandedToTheCollection(): void
    {
        $rows = $this->provider([
            $this->subscriber(9, 'newest@example.com', Subscriber::STATUS_SUBSCRIBED),
            $this->subscriber(8, 'older@example.com', Subscriber::STATUS_UNSUBSCRIBED),
        ])->getRecent(5);

        $this->assertSame([9, 8], array_column($rows, 'id'));
        $this->assertSame('older@example.com — Unsubscribed', $rows[1]['label']);
        $this->assertSame(['subscriber_id', 'DESC'], $this->applied['order']);
        $this->assertSame(5, $this->applied['page_size']);
        $this->assertSame(1, $this->applied['cur_page']);
    }

    public function testEmptyResultYieldsEmptyList(): void
    {
        $this->assertSame([], $this->provider([])->getRecent(20));
    }
}
