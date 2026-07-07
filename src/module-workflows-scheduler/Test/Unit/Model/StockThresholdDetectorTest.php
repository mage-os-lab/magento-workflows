<?php
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Unit\Model;

use MageOS\Workflows\Test\Unit\Stub\StubScopeConfig;
use MageOS\WorkflowsScheduler\Model\StockThresholdDetector;
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
 * - recovery strictly ABOVE the threshold unflags (re-arms), so a later dip
 *   fires again;
 * - a publish failure leaves the product UNflagged so the next run retries;
 * - a missing/uninstantiable EventPublisher (deliberate soft dependency)
 *   degrades to a debug log, and the product is still flagged so it is not
 *   re-logged every 10 minutes.
 *
 * The FakeStockDb evaluates the qty comparisons with the operator and bound
 * value the detector's own queries emit, so the <=-fires / >-re-arms
 * boundary is production behavior, not fake behavior.
 */
class StockThresholdDetectorTest extends TestCase
{
    private const EVENT = 'inventory.stock_threshold_crossed';

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

        // Dip 1: fires and flags.
        $this->detector($resource, '5', $objectManager)->execute();
        $this->assertCount(1, $publisher->published);

        // Hovering AT the boundary is not a recovery ("unflagged only once
        // qty recovers *above* the threshold") — flag must survive.
        $db->setQty(42, 5.0);
        $this->detector($resource, '5', $objectManager)->execute();
        $this->assertArrayHasKey(42, $db->flags, 'qty equal to the threshold must NOT re-arm');
        $this->assertCount(1, $publisher->published);

        // Genuine recovery strictly above: flag deleted, trigger re-armed.
        $db->setQty(42, 9.0);
        $this->detector($resource, '5', $objectManager)->execute();
        $this->assertFalse(array_key_exists(42, $db->flags), 'recovery above the threshold must unflag');
        $this->assertCount(1, $publisher->published, 'recovery itself does not publish');

        // Dip 2: fires again because the flag was cleared.
        $db->setQty(42, 2.0);
        $this->detector($resource, '5', $objectManager)->execute();
        $this->assertCount(2, $publisher->published, 'a re-armed product must fire on the next downward crossing');
        $this->assertArrayHasKey(42, $db->flags);
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
