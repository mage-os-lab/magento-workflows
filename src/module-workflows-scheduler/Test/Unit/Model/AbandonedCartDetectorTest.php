<?php
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Unit\Model;

use MageOS\Workflows\Test\Unit\Stub\StubScopeConfig;
use MageOS\WorkflowsScheduler\Model\AbandonedCartDetector;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\FakeObjectManager;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\FakeQuoteDb;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\FakeResourceConnection;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\RecordingLogger;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\RecordingPublisher;
use PHPUnit\Framework\TestCase;

/**
 * AbandonedCartDetector, per docs/05-triggers.md footnote 1 and the class
 * docblock: cart abandonment is a QUERY — "quote updated > N hours ago, no
 * order" — whose hits are published as quote.abandoned events.
 *
 * - only active quotes with items (and an email), older than the configured
 *   age window but inside the 7-day max age, are candidates;
 * - an already-flagged quote is not re-published (dedupe flag);
 * - a publish failure leaves the quote UNflagged so the next run retries;
 * - the EventPublisher is a deliberate soft dependency: when it cannot be
 *   resolved the detector degrades to a debug log and still flags, so the
 *   same quote is not re-logged every 10 minutes.
 *
 * The detector reads the real clock for its window arithmetic; quote ages
 * here are hours/days away from every boundary, so test runtime jitter
 * cannot flip an expectation. FakeQuoteDb applies the window bounds the
 * detector itself computed and bound into its query.
 */
class AbandonedCartDetectorTest extends TestCase
{
    private const EVENT = 'quote.abandoned';

    private function detector(
        FakeResourceConnection $resource,
        ?string $configuredHours,
        FakeObjectManager $objectManager,
        ?RecordingLogger $logger = null
    ): AbandonedCartDetector {
        return new AbandonedCartDetector(
            $resource,
            new StubScopeConfig(
                $configuredHours === null ? [] : ['mageos_workflows/scheduler/abandoned_hours' => $configuredHours]
            ),
            $logger ?? new RecordingLogger(),
            $objectManager
        );
    }

    /**
     * UTC timestamp $modify (e.g. '-3 hours') from now — the same clock and
     * format the detector uses for its window bounds.
     */
    private function ago(string $modify): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify($modify)
            ->format('Y-m-d H:i:s');
    }

    public function testOnlyActiveAgedQuotesWithItemsAndEmailAreCandidates(): void
    {
        $db = new FakeQuoteDb();
        // The one true abandoned cart: active, has items + email, 3h stale.
        $db->addQuote(10, $this->ago('-3 hours'), 1, 2, 'buyer@example.com', 3);
        // Too fresh: still inside the 2-hour window.
        $db->addQuote(11, $this->ago('-30 minutes'));
        // Aged out: older than the 7-day max age — never resurrected.
        $db->addQuote(12, $this->ago('-8 days'));
        // Converted/inactive quote.
        $db->addQuote(13, $this->ago('-3 hours'), 0);
        // Empty cart.
        $db->addQuote(14, $this->ago('-3 hours'), 1, 0);
        // Guest cart without an email — nothing to follow up with.
        $db->addQuote(15, $this->ago('-3 hours'), 1, 2, null);

        $publisher = new RecordingPublisher();
        $this->detector(
            new FakeResourceConnection($db),
            '2',
            new FakeObjectManager($publisher)
        )->execute();

        $this->assertCount(1, $publisher->published, 'exactly one quote qualifies as abandoned');
        [$event, $payload] = $publisher->published[0];
        $this->assertSame(self::EVENT, $event);
        $this->assertSame(10, $payload['quote_id']);
        $this->assertSame('buyer@example.com', $payload['customer_email']);
        $this->assertSame(3, $payload['store_id']);

        $this->assertArrayHasKey(10, $db->flags, 'the published quote is flagged for dedupe');
        $this->assertCount(1, $db->flags, 'non-candidates must not be flagged');
    }

    public function testDefaultAgeWindowIsFourHours(): void
    {
        // No abandoned_hours configured: the documented default window (4h)
        // applies — a 3h-old quote is not yet abandoned, a 5h-old one is.
        $db = new FakeQuoteDb();
        $db->addQuote(20, $this->ago('-3 hours'));
        $db->addQuote(21, $this->ago('-5 hours'));

        $publisher = new RecordingPublisher();
        $this->detector(
            new FakeResourceConnection($db),
            null,
            new FakeObjectManager($publisher)
        )->execute();

        $this->assertCount(1, $publisher->published);
        $this->assertSame(21, $publisher->published[0][1]['quote_id']);
    }

    public function testFlaggedQuoteIsNotRepublishedOnTheNextRun(): void
    {
        $db = new FakeQuoteDb();
        $db->addQuote(10, $this->ago('-3 hours'));
        $publisher = new RecordingPublisher();
        $resource = new FakeResourceConnection($db);
        $objectManager = new FakeObjectManager($publisher);

        $this->detector($resource, '2', $objectManager)->execute();
        // Ten minutes later the quote is still abandoned — but already flagged.
        $this->detector($resource, '2', $objectManager)->execute();

        $this->assertCount(1, $publisher->published, 'an already-flagged quote must not be re-published');
        $this->assertSame(1, $publisher->attempts, 'the second run must not even attempt a publish');
    }

    public function testPublishFailureLeavesQuoteUnflaggedSoNextRunRetries(): void
    {
        $db = new FakeQuoteDb();
        $db->addQuote(10, $this->ago('-3 hours'));
        $publisher = new RecordingPublisher();
        $publisher->failWith = new \RuntimeException('amqp connection refused');
        $logger = new RecordingLogger();
        $resource = new FakeResourceConnection($db);
        $objectManager = new FakeObjectManager($publisher);

        $this->detector($resource, '2', $objectManager, $logger)->execute();

        $this->assertCount(0, $publisher->published);
        $this->assertCount(0, $db->flags, 'a quote whose publish failed must NOT be flagged, so the next run retries');
        $this->assertTrue($logger->hasMessageContaining('error', 'failed publishing'));

        // Broker back: the next sweep picks the same quote up again.
        $publisher->failWith = null;
        $this->detector($resource, '2', $objectManager, $logger)->execute();

        $this->assertCount(1, $publisher->published);
        $this->assertSame(10, $publisher->published[0][1]['quote_id']);
        $this->assertArrayHasKey(10, $db->flags);
    }

    public function testUnavailablePublisherDegradesToDebugLogAndStillFlags(): void
    {
        // Soft dependency posture (class docblock): detection still runs and
        // still dedupes via the flag table; the missing publisher is a debug
        // note, never an error.
        $db = new FakeQuoteDb();
        $db->addQuote(10, $this->ago('-3 hours'));
        $logger = new RecordingLogger();

        $this->detector(
            new FakeResourceConnection($db),
            '2',
            new FakeObjectManager(null, new \RuntimeException('EventPublisher not instantiable')),
            $logger
        )->execute();

        $this->assertArrayHasKey(10, $db->flags, 'dedupe must still work without the publisher');
        $this->assertTrue(
            $logger->hasMessageContaining('debug', 'no EventPublisher is available'),
            'the degraded path is a debug log'
        );
        $this->assertCount(0, $logger->messagesAt('error'));
    }
}
