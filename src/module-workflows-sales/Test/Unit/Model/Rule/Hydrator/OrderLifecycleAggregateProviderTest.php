<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Test\Unit\Model\Rule\Hydrator;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use MageOS\WorkflowsSales\Model\Rule\Hydrator\OrderLifecycleAggregateProvider;
use PHPUnit\Framework\TestCase;

/**
 * Provider computation for the order lifecycle-flag aggregates (ORD-C1):
 * booleans come straight from the order model's guards, counts from the
 * document collections, and a non-loadable order yields NO aggregates (absent,
 * fail-toward-false) rather than a row of falses.
 */
class OrderLifecycleAggregateProviderTest extends TestCase
{
    private function order(
        bool $canInvoice,
        bool $canShip,
        bool $canCreditmemo,
        bool $isVirtual,
        int $invoiceCount,
        int $shipmentCount
    ): Order {
        return new class(
            $canInvoice,
            $canShip,
            $canCreditmemo,
            $isVirtual,
            $invoiceCount,
            $shipmentCount
        ) extends Order {
            public function __construct(
                private readonly bool $canInvoiceV,
                private readonly bool $canShipV,
                private readonly bool $canCreditmemoV,
                private readonly bool $isVirtualV,
                private readonly int $invoiceCount,
                private readonly int $shipmentCount
            ) {
            }

            public function canInvoice(): bool
            {
                return $this->canInvoiceV;
            }

            public function canShip(): bool
            {
                return $this->canShipV;
            }

            public function canCreditmemo(): bool
            {
                return $this->canCreditmemoV;
            }

            /**
             * @return mixed
             */
            public function getIsVirtual()
            {
                return $this->isVirtualV;
            }

            /**
             * @return object
             */
            public function getInvoiceCollection()
            {
                return $this->sizedCollection($this->invoiceCount);
            }

            /**
             * @return object
             */
            public function getShipmentsCollection()
            {
                return $this->sizedCollection($this->shipmentCount);
            }

            private function sizedCollection(int $size): object
            {
                return new class($size) {
                    public function __construct(private readonly int $size)
                    {
                    }
                    public function getSize(): int
                    {
                        return $this->size;
                    }
                };
            }
        };
    }

    private function repositoryReturning(?Order $order): OrderRepositoryInterface
    {
        return new class($order) implements OrderRepositoryInterface {
            public function __construct(private readonly ?Order $order)
            {
            }
            public function get(int $orderId)
            {
                if ($this->order === null) {
                    throw new NoSuchEntityException(__('No such order %1', $orderId));
                }
                return $this->order;
            }
        };
    }

    public function testComputesGuardsAndCounts(): void
    {
        $provider = new OrderLifecycleAggregateProvider(
            $this->repositoryReturning($this->order(
                canInvoice: true,
                canShip: false,
                canCreditmemo: true,
                isVirtual: false,
                invoiceCount: 2,
                shipmentCount: 1
            ))
        );

        $aggregates = $provider->getAggregates(5);

        $this->assertSame(1, $aggregates['can_invoice']);
        $this->assertSame(0, $aggregates['can_ship']);
        $this->assertSame(1, $aggregates['can_creditmemo']);
        $this->assertSame(0, $aggregates['is_virtual']);
        $this->assertSame(2, $aggregates['invoice_count']);
        $this->assertSame(1, $aggregates['shipment_count']);
    }

    public function testVirtualOrderReportsIsVirtualTrue(): void
    {
        $provider = new OrderLifecycleAggregateProvider(
            $this->repositoryReturning($this->order(
                canInvoice: true,
                canShip: false,
                canCreditmemo: false,
                isVirtual: true,
                invoiceCount: 0,
                shipmentCount: 0
            ))
        );

        $aggregates = $provider->getAggregates(5);

        $this->assertSame(1, $aggregates['is_virtual']);
        $this->assertSame(0, $aggregates['shipment_count']);
    }

    public function testMissingOrderYieldsNoAggregates(): void
    {
        $provider = new OrderLifecycleAggregateProvider($this->repositoryReturning(null));

        $this->assertSame([], $provider->getAggregates(999), 'absent -> fail-toward-false, not a row of falses');
    }

    public function testAttributeMetadataAdvertisesBooleanAndNumericTypes(): void
    {
        $provider = new OrderLifecycleAggregateProvider($this->repositoryReturning(null));
        $meta = $provider->getAttributeMetadata();

        $this->assertSame('boolean', $meta['can_ship']['input_type']);
        $this->assertSame('boolean', $meta['is_virtual']['input_type']);
        $this->assertSame('numeric', $meta['invoice_count']['input_type']);
        $this->assertSame('numeric', $meta['hours_in_current_status']['input_type']);
        $this->assertArrayHasKey('label', $meta['can_invoice']);
    }

    // --- ORD-C3: hours_in_current_status -------------------------------------

    private const NOW = 1_700_000_000;

    /**
     * A history row is an object exposing getStatus() + getCreatedAt(), the
     * only surface the provider reads.
     */
    private function history(string $status, ?string $createdAt): object
    {
        return new class($status, $createdAt) {
            public function __construct(private readonly string $status, private readonly ?string $createdAt)
            {
            }
            public function getStatus(): string
            {
                return $this->status;
            }
            public function getCreatedAt(): ?string
            {
                return $this->createdAt;
            }
        };
    }

    /**
     * Order carrying a current status, a created_at and a status-history list,
     * plus the lifecycle surface getAggregates() always reads.
     *
     * @param array<int, object> $histories
     */
    private function statusOrder(string $status, ?string $createdAt, array $histories): Order
    {
        return new class($status, $createdAt, $histories) extends Order {
            /**
             * @param array<int, object> $histories
             */
            public function __construct(
                private readonly string $statusV,
                private readonly ?string $createdAtV,
                private readonly array $historiesV
            ) {
            }
            public function getStatus(): string
            {
                return $this->statusV;
            }
            public function getCreatedAt(): ?string
            {
                return $this->createdAtV;
            }
            public function getStatusHistories(): array
            {
                return $this->historiesV;
            }
            public function canInvoice(): bool
            {
                return false;
            }
            public function canShip(): bool
            {
                return false;
            }
            public function canCreditmemo(): bool
            {
                return false;
            }
            /**
             * @return mixed
             */
            public function getIsVirtual()
            {
                return false;
            }
            /**
             * @return object
             */
            public function getInvoiceCollection()
            {
                return new class {
                    public function getSize(): int
                    {
                        return 0;
                    }
                };
            }
            /**
             * @return object
             */
            public function getShipmentsCollection()
            {
                return new class {
                    public function getSize(): int
                    {
                        return 0;
                    }
                };
            }
        };
    }

    private function providerWithClock(OrderRepositoryInterface $repository, int $now): OrderLifecycleAggregateProvider
    {
        return new class($repository, $now) extends OrderLifecycleAggregateProvider {
            public function __construct(OrderRepositoryInterface $repository, private readonly int $now)
            {
                parent::__construct($repository);
            }
            protected function currentTimestamp(): int
            {
                return $this->now;
            }
        };
    }

    public function testHoursInCurrentStatusUsesLatestMatchingHistoryRow(): void
    {
        // Two 'processing' rows (5h and 2h ago) + an older 'pending' row: the
        // reference is the LATEST 'processing' row, so 2 hours, not 5.
        $order = $this->statusOrder('processing', gmdate('Y-m-d H:i:s', self::NOW - 9 * 3600), [
            $this->history('pending', gmdate('Y-m-d H:i:s', self::NOW - 9 * 3600)),
            $this->history('processing', gmdate('Y-m-d H:i:s', self::NOW - 5 * 3600)),
            $this->history('processing', gmdate('Y-m-d H:i:s', self::NOW - 2 * 3600)),
        ]);
        $provider = $this->providerWithClock($this->repositoryReturning($order), self::NOW);

        $aggregates = $provider->getAggregates(7);

        $this->assertSame(2.0, $aggregates['hours_in_current_status']);
    }

    public function testHoursInCurrentStatusRoundsHalfUpToTwoDecimals(): void
    {
        // 90 minutes + 30 seconds ago => 1.508333.. hours => 1.51 (half-up).
        $order = $this->statusOrder('holded', null, [
            $this->history('holded', gmdate('Y-m-d H:i:s', self::NOW - (90 * 60 + 30))),
        ]);
        $provider = $this->providerWithClock($this->repositoryReturning($order), self::NOW);

        $this->assertSame(1.51, $provider->getAggregates(7)['hours_in_current_status']);
    }

    public function testHoursInCurrentStatusFallsBackToCreatedAtWhenNoHistoryMatches(): void
    {
        // Current status 'complete' has no matching history row; fall back to
        // created_at (7 hours ago).
        $order = $this->statusOrder('complete', gmdate('Y-m-d H:i:s', self::NOW - 7 * 3600), [
            $this->history('processing', gmdate('Y-m-d H:i:s', self::NOW - 8 * 3600)),
        ]);
        $provider = $this->providerWithClock($this->repositoryReturning($order), self::NOW);

        $this->assertSame(7.0, $provider->getAggregates(7)['hours_in_current_status']);
    }

    public function testHoursInCurrentStatusClampsFutureReferenceToZero(): void
    {
        // A created_at slightly in the future (clock skew) must read 0, never
        // negative.
        $order = $this->statusOrder('processing', gmdate('Y-m-d H:i:s', self::NOW + 120), []);
        $provider = $this->providerWithClock($this->repositoryReturning($order), self::NOW);

        $this->assertSame(0.0, $provider->getAggregates(7)['hours_in_current_status']);
    }

    public function testHoursInCurrentStatusAbsentWhenIndeterminable(): void
    {
        // No matching history AND no parseable created_at => attribute absent
        // (fail-toward-false), the other aggregates still present.
        $order = $this->statusOrder('processing', null, []);
        $provider = $this->providerWithClock($this->repositoryReturning($order), self::NOW);

        $aggregates = $provider->getAggregates(7);

        $this->assertFalse(array_key_exists('hours_in_current_status', $aggregates));
        $this->assertArrayHasKey('can_invoice', $aggregates);
    }
}
