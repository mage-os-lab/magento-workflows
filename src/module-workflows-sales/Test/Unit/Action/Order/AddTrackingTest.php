<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Test\Unit\Action\Order;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Magento\Shipping\Model\Config as ShippingConfig;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsSales\Action\Order\AddTracking;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour coverage for order.add_tracking (ORD-A1): the track lands on the
 * order's LATEST shipment, an order with no shipment is a NON-retryable
 * failure (state won't self-heal), required config is enforced, the title
 * defaults to the carrier, and interpolated config values flow through
 * verbatim onto the track. simulate() never saves.
 */
class AddTrackingTest extends TestCase
{
    private function context(int $entityId = 42): ExecutionContext
    {
        return new ExecutionContext(new WorkflowExecutionStub(entityId: $entityId));
    }

    private function orderWithShipments(array $shipments): Order
    {
        return new class($shipments) extends Order {
            public function __construct(private readonly array $shipments)
            {
            }
            public function getEntityId(): int
            {
                return 42;
            }
            public function getIncrementId(): string
            {
                return '100000042';
            }
            /**
             * @return object
             */
            public function getShipmentsCollection()
            {
                $shipments = $this->shipments;
                return new class($shipments) {
                    public function __construct(private readonly array $shipments)
                    {
                    }
                    public function getItems(): array
                    {
                        return $this->shipments;
                    }
                };
            }
        };
    }

    /**
     * A shipment double. `$existing` seeds tracks the shipment already carries
     * as [carrier_code, track_number] pairs, so the natural-idempotence guard
     * has something to find.
     *
     * @param array<int, array{0: string, 1: string}> $existing
     */
    private function shipment(int $id, array $existing = []): object
    {
        $shipment = new class($id) {
            public array $tracks = [];
            public function __construct(private readonly int $id)
            {
            }
            public function getEntityId(): int
            {
                return $this->id;
            }
            public function addTrack($track)
            {
                $this->tracks[] = $track;
                return $this;
            }
            /**
             * Mirrors Shipment::getTracks() — the accessor the guard scans.
             */
            public function getTracks(): array
            {
                return $this->tracks;
            }
        };
        foreach ($existing as [$carrier, $number]) {
            $track = $this->track();
            $track->setCarrierCode($carrier);
            $track->setTrackNumber($number);
            $shipment->addTrack($track);
        }
        return $shipment;
    }

    private function track(): object
    {
        return new class {
            public ?string $carrierCode = null;
            public ?string $title = null;
            public ?string $trackNumber = null;
            public function setCarrierCode($v)
            {
                $this->carrierCode = $v;
                return $this;
            }
            public function setTitle($v)
            {
                $this->title = $v;
                return $this;
            }
            public function setTrackNumber($v)
            {
                $this->trackNumber = $v;
                return $this;
            }
            // Read side, used by the (carrier, number) idempotence scan.
            public function getCarrierCode()
            {
                return $this->carrierCode;
            }
            public function getTrackNumber()
            {
                return $this->trackNumber;
            }
        };
    }

    private function trackFactory(object $track): TrackFactory
    {
        return new class($track) extends TrackFactory {
            public function __construct(private readonly object $track)
            {
            }
            public function create(array $data = [])
            {
                return $this->track;
            }
        };
    }

    private function recordingShipmentRepository(): ShipmentRepositoryInterface
    {
        return new class implements ShipmentRepositoryInterface {
            public int $saves = 0;
            public mixed $lastSaved = null;
            public function save($entity)
            {
                $this->saves++;
                $this->lastSaved = $entity;
                return $entity;
            }
            // Full ShipmentRepositoryInterface surface (unused here).
            public function create() { throw new \BadMethodCallException(__METHOD__); }
            public function get($id) { throw new \BadMethodCallException(__METHOD__); }
            public function getList($searchCriteria) { throw new \BadMethodCallException(__METHOD__); }
            public function delete($entity) { throw new \BadMethodCallException(__METHOD__); }
        };
    }

    /**
     * @param array<string, string> $carriers carrier code => configured title
     */
    private function shippingConfig(array $carriers = ['ups' => 'United Parcel Service'], bool $throws = false): ShippingConfig
    {
        $models = [];
        foreach ($carriers as $code => $title) {
            $models[$code] = new class($title) {
                public function __construct(private readonly string $title)
                {
                }
                public function getConfigData($field)
                {
                    return $field === 'title' ? $this->title : null;
                }
            };
        }

        return new class($models, $throws) extends ShippingConfig {
            public function __construct(private readonly array $models, private readonly bool $throws)
            {
            }
            public function getActiveCarriers($store = null)
            {
                if ($this->throws) {
                    throw new \RuntimeException('shipping config unavailable');
                }
                return $this->models;
            }
        };
    }

    private function repositoryReturning(?Order $order): OrderRepositoryInterface
    {
        return new class($order) implements OrderRepositoryInterface {
            public function __construct(private readonly ?Order $order)
            {
            }
            // NB: real OrderRepositoryInterface::get($id) is UNTYPED — a typed
            // int param would narrow it (contravariance violation → fatal).
            public function get($orderId)
            {
                if ($this->order === null) {
                    throw new NoSuchEntityException(__('No such order %1', $orderId));
                }
                return $this->order;
            }
            public function getList($searchCriteria) { throw new \BadMethodCallException(__METHOD__); }
            public function save($entity) { throw new \BadMethodCallException(__METHOD__); }
            public function delete($entity) { throw new \BadMethodCallException(__METHOD__); }
            public function deleteById($id) { throw new \BadMethodCallException(__METHOD__); }
        };
    }

    public function testAttachesTrackToLatestShipmentAndSaves(): void
    {
        $track = $this->track();
        $order = $this->orderWithShipments([$this->shipment(500), $this->shipment(501)]);
        $shipmentRepo = $this->recordingShipmentRepository();
        $action = new AddTracking($this->repositoryReturning($order), $this->trackFactory($track), $shipmentRepo, $this->shippingConfig());

        $result = $action->execute($this->context(), [
            'carrier_code' => 'ups',
            'track_number' => '1Z999',
            'title' => 'UPS Ground',
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('ups', $track->carrierCode);
        $this->assertSame('UPS Ground', $track->title);
        $this->assertSame('1Z999', $track->trackNumber);
        $this->assertSame(1, $shipmentRepo->saves);
        // Latest = last item in the collection (501), not the first.
        $this->assertSame(501, $shipmentRepo->lastSaved->getEntityId());
        $this->assertSame(501, $result->getOutput()['shipment_id']);
        $this->assertSame('1Z999', $result->getOutput()['track_number']);
    }

    // -- redelivery guard: (carrier, number) is the parcel's identity ---------

    public function testRedeliveryOfTheSameTrackIsSkippedNotDuplicated(): void
    {
        // The crash-inside-the-step window: delivery #1 saved the track, the
        // step row was never marked complete, delivery #2 re-runs the action
        // against an order that already carries the number.
        $shipment = $this->shipment(501, [['ups', '1Z999']]);
        $order = $this->orderWithShipments([$this->shipment(500), $shipment]);
        $shipmentRepo = $this->recordingShipmentRepository();
        $action = new AddTracking(
            $this->repositoryReturning($order),
            $this->trackFactory($this->track()),
            $shipmentRepo,
            $this->shippingConfig()
        );

        $result = $action->execute($this->context(), ['carrier_code' => 'ups', 'track_number' => '1Z999']);

        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $result->getStatus());
        $this->assertSame(0, $shipmentRepo->saves, 'a redelivery must not append a second track row');
        $this->assertCount(1, $shipment->tracks);
        $this->assertSame(501, $result->getOutput()['shipment_id']);
        $this->assertSame('1Z999', $result->getOutput()['track_number']);
        $this->assertStringContainsString('already on shipment 501', (string)$result->getOutput()['reason']);
    }

    public function testGuardIsCaseAndWhitespaceInsensitiveOnBothHalves(): void
    {
        $shipment = $this->shipment(500, [['UPS', ' 1z999 ']]);
        $order = $this->orderWithShipments([$shipment]);
        $shipmentRepo = $this->recordingShipmentRepository();
        $action = new AddTracking(
            $this->repositoryReturning($order),
            $this->trackFactory($this->track()),
            $shipmentRepo,
            $this->shippingConfig()
        );

        $result = $action->execute($this->context(), ['carrier_code' => 'ups', 'track_number' => '1Z999']);

        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $result->getStatus());
        $this->assertSame(0, $shipmentRepo->saves);
    }

    public function testGuardSpansEveryShipmentNotJustTheLatest(): void
    {
        // A newer shipment appeared between the two deliveries: the number
        // lives on the OLD shipment, and re-adding it to the new one would be
        // exactly the customer-visible duplicate the guard exists to stop.
        $old = $this->shipment(500, [['ups', '1Z999']]);
        $new = $this->shipment(501);
        $order = $this->orderWithShipments([$old, $new]);
        $shipmentRepo = $this->recordingShipmentRepository();
        $action = new AddTracking(
            $this->repositoryReturning($order),
            $this->trackFactory($this->track()),
            $shipmentRepo,
            $this->shippingConfig()
        );

        $result = $action->execute($this->context(), ['carrier_code' => 'ups', 'track_number' => '1Z999']);

        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $result->getStatus());
        $this->assertSame(500, $result->getOutput()['shipment_id'], 'the holding shipment is reported');
        $this->assertSame([], $new->tracks);
        $this->assertSame(0, $shipmentRepo->saves);
    }

    public function testADifferentNumberOnTheSameCarrierStillAdds(): void
    {
        // Two parcels, same carrier: not a duplicate, must go through.
        $shipment = $this->shipment(500, [['ups', '1Z999']]);
        $order = $this->orderWithShipments([$shipment]);
        $shipmentRepo = $this->recordingShipmentRepository();
        $action = new AddTracking(
            $this->repositoryReturning($order),
            $this->trackFactory($this->track()),
            $shipmentRepo,
            $this->shippingConfig()
        );

        $result = $action->execute($this->context(), ['carrier_code' => 'ups', 'track_number' => '1Z888']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $shipmentRepo->saves);
        $this->assertCount(2, $shipment->tracks);
    }

    public function testSameNumberOnADifferentCarrierStillAdds(): void
    {
        $shipment = $this->shipment(500, [['ups', '1Z999']]);
        $order = $this->orderWithShipments([$shipment]);
        $shipmentRepo = $this->recordingShipmentRepository();
        $action = new AddTracking(
            $this->repositoryReturning($order),
            $this->trackFactory($this->track()),
            $shipmentRepo,
            $this->shippingConfig()
        );

        $result = $action->execute($this->context(), ['carrier_code' => 'fedex', 'track_number' => '1Z999']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $shipmentRepo->saves);
    }

    public function testAFailingTrackScanParksForRetryInsteadOfAppendingBlind(): void
    {
        $shipment = new class {
            public function getEntityId(): int
            {
                return 500;
            }
            public function getTracks(): array
            {
                throw new \RuntimeException('track collection unavailable');
            }
            public function addTrack($track)
            {
                throw new \BadMethodCallException('must not be reached');
            }
        };
        $order = $this->orderWithShipments([$shipment]);
        $shipmentRepo = $this->recordingShipmentRepository();
        $action = new AddTracking(
            $this->repositoryReturning($order),
            $this->trackFactory($this->track()),
            $shipmentRepo,
            $this->shippingConfig()
        );

        $result = $action->execute($this->context(), ['carrier_code' => 'ups', 'track_number' => '1Z999']);

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isRetryable(), 'an unanswered dedupe question is retryable, not a duplicate');
        $this->assertStringContainsString('existing tracking', (string)$result->getError());
        $this->assertSame(0, $shipmentRepo->saves);
    }

    public function testShipmentWithoutATracksAccessorDoesNotBreakTheAdd(): void
    {
        // Partial doubles / exotic extensions: the guard degrades to "carries
        // nothing" rather than failing the step.
        $shipment = new class {
            public array $tracks = [];
            public function getEntityId(): int
            {
                return 500;
            }
            public function addTrack($track)
            {
                $this->tracks[] = $track;
                return $this;
            }
        };
        $order = $this->orderWithShipments([$shipment]);
        $shipmentRepo = $this->recordingShipmentRepository();
        $action = new AddTracking(
            $this->repositoryReturning($order),
            $this->trackFactory($this->track()),
            $shipmentRepo,
            $this->shippingConfig()
        );

        $result = $action->execute($this->context(), ['carrier_code' => 'ups', 'track_number' => '1Z999']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $shipmentRepo->saves);
    }

    public function testNoShipmentIsNonRetryableFailure(): void
    {
        $order = $this->orderWithShipments([]);
        $shipmentRepo = $this->recordingShipmentRepository();
        $action = new AddTracking($this->repositoryReturning($order), $this->trackFactory($this->track()), $shipmentRepo, $this->shippingConfig());

        $result = $action->execute($this->context(), ['carrier_code' => 'ups', 'track_number' => '1Z999']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable(), 'a missing shipment will not appear on redelivery');
        $this->assertStringContainsString('no shipment', (string)$result->getError());
        $this->assertSame(0, $shipmentRepo->saves);
    }

    public function testMissingCarrierCodeIsRejected(): void
    {
        $order = $this->orderWithShipments([$this->shipment(500)]);
        $action = new AddTracking($this->repositoryReturning($order), $this->trackFactory($this->track()), $this->recordingShipmentRepository(), $this->shippingConfig());

        $result = $action->execute($this->context(), ['track_number' => '1Z999']);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('carrier_code', (string)$result->getError());
    }

    public function testMissingTrackNumberIsRejected(): void
    {
        $order = $this->orderWithShipments([$this->shipment(500)]);
        $action = new AddTracking($this->repositoryReturning($order), $this->trackFactory($this->track()), $this->recordingShipmentRepository(), $this->shippingConfig());

        $result = $action->execute($this->context(), ['carrier_code' => 'ups']);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('track_number', (string)$result->getError());
    }

    public function testTitleDefaultsToCarrierCode(): void
    {
        $track = $this->track();
        $order = $this->orderWithShipments([$this->shipment(500)]);
        $action = new AddTracking($this->repositoryReturning($order), $this->trackFactory($track), $this->recordingShipmentRepository(), $this->shippingConfig());

        $result = $action->execute($this->context(), ['carrier_code' => 'fedex', 'track_number' => '77']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('fedex', $track->title, 'title falls back to the carrier code');
    }

    public function testInterpolatedTrackNumberFlowsThroughVerbatim(): void
    {
        // Config values arrive already interpolated ({{ trigger.tracking }} ->
        // concrete string); the action must pass the resolved value straight to
        // the track without further processing.
        $track = $this->track();
        $order = $this->orderWithShipments([$this->shipment(500)]);
        $action = new AddTracking($this->repositoryReturning($order), $this->trackFactory($track), $this->recordingShipmentRepository(), $this->shippingConfig());

        $result = $action->execute($this->context(), [
            'carrier_code' => 'dhl',
            'track_number' => 'RESOLVED-ABC-123',
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('RESOLVED-ABC-123', $track->trackNumber);
    }

    public function testOrderNotFoundIsFailure(): void
    {
        $action = new AddTracking($this->repositoryReturning(null), $this->trackFactory($this->track()), $this->recordingShipmentRepository(), $this->shippingConfig());

        $result = $action->execute($this->context(), ['carrier_code' => 'ups', 'track_number' => '1Z999']);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('not found', (string)$result->getError());
    }

    public function testSimulateDescribesWithoutSaving(): void
    {
        $order = $this->orderWithShipments([$this->shipment(500)]);
        $shipmentRepo = $this->recordingShipmentRepository();
        $action = new AddTracking($this->repositoryReturning($order), $this->trackFactory($this->track()), $shipmentRepo, $this->shippingConfig());

        $result = $action->simulate($this->context(), ['carrier_code' => 'ups', 'track_number' => '1Z999']);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getOutput()['simulated']);
        $this->assertSame(0, $shipmentRepo->saves, 'simulate() must not save');
    }

    public function testSimulateFailsWhenNoShipment(): void
    {
        $order = $this->orderWithShipments([]);
        $action = new AddTracking($this->repositoryReturning($order), $this->trackFactory($this->track()), $this->recordingShipmentRepository(), $this->shippingConfig());

        $result = $action->simulate($this->context(), ['carrier_code' => 'ups', 'track_number' => '1Z999']);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('no shipment', (string)$result->getError());
    }

    public function testConfigFormOffersTheActiveCarriersAsABoundedSelect(): void
    {
        $action = new AddTracking(
            $this->repositoryReturning(null),
            $this->trackFactory($this->track()),
            $this->recordingShipmentRepository(),
            $this->shippingConfig(['ups' => 'United Parcel Service', 'flatrate' => ''])
        );

        $field = $action->getConfigForm()[0];

        $this->assertSame('carrier_code', $field['name']);
        $this->assertSame('select', $field['type']);
        $this->assertSame([
            ['value' => 'ups', 'label' => 'United Parcel Service'],
            // No configured title: fall back to the code the track will carry.
            ['value' => 'flatrate', 'label' => 'flatrate'],
        ], $field['options']);
    }

    public function testConfigFormDegradesToTextWhenTheShippingConfigThrows(): void
    {
        $action = new AddTracking(
            $this->repositoryReturning(null),
            $this->trackFactory($this->track()),
            $this->recordingShipmentRepository(),
            $this->shippingConfig(throws: true)
        );

        $field = $action->getConfigForm()[0];

        $this->assertSame('carrier_code', $field['name']);
        $this->assertSame('text', $field['type'], 'an unavailable carrier list must not break the form');
        $this->assertFalse(isset($field['options']));
        $this->assertTrue($field['required']);
    }

    public function testConfigFormLeavesTheSelectOptionlessWhenNoCarrierIsActive(): void
    {
        $action = new AddTracking(
            $this->repositoryReturning(null),
            $this->trackFactory($this->track()),
            $this->recordingShipmentRepository(),
            $this->shippingConfig([])
        );

        $field = $action->getConfigForm()[0];

        $this->assertSame('select', $field['type']);
        $this->assertFalse(isset($field['options']));
    }
}
