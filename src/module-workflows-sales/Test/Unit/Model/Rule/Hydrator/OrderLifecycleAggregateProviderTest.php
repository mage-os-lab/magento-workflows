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
        $this->assertArrayHasKey('label', $meta['can_invoice']);
    }
}
