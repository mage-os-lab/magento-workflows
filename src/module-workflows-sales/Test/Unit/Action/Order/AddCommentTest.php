<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Test\Unit\Action\Order;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsSales\Action\Order\AddComment;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour coverage for order.add_comment. Pins docs/08-execution-model.md:
 * "add-comment dedupes on execution UUID" — the dedupe key is per step
 * (execution UUID + step key), carried as an invisible HTML-comment marker,
 * so a queue redelivery of the same step never spams a second comment while
 * a different step of the same execution still may comment.
 */
class AddCommentTest extends TestCase
{
    private const UUID = 'exec-uuid-1';
    private const STEP = 'note_step';
    private const MARKER = '<!-- mageos-workflows:' . self::UUID . ':' . self::STEP . ' -->';

    public function testExecuteMissingCommentConfigIsTerminalFailure(): void
    {
        $fixture = $this->createFixture();

        $result = $fixture['action']->execute($this->createContext(), []);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('comment', (string)$result->getError());
        $this->assertSame(0, $fixture['repository']->saveCalls);
    }

    public function testExecuteAddsCommentWithDedupeMarkerAndSavesOnce(): void
    {
        $fixture = $this->createFixture();

        $result = $fixture['action']->execute(
            $this->createContext(),
            ['comment' => 'Flagged for manual review', 'is_visible_on_front' => true]
        );

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, $fixture['order']->addedComments);
        $added = $fixture['order']->addedComments[0];
        $this->assertStringContainsString('Flagged for manual review', $added['comment']);
        $this->assertStringContainsString(self::MARKER, $added['comment'], 'Comment must carry the dedupe marker');
        $this->assertTrue($added['visible']);
        $this->assertSame(1, $fixture['repository']->saveCalls);
        $this->assertSame(77, $result->getOutput()['comment_id']);
        $this->assertTrue($result->getOutput()['is_visible_on_front']);
    }

    public function testExecuteRedeliveryWithSameExecutionAndStepSkipsAndAddsNothing(): void
    {
        $fixture = $this->createFixture();
        // A previous delivery of this exact execution UUID + step key already
        // landed its comment (marker present in the order's status history).
        $fixture['order']->histories = [
            $this->createHistory('Flagged for manual review ' . self::MARKER),
        ];

        $result = $fixture['action']->execute(
            $this->createContext(),
            ['comment' => 'Flagged for manual review']
        );

        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $result->getStatus());
        $this->assertFalse($result->isFailure());
        $this->assertCount(0, $fixture['order']->addedComments, 'Redelivery must not add a second comment');
        $this->assertSame(0, $fixture['repository']->saveCalls, 'Redelivery must not save the order again');
        $this->assertStringContainsString('dedupe marker', (string)($result->getOutput()['reason'] ?? ''));
    }

    public function testExecuteDifferentStepKeyOfSameExecutionIsNotDeduped(): void
    {
        $fixture = $this->createFixture();
        // Marker from ANOTHER step of the same execution must not suppress
        // this step's comment — the key is execution UUID + step key.
        $fixture['order']->histories = [
            $this->createHistory('Earlier note <!-- mageos-workflows:' . self::UUID . ':other_step -->'),
        ];

        $result = $fixture['action']->execute(
            $this->createContext(),
            ['comment' => 'Second note']
        );

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, $fixture['order']->addedComments);
        $this->assertStringContainsString(self::MARKER, $fixture['order']->addedComments[0]['comment']);
        $this->assertSame(1, $fixture['repository']->saveCalls);
    }

    public function testExecuteSaveFailureIsRetryable(): void
    {
        $fixture = $this->createFixture();
        $fixture['repository']->throwOnSave = new \RuntimeException('MySQL server has gone away');

        $result = $fixture['action']->execute($this->createContext(), ['comment' => 'Hello']);

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isRetryable(), 'A crashed save must be redelivered; the marker check makes the retry safe');
        $this->assertStringContainsString('gone away', (string)$result->getError());
    }

    public function testSimulateNeverTouchesTheOrder(): void
    {
        $fixture = $this->createFixture();

        $result = $fixture['action']->simulate($this->createContext(), ['comment' => 'Hello']);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getOutput()['simulated']);
        $this->assertCount(0, $fixture['order']->addedComments);
        $this->assertSame(0, $fixture['repository']->saveCalls);
    }

    private function createContext(): ExecutionContext
    {
        $execution = new WorkflowExecutionStub(uuid: self::UUID, entityId: 42);
        $execution->setCurrentStep(self::STEP);
        return new ExecutionContext($execution);
    }

    private function createHistory(string $comment): object
    {
        return new class($comment) {
            public function __construct(private readonly string $comment)
            {
            }
            public function getComment()
            {
                return $this->comment;
            }
        };
    }

    /**
     * @return array{action: AddComment, repository: object, order: object}
     */
    private function createFixture(): array
    {
        $order = new class extends Order {
            public array $histories = [];
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
            public function getStatusHistories($reload = true)
            {
                return $this->histories;
            }
            public function addCommentToStatusHistory($comment, $status = false, $isVisibleOnFront = false)
            {
                $this->addedComments[] = [
                    'comment' => (string)$comment,
                    'status' => $status,
                    'visible' => $isVisibleOnFront,
                ];
                return new class((bool)$isVisibleOnFront) {
                    public function __construct(private readonly bool $visible)
                    {
                    }
                    public function getEntityId()
                    {
                        return 77;
                    }
                    public function getIsVisibleOnFront()
                    {
                        return $this->visible;
                    }
                };
            }
        };

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

        return [
            'action' => new AddComment($repository),
            'repository' => $repository,
            'order' => $order,
        ];
    }
}
