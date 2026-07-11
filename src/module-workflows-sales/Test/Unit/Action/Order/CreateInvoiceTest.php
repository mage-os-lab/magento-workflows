<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Test\Unit\Action\Order;

use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Service\InvoiceService;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsSales\Action\Order\CreateInvoice;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour coverage for order.create_invoice: a regression here captures
 * payment twice under queue redelivery or captures online when offline was
 * configured. Pins the docs/07-actions.md promise "create invoice (capture
 * online/offline)" guarded by canInvoice().
 */
class CreateInvoiceTest extends TestCase
{
    public function testExecuteInvalidCaptureModeIsTerminalFailureBeforeTouchingTheOrder(): void
    {
        $fixture = $this->createFixture(canInvoice: true);
        $action = $fixture['action'];

        $result = $action->execute($this->createContext(), ['capture' => 'sideways']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('Invalid capture mode', (string)$result->getError());
        $this->assertSame(0, $fixture['repository']->getCalls, 'Config must be rejected before loading the order');
        $this->assertSame(0, $fixture['invoiceService']->prepareCalls);
    }

    public function testExecuteSkipsWhenOrderCannotBeInvoicedAndNeverPreparesInvoice(): void
    {
        $fixture = $this->createFixture(canInvoice: false);
        $action = $fixture['action'];

        $result = $action->execute($this->createContext(), []);

        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $result->getStatus());
        $this->assertFalse($result->isFailure());
        $this->assertSame(0, $fixture['invoiceService']->prepareCalls, 'A fully invoiced order must skip, not re-invoice');
        $this->assertSame(0, $fixture['transactionFactory']->createCalls);
        $this->assertStringContainsString('cannot be invoiced', (string)($result->getOutput()['reason'] ?? ''));
    }

    public function testExecuteSkipsWhenPreparedInvoiceHasZeroQty(): void
    {
        $fixture = $this->createFixture(canInvoice: true);
        $fixture['invoice']->totalQty = 0.0;
        $action = $fixture['action'];

        $result = $action->execute($this->createContext(), []);

        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $result->getStatus());
        $this->assertStringContainsString('No invoiceable items', (string)($result->getOutput()['reason'] ?? ''));
        $this->assertSame(0, $fixture['invoice']->registerCalls, 'Zero-qty invoice must never be registered');
        $this->assertSame(0, $fixture['transactionFactory']->createCalls, 'Zero-qty invoice must never be saved');
        $this->assertNull($fixture['invoice']->captureCase);
    }

    public function testExecuteDefaultsToOfflineCaptureAndSavesExactlyOnce(): void
    {
        $fixture = $this->createFixture(canInvoice: true);
        $action = $fixture['action'];

        $result = $action->execute($this->createContext(), []);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(Invoice::CAPTURE_OFFLINE, $fixture['invoice']->captureCase, 'Default must be offline: no gateway call');
        $this->assertSame(1, $fixture['invoice']->registerCalls);
        $this->assertSame(1, $fixture['transactionFactory']->createCalls);
        $this->assertSame(1, $fixture['transaction']->saveCalls, 'Invoice + order must be persisted exactly once');
        $this->assertCount(2, $fixture['transaction']->objects, 'Both invoice and order belong in the transaction');
        $this->assertTrue($fixture['order']->isInProcess);
        $this->assertSame(900, $result->getOutput()['invoice_id']);
        $this->assertSame('INV-900', $result->getOutput()['invoice_increment_id']);
        $this->assertSame(49.5, $result->getOutput()['grand_total']);
        $this->assertSame('offline', $result->getOutput()['capture']);
    }

    public function testExecuteHonorsOnlineCaptureConfig(): void
    {
        $fixture = $this->createFixture(canInvoice: true);
        $action = $fixture['action'];

        $result = $action->execute($this->createContext(), ['capture' => 'online']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(Invoice::CAPTURE_ONLINE, $fixture['invoice']->captureCase);
        $this->assertSame('online', $result->getOutput()['capture']);
        $this->assertSame(1, $fixture['transaction']->saveCalls);
    }

    public function testExecuteLocalizedExceptionIsTerminalFailure(): void
    {
        $fixture = $this->createFixture(canInvoice: true);
        $fixture['invoiceService']->throwOnPrepare = new LocalizedException(
            new Phrase('The order does not allow an invoice to be created.')
        );
        $action = $fixture['action'];

        $result = $action->execute($this->createContext(), []);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable(), 'A state/config problem must not burn the retry queue');
        $this->assertStringContainsString('does not allow an invoice', (string)$result->getError());
        $this->assertSame(0, $fixture['transaction']->saveCalls);
    }

    public function testExecuteGenericExceptionOnSaveIsRetryableFailure(): void
    {
        $fixture = $this->createFixture(canInvoice: true);
        $fixture['transaction']->throwOnSave = new \RuntimeException('Lock wait timeout exceeded');
        $action = $fixture['action'];

        $result = $action->execute($this->createContext(), []);

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isRetryable(), 'Infrastructure flakiness must be redelivered via the queue');
        $this->assertStringContainsString('Lock wait timeout', (string)$result->getError());
    }

    public function testSimulateNeverPreparesOrSavesAnInvoice(): void
    {
        $fixture = $this->createFixture(canInvoice: true);
        $action = $fixture['action'];

        $result = $action->simulate($this->createContext(), ['capture' => 'online']);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getOutput()['simulated']);
        $this->assertSame(0, $fixture['invoiceService']->prepareCalls, 'simulate() must not create documents');
        $this->assertSame(0, $fixture['transactionFactory']->createCalls);
    }

    private function createContext(): ExecutionContext
    {
        return new ExecutionContext(new WorkflowExecutionStub(entityId: 42));
    }

    /**
     * @return array{action: CreateInvoice, repository: object, invoiceService: object,
     *     transactionFactory: object, transaction: object, invoice: object, order: object}
     */
    private function createFixture(bool $canInvoice): array
    {
        $order = new class extends Order {
            public bool $canInvoiceFlag = false;
            public bool $isInProcess = false;
            public function __construct()
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
            public function getState(): string
            {
                return Order::STATE_NEW;
            }
            public function canInvoice(): bool
            {
                return $this->canInvoiceFlag;
            }
            public function setIsInProcess($flag)
            {
                $this->isInProcess = (bool)$flag;
                return $this;
            }
        };
        $order->canInvoiceFlag = $canInvoice;

        $invoice = new class($order) extends Invoice {
            public float $totalQty = 2.0;
            public ?string $captureCase = null;
            public int $registerCalls = 0;
            public function __construct(private readonly Order $orderModel)
            {
            }
            public function getTotalQty()
            {
                return $this->totalQty;
            }
            public function setRequestedCaptureCase($requestedCaptureCase)
            {
                $this->captureCase = (string)$requestedCaptureCase;
                return $this;
            }
            public function register()
            {
                $this->registerCalls++;
                return $this;
            }
            public function getOrder()
            {
                return $this->orderModel;
            }
            public function getEntityId()
            {
                return 900;
            }
            public function getIncrementId()
            {
                return 'INV-900';
            }
            public function getGrandTotal()
            {
                return 49.5;
            }
        };

        $repository = new class($order) implements OrderRepositoryInterface {
            public int $getCalls = 0;
            public function __construct(private readonly Order $order)
            {
            }
            public function get($id)
            {
                $this->getCalls++;
                return $this->order;
            }
            public function getList($searchCriteria) { throw new \BadMethodCallException(__METHOD__); }
            public function delete($entity) { throw new \BadMethodCallException(__METHOD__); }
            public function save($entity) { throw new \BadMethodCallException(__METHOD__); }
            public function deleteById($id) { throw new \BadMethodCallException(__METHOD__); }
        };

        $invoiceService = new class($invoice) extends InvoiceService {
            public int $prepareCalls = 0;
            public ?\Throwable $throwOnPrepare = null;
            public function __construct(private readonly Invoice $invoice)
            {
            }
            public function prepareInvoice(
                Order $order,
                array $orderItemsQtyToInvoice = []
            ): \Magento\Sales\Api\Data\InvoiceInterface {
                $this->prepareCalls++;
                if ($this->throwOnPrepare !== null) {
                    throw $this->throwOnPrepare;
                }
                return $this->invoice;
            }
        };

        $transaction = new class {
            public array $objects = [];
            public int $saveCalls = 0;
            public ?\Throwable $throwOnSave = null;
            public function addObject($object)
            {
                $this->objects[] = $object;
                return $this;
            }
            public function save()
            {
                $this->saveCalls++;
                if ($this->throwOnSave !== null) {
                    throw $this->throwOnSave;
                }
                return $this;
            }
        };

        $transactionFactory = new class($transaction) extends TransactionFactory {
            public int $createCalls = 0;
            public function __construct(private readonly object $transaction)
            {
            }
            public function create(array $data = [])
            {
                $this->createCalls++;
                return $this->transaction;
            }
        };

        return [
            'action' => new CreateInvoice($repository, $invoiceService, $transactionFactory),
            'repository' => $repository,
            'invoiceService' => $invoiceService,
            'transactionFactory' => $transactionFactory,
            'transaction' => $transaction,
            'invoice' => $invoice,
            'order' => $order,
        ];
    }
}
