<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Unit\Action\Order;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Model\Option\OrderStatusOptionSource;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsActionsCore\Action\Order\ChangeStatus;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour coverage for order.change_status. Pins docs/07-actions.md
 * footnote 2: "Status transitions are validated against the state machine
 * ... an invalid transition = step failure, not silent corruption" — i.e.
 * an illegal target status must produce a TERMINAL failure and the order
 * must never be saved.
 */
class ChangeStatusTest extends TestCase
{
    public function testExecuteMissingStatusConfigIsTerminalFailure(): void
    {
        $fixture = $this->createFixture(currentStatus: 'processing', state: 'processing');

        $result = $fixture['action']->execute($this->createContext(), []);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('status', (string)$result->getError());
        $this->assertSame(0, $fixture['repository']->saveCalls);
    }

    public function testExecuteSkipsWhenOrderAlreadyHasTargetStatus(): void
    {
        $fixture = $this->createFixture(currentStatus: 'processing', state: 'processing');

        $result = $fixture['action']->execute($this->createContext(), ['status' => 'processing']);

        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $result->getStatus());
        $this->assertFalse($result->isFailure());
        $this->assertSame(0, $fixture['repository']->saveCalls, 'Already-at-target must be a no-op (redelivery safety)');
        $this->assertCount(0, $fixture['order']->addedComments);
        $this->assertStringContainsString('already has status', (string)($result->getOutput()['reason'] ?? ''));
    }

    public function testExecuteInvalidTransitionIsTerminalFailureAndOrderIsNotSaved(): void
    {
        $fixture = $this->createFixture(
            currentStatus: 'processing',
            state: 'processing',
            stateStatuses: ['processing' => 'Processing', 'fraud' => 'Suspected Fraud']
        );

        $result = $fixture['action']->execute($this->createContext(), ['status' => 'complete']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable(), 'An illegal state-machine transition never resolves on retry');
        $this->assertStringContainsString('not valid for order state', (string)$result->getError());
        $this->assertStringContainsString('complete', (string)$result->getError());
        $this->assertStringContainsString('processing', (string)$result->getError());
        $this->assertSame(0, $fixture['repository']->saveCalls, 'Order must NOT be saved on an invalid transition');
        $this->assertCount(0, $fixture['order']->addedComments, 'No status history for a rejected transition');
    }

    public function testExecuteValidTransitionAddsStatusHistoryAndSavesOnce(): void
    {
        $fixture = $this->createFixture(
            currentStatus: 'processing',
            state: 'processing',
            stateStatuses: ['processing' => 'Processing', 'fraud' => 'Suspected Fraud']
        );

        $result = $fixture['action']->execute($this->createContext(), ['status' => 'fraud']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $fixture['repository']->saveCalls);
        $this->assertCount(1, $fixture['order']->addedComments);
        $this->assertSame('fraud', $fixture['order']->addedComments[0]['status']);
        $this->assertFalse($fixture['order']->addedComments[0]['visible'], 'Workflow bookkeeping is not storefront-visible');
        $this->assertStringContainsString('Fraud Screening', (string)$fixture['order']->addedComments[0]['comment']);
        $this->assertStringContainsString('Status changed by workflow', (string)$fixture['order']->addedComments[0]['comment']);
        $this->assertSame('processing', $result->getOutput()['previous_status']);
        $this->assertSame('fraud', $result->getOutput()['status']);
    }

    public function testExecuteSaveFailureIsRetryable(): void
    {
        $fixture = $this->createFixture(
            currentStatus: 'processing',
            state: 'processing',
            stateStatuses: ['processing' => 'Processing', 'fraud' => 'Suspected Fraud']
        );
        $fixture['repository']->throwOnSave = new \RuntimeException('Deadlock found when trying to get lock');

        $result = $fixture['action']->execute($this->createContext(), ['status' => 'fraud']);

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isRetryable(), 'A failed save may succeed on queue redelivery');
        $this->assertStringContainsString('Deadlock', (string)$result->getError());
    }

    public function testSimulateInvalidTransitionFailsWithoutSaving(): void
    {
        $fixture = $this->createFixture(
            currentStatus: 'processing',
            state: 'processing',
            stateStatuses: ['processing' => 'Processing']
        );

        $result = $fixture['action']->simulate($this->createContext(), ['status' => 'complete']);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('not valid for order state', (string)$result->getError());
        $this->assertSame(0, $fixture['repository']->saveCalls);
        $this->assertCount(0, $fixture['order']->addedComments);
    }

    private function createContext(): ExecutionContext
    {
        return new ExecutionContext(
            new WorkflowExecutionStub(entityId: 42),
            workflow: ['id' => 7, 'name' => 'Fraud Screening', 'version' => 1]
        );
    }

    /**
     * @param array<string, string> $stateStatuses allowed status => label for the order's state
     * @return array{action: ChangeStatus, repository: object, order: object}
     */
    private function createFixture(string $currentStatus, string $state, array $stateStatuses = []): array
    {
        $order = new class extends Order {
            public string $status = '';
            public string $stateValue = '';
            public array $addedComments = [];
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
                return $this->stateValue;
            }
            public function getStatus(): string
            {
                return $this->status;
            }
            public function addCommentToStatusHistory($comment, $status = false, $isVisibleOnFront = false)
            {
                $this->addedComments[] = [
                    'comment' => (string)$comment,
                    'status' => $status,
                    'visible' => $isVisibleOnFront,
                ];
                return new class {
                    public function getEntityId()
                    {
                        return 1;
                    }
                };
            }
        };
        $order->status = $currentStatus;
        $order->stateValue = $state;

        $repository = new class($order) implements OrderRepositoryInterface {
            public int $saveCalls = 0;
            public ?\Throwable $throwOnSave = null;
            public function __construct(private readonly Order $order)
            {
            }
            public function get($id)
            {
                return $this->order;
            }
            public function save($entity)
            {
                $this->saveCalls++;
                if ($this->throwOnSave !== null) {
                    throw $this->throwOnSave;
                }
                return $entity;
            }
            public function getList($searchCriteria) { throw new \BadMethodCallException(__METHOD__); }
            public function delete($entity) { throw new \BadMethodCallException(__METHOD__); }
            public function deleteById($id) { throw new \BadMethodCallException(__METHOD__); }
        };

        $orderConfig = new class($stateStatuses) extends OrderConfig {
            public function __construct(private readonly array $allowedStateStatuses)
            {
            }
            public function getStateStatuses($state, $addLabels = true)
            {
                return $this->allowedStateStatuses;
            }
            public function getStatuses()
            {
                return [];
            }
        };

        return [
            'action' => new ChangeStatus($repository, $orderConfig, new OrderStatusOptionSource($orderConfig)),
            'repository' => $repository,
            'order' => $order,
        ];
    }
}
