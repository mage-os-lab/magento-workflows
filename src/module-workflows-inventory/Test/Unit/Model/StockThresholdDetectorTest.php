<?php
declare(strict_types=1);

namespace MageOS\WorkflowsInventory\Test\Unit\Model;

use MageOS\Workflows\Test\Unit\Stub\StubScopeConfig;
use MageOS\WorkflowsInventory\Model\StockThresholdDetector;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\FakeObjectManager;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\FakeResourceConnection;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\FakeStockDb;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\RecordingLogger;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\RecordingPublisher;
use PHPUnit\Framework\TestCase;

/**
 * StockThresholdDetector hysteresis, per docs/05-triggers.md footnote 2 and
 * the class docblock:
 *
 * - threshold config 0 (or negative/empty) disables the detector entirely;
 * - a managed product crossing DOWN "to or at" the threshold publishes
 *   inventory.stock_threshold_crossed once and is flagged;
 * - while it stays at/below the threshold, the flag suppresses re-fires
 *   (a product hovering at the boundary fires once, not every 10 minutes);
 * - recovery strictly ABOVE the threshold publishes 'inventory.back_in_stock'
 *   once (INV-T1) and unflags (re-arms), so a later dip fires the crossing
 *   trigger again; the back-in-stock announcement itself fires once per
 *   flag/unflag cycle, never on a still-recovered re-run;
 * - a crossing publish failure leaves the product UNflagged so the next run
 *   retries; a recovery publish failure leaves the product FLAGGED so the next
 *   run retries the back-in-stock announcement (and the crossing trigger stays
 *   disarmed until then);
 * - a missing/uninstantiable EventPublisher (deliberate soft dependency)
 *   degrades to a debug log, and the flag state is still updated (flagged on a
 *   crossing, cleared on a recovery) so it is not re-logged every 10 minutes.
 *
 * The FakeStockDb evaluates the qty comparisons with the operator and bound
 * value the detector's own queries emit, so the <=-fires / >-re-arms
 * boundary is production behavior, not fake behavior.
 */
class StockThresholdDetectorTest extends TestCase
{
    private const EVENT = 'inventory.stock_threshold_crossed';
    private const EVENT_BACK_IN_STOCK = 'inventory.back_in_stock';

    /**
     * Payloads published under one event name, in order.
     *
     * @return array<int, array<string, mixed>>
     */
    private function payloadsFor(RecordingPublisher $publisher, string $event): array
    {
        $rows = [];
        foreach ($publisher->published as [$name, $payload]) {
            if ($name === $event) {
                $rows[] = $payload;
            }
        }
        return $rows;
    }

    private function detector(
        FakeResourceConnection $resource,
        ?string $threshold,
        FakeObjectManager $objectManager,
        ?RecordingLogger $logger = null
    ): StockThresholdDetector {
        return new StockThresholdDetector(
            $resource,
            new StubScopeConfig(
                $threshold === null ? [] : ['mageos_workflows/scheduler/stock_threshold' => $threshold]
            ),
            $logger ?? new RecordingLogger(),
            $objectManager
        );
    }

    public function testZeroThresholdDisablesTheDetector(): void
    {
        // No connection configured: any DB touch would throw and fail the test.
        $resource = new FakeResourceConnection();
        $objectManager = new FakeObjectManager(new RecordingPublisher());
        $logger = new RecordingLogger();

        $this->detector($resource, '0', $objectManager, $logger)->execute();

        $this->assertSame(0, $resource->getConnectionCalls, 'a disabled detector must not touch the database');
        $this->assertSame(0, $objectManager->getCalls, 'a disabled detector must not resolve the publisher');
        $this->assertTrue(
            $logger->hasMessageContaining('debug', 'disabled'),
            'disabling by configuration is a debug-level note, not an error'
        );
    }

    public function testNegativeThresholdDisablesTheDetector(): void
    {
        $resource = new FakeResourceConnection();
        $objectManager = new FakeObjectManager(new RecordingPublisher());

        $this->detector($resource, '-3', $objectManager)->execute();

        $this->assertSame(0, $resource->getConnectionCalls);
        $this->assertSame(0, $objectManager->getCalls);
    }

    public function testDownwardCrossingPublishesOnceAndFlagsTheProduct(): void
    {
        $db = new FakeStockDb();
        $db->addProduct(42, 3.0, 'SKU-42');   // below threshold -> fires
        $db->addProduct(43, 10.0, 'SKU-43');  // above threshold -> silent
        $publisher = new RecordingPublisher();

        $this->detector(
            new FakeResourceConnection($db),
            '5',
            new FakeObjectManager($publisher)
        )->execute();

        $this->assertCount(1, $publisher->published, 'exactly one product crossed the threshold');
        [$event, $payload] = $publisher->published[0];
        $this->assertSame(self::EVENT, $event);
        $this->assertSame(42, $payload['product_id']);
        $this->assertSame('SKU-42', $payload['sku']);
        $this->assertSame(3.0, $payload['qty']);
        $this->assertSame(5.0, $payload['threshold']);

        $this->assertArrayHasKey(42, $db->flags, 'the fired product must be flagged (hysteresis)');
        $this->assertFalse(array_key_exists(43, $db->flags), 'a product above the threshold must not be flagged');
    }

    public function testQtyExactlyAtThresholdFires(): void
    {
        // Docs: "drops to or at" the threshold — the boundary itself fires.
        $db = new FakeStockDb();
        $db->addProduct(44, 5.0, 'SKU-44');
        $publisher = new RecordingPublisher();

        $this->detector(
            new FakeResourceConnection($db),
            '5',
            new FakeObjectManager($publisher)
        )->execute();

        $this->assertCount(1, $publisher->published);
        $this->assertSame(44, $publisher->published[0][1]['product_id']);
    }

    public function testStillBelowThresholdOnNextRunDoesNotRefire(): void
    {
        $db = new FakeStockDb();
        $db->addProduct(42, 3.0, 'SKU-42');
        $publisher = new RecordingPublisher();
        $resource = new FakeResourceConnection($db);
        $objectManager = new FakeObjectManager($publisher);

        $this->detector($resource, '5', $objectManager)->execute();
        // Ten minutes later, qty unchanged: the flag must suppress a re-fire.
        $this->detector($resource, '5', $objectManager)->execute();

        $this->assertCount(1, $publisher->published, 'a product still below the threshold must not fire again');
        $this->assertSame(1, $publisher->attempts, 'the second run must not even attempt a publish');
        $this->assertArrayHasKey(42, $db->flags, 'the flag stays while the product is below the threshold');
    }

    public function testRecoveryAboveThresholdRearmsSoALaterDipFiresAgain(): void
    {
        $db = new FakeStockDb();
        $db->addProduct(42, 3.0, 'SKU-42');
        $publisher = new RecordingPublisher();
        $resource = new FakeResourceConnection($db);
        $objectManager = new FakeObjectManager($publisher);

        // Dip 1: fires the crossing trigger and flags.
        $this->detector($resource, '5', $objectManager)->execute();
        $this->assertCount(1, $this->payloadsFor($publisher, self::EVENT));

        // Hovering AT the boundary is not a recovery ("unflagged only once
        // qty recovers *above* the threshold") — flag must survive, nothing
        // is published.
        $db->setQty(42, 5.0);
        $this->detector($resource, '5', $objectManager)->execute();
        $this->assertArrayHasKey(42, $db->flags, 'qty equal to the threshold must NOT re-arm');
        $this->assertCount(0, $this->payloadsFor($publisher, self::EVENT_BACK_IN_STOCK));

        // Genuine recovery strictly above: back-in-stock fires, flag deleted,
        // crossing trigger re-armed.
        $db->setQty(42, 9.0);
        $this->detector($resource, '5', $objectManager)->execute();
        $this->assertFalse(array_key_exists(42, $db->flags), 'recovery above the threshold must unflag');
        $this->assertCount(1, $this->payloadsFor($publisher, self::EVENT_BACK_IN_STOCK), 'recovery publishes once');
        $this->assertCount(1, $this->payloadsFor($publisher, self::EVENT), 'recovery does not re-fire the crossing');

        // Dip 2: fires the crossing again because the flag was cleared.
        $db->setQty(42, 2.0);
        $this->detector($resource, '5', $objectManager)->execute();
        $this->assertCount(2, $this->payloadsFor($publisher, self::EVENT), 'a re-armed product fires on the next dip');
        $this->assertArrayHasKey(42, $db->flags);
    }

    public function testRecoveryPublishesBackInStockOnceThenGoesSilent(): void
    {
        // The publish-on-recovery behavior (INV-T1) and its once-per-cycle
        // guarantee: a product that recovers publishes back_in_stock exactly
        // once, and a subsequent run while still above the threshold does NOT
        // re-publish (the flag is already cleared — nothing left to recover).
        $db = new FakeStockDb();
        $db->addProduct(42, 3.0, 'SKU-42');
        $publisher = new RecordingPublisher();
        $resource = new FakeResourceConnection($db);
        $objectManager = new FakeObjectManager($publisher);

        // Dip below → flag.
        $this->detector($resource, '5', $objectManager)->execute();
        $this->assertArrayHasKey(42, $db->flags);

        // Recover above → one back_in_stock, carrying the recovered qty + sku.
        $db->setQty(42, 12.0);
        $this->detector($resource, '5', $objectManager)->execute();
        $backInStock = $this->payloadsFor($publisher, self::EVENT_BACK_IN_STOCK);
        $this->assertCount(1, $backInStock, 'recovery publishes inventory.back_in_stock once');
        $this->assertSame(42, $backInStock[0]['product_id']);
        $this->assertSame(42, $backInStock[0]['entity_id']);
        $this->assertSame('SKU-42', $backInStock[0]['sku']);
        $this->assertSame(12.0, $backInStock[0]['qty'], 'the payload carries the RECOVERED qty');
        $this->assertSame(5.0, $backInStock[0]['threshold']);
        $this->assertFalse(array_key_exists(42, $db->flags), 'recovery clears the flag');

        // Still above the threshold on the next run: no re-publish (no flag to
        // recover — the once-per-cycle guarantee).
        $this->detector($resource, '5', $objectManager)->execute();
        $this->assertCount(
            1,
            $this->payloadsFor($publisher, self::EVENT_BACK_IN_STOCK),
            'back_in_stock fires once per flag/unflag cycle, not every run'
        );
    }

    public function testRecoveryPublishFailureKeepsFlagSoNextRunRetries(): void
    {
        // Symmetry with the crossing retry path: if publishing back_in_stock
        // fails, the flag must survive so the announcement is retried — and the
        // product is NOT re-armed for the crossing trigger in the meantime.
        $db = new FakeStockDb();
        $db->addProduct(42, 3.0, 'SKU-42');
        $publisher = new RecordingPublisher();
        $logger = new RecordingLogger();
        $resource = new FakeResourceConnection($db);
        $objectManager = new FakeObjectManager($publisher);

        // Dip below (broker healthy) → flag.
        $this->detector($resource, '5', $objectManager, $logger)->execute();
        $this->assertArrayHasKey(42, $db->flags);

        // Recover above, but the broker is down: back_in_stock publish fails,
        // the flag must remain so recovery is retried.
        $db->setQty(42, 9.0);
        $publisher->failWith = new \RuntimeException('amqp connection refused');
        $this->detector($resource, '5', $objectManager, $logger)->execute();
        $this->assertCount(0, $this->payloadsFor($publisher, self::EVENT_BACK_IN_STOCK));
        $this->assertArrayHasKey(42, $db->flags, 'a failed recovery publish must keep the flag');
        $this->assertTrue($logger->hasMessageContaining('error', 'failed publishing'));

        // Broker back: the next run retries and only then unflags.
        $publisher->failWith = null;
        $this->detector($resource, '5', $objectManager, $logger)->execute();
        $this->assertCount(1, $this->payloadsFor($publisher, self::EVENT_BACK_IN_STOCK));
        $this->assertFalse(array_key_exists(42, $db->flags), 'a successful recovery publish unflags');
    }

    public function testRecoveryUnflagsWithoutPublisherDegradesToDebugLog(): void
    {
        // Soft-dependency posture on the recovery path: with no EventPublisher,
        // there is nothing to announce, so recovery simply clears the flag
        // (re-arm still works) and logs at debug level.
        $db = new FakeStockDb();
        $db->addProduct(42, 3.0, 'SKU-42');
        $logger = new RecordingLogger();
        $resource = new FakeResourceConnection($db);

        // Dip below with no publisher → flag (existing degraded behavior).
        $this->detector(
            $resource,
            '5',
            new FakeObjectManager(null, new \RuntimeException('EventPublisher not instantiable')),
            $logger
        )->execute();
        $this->assertArrayHasKey(42, $db->flags);

        // Recover above with no publisher → flag cleared, debug log, no error.
        $db->setQty(42, 9.0);
        $this->detector(
            $resource,
            '5',
            new FakeObjectManager(null, new \RuntimeException('EventPublisher not instantiable')),
            $logger
        )->execute();
        $this->assertFalse(array_key_exists(42, $db->flags), 'recovery re-arms even without a publisher');
        $this->assertTrue($logger->hasMessageContaining('debug', 'recovered above the stock threshold'));
        $this->assertCount(0, $logger->messagesAt('error'));
    }

    public function testPublishFailureLeavesProductUnflaggedSoNextRunRetries(): void
    {
        $db = new FakeStockDb();
        $db->addProduct(42, 3.0, 'SKU-42');
        $publisher = new RecordingPublisher();
        $publisher->failWith = new \RuntimeException('amqp connection refused');
        $logger = new RecordingLogger();
        $resource = new FakeResourceConnection($db);
        $objectManager = new FakeObjectManager($publisher);

        $this->detector($resource, '5', $objectManager, $logger)->execute();

        $this->assertCount(0, $publisher->published);
        $this->assertFalse(
            array_key_exists(42, $db->flags),
            'a product whose publish failed must NOT be flagged, so the next run retries it'
        );
        $this->assertTrue($logger->hasMessageContaining('error', 'failed publishing'));

        // Broker back: the very next run retries and only then flags.
        $publisher->failWith = null;
        $this->detector($resource, '5', $objectManager, $logger)->execute();

        $this->assertCount(1, $publisher->published);
        $this->assertSame(42, $publisher->published[0][1]['product_id']);
        $this->assertArrayHasKey(42, $db->flags);
    }

    public function testUnavailablePublisherDegradesToDebugLogAndStillFlags(): void
    {
        // Soft dependency posture: EventPublisher cannot be resolved. The
        // detector must not blow up; it logs at debug level and still flags
        // the product so it is not re-logged every 10 minutes.
        $db = new FakeStockDb();
        $db->addProduct(42, 3.0, 'SKU-42');
        $logger = new RecordingLogger();

        $this->detector(
            new FakeResourceConnection($db),
            '5',
            new FakeObjectManager(null, new \RuntimeException('EventPublisher not instantiable')),
            $logger
        )->execute();

        $this->assertArrayHasKey(42, $db->flags, 'detection/dedupe must still work without the publisher');
        $this->assertTrue(
            $logger->hasMessageContaining('debug', 'no EventPublisher is available'),
            'the degraded path is a debug log, not an error'
        );
        $this->assertCount(0, $logger->messagesAt('error'));
    }
}
