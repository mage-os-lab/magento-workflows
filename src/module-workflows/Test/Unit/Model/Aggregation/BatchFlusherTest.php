<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Aggregation;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\Aggregation\BatchContextBuilder;
use MageOS\Workflows\Model\Aggregation\BatchExecutionFactoryInterface;
use MageOS\Workflows\Model\Aggregation\BatchFlusher;
use MageOS\Workflows\Model\Aggregation\CronSchedule;
use MageOS\Workflows\Model\Aggregation\WindowKeyCalculator;
use MageOS\Workflows\Test\Unit\Stub\FakeBatchStore;
use MageOS\Workflows\Test\Unit\Stub\WorkflowStub;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

class BatchFlusherTest extends TestCase
{
    private FakeBatchStore $store;

    /** @var int number of executions created */
    public int $created = 0;

    /** @var int next execution id the factory hands out */
    private int $nextExecutionId = 1;

    /** @var array<int, string> published execution ids */
    public array $published = [];

    public function setUp(): void
    {
        $this->store = new FakeBatchStore();
        $this->created = 0;
        $this->nextExecutionId = 1;
        $this->published = [];
    }

    private function workflowRepository(WorkflowInterface $workflow): WorkflowRepositoryInterface
    {
        return new class ($workflow) implements WorkflowRepositoryInterface {
            public function __construct(private readonly WorkflowInterface $workflow)
            {
            }

            public function save(WorkflowInterface $workflow): WorkflowInterface
            {
                return $workflow;
            }

            public function getById(int $workflowId): WorkflowInterface
            {
                return $this->workflow;
            }

            public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
            {
                throw new \RuntimeException('not used');
            }

            public function delete(WorkflowInterface $workflow): bool
            {
                return true;
            }

            public function deleteById(int $workflowId): bool
            {
                return true;
            }
        };
    }

    private function executionFactory(): BatchExecutionFactoryInterface
    {
        $test = $this;
        return new class ($test) implements BatchExecutionFactoryInterface {
            public function __construct(private readonly BatchFlusherTest $test)
            {
            }

            public function create(WorkflowInterface $workflow, array $batchContext, int $storeId): int
            {
                return $this->test->recordCreate();
            }
        };
    }

    public function recordCreate(): int
    {
        $this->created++;
        return $this->nextExecutionId++;
    }

    private function publisher(bool $throwOnce = false): PublisherInterface
    {
        $test = $this;
        return new class ($test, $throwOnce) implements PublisherInterface {
            private bool $thrown = false;

            public function __construct(
                private readonly BatchFlusherTest $test,
                private readonly bool $throwOnce
            ) {
            }

            public function publish($topicName, $data)
            {
                if ($this->throwOnce && !$this->thrown) {
                    $this->thrown = true;
                    throw new \RuntimeException('queue down');
                }
                $this->test->recordPublish((string) $data);
                return null;
            }
        };
    }

    public function recordPublish(string $id): void
    {
        $this->published[] = $id;
    }

    private function flusher(WorkflowInterface $workflow, PublisherInterface $publisher, BatchExecutionFactoryInterface $factory): BatchFlusher
    {
        return new BatchFlusher(
            $this->store,
            $this->workflowRepository($workflow),
            $factory,
            new BatchContextBuilder(),
            new WindowKeyCalculator(new CronSchedule()),
            $publisher,
            new NullLogger()
        );
    }

    private function scheduleWorkflow(int $minItems = 1): WorkflowStub
    {
        return new WorkflowStub([
            'workflow_id' => 5,
            'name' => 'Digest',
            'entity_type' => 'catalog_product',
            'definition' => '{"schema":1,"entry":"s1","steps":{"s1":{"type":"stop"}}}',
            'aggregation' => json_encode([
                'mode' => 'window',
                'window' => ['type' => 'schedule', 'cron' => '0 9 * * *', 'timezone' => 'UTC'],
                'min_items' => $minItems,
            ]),
        ]);
    }

    private function dueBatch(int $items): int
    {
        $batchId = $this->store->openBatch(5, '2026-07-04T09:00:00Z', '2000-01-01 00:00:00', 0);
        for ($i = 1; $i <= $items; $i++) {
            $this->store->upsertItem($batchId, $i, json_encode(['entity_id' => $i]));
        }
        $this->store->syncItemCount($batchId);
        return $batchId;
    }

    public function testFlushDueCreatesOneExecution(): void
    {
        $workflow = $this->scheduleWorkflow();
        $batchId = $this->dueBatch(3);

        $factory = $this->executionFactory();
        $flushed = $this->flusher($workflow, $this->publisher(), $factory)->flush();

        $this->assertSame(1, $flushed);
        $this->assertSame(1, $this->created);
        $this->assertSame(['1'], $this->published);
        $this->assertSame('flushed', $this->store->batch($batchId)['status']);
        $this->assertSame(1, (int) $this->store->batch($batchId)['execution_id']);
    }

    public function testClaimRaceOneWinner(): void
    {
        $workflow = $this->scheduleWorkflow();
        $this->dueBatch(3);

        $factory = $this->executionFactory();
        $publisher = $this->publisher();
        // Two sweepers over the same store: the first claims and flushes, the
        // second finds nothing to claim.
        $flusherA = $this->flusher($workflow, $publisher, $factory);
        $flusherB = $this->flusher($workflow, $publisher, $factory);

        $a = $flusherA->flush();
        $b = $flusherB->flush();

        $this->assertSame(1, $a);
        $this->assertSame(0, $b);
        // Exactly one execution created and published across both sweepers.
        $this->assertSame(1, $this->created);
        $this->assertSame(['1'], $this->published);
    }

    public function testClaimIsAtomicAtTheStore(): void
    {
        $this->dueBatch(3);
        $now = gmdate('Y-m-d H:i:s');

        $first = $this->store->claimDueForFlush($now);
        $second = $this->store->claimDueForFlush($now);

        $this->assertCount(1, $first);
        $this->assertCount(0, $second);
    }

    public function testCrashBetweenCreateAndPublishRepublishesSameExecution(): void
    {
        $workflow = $this->scheduleWorkflow();
        $batchId = $this->dueBatch(3);
        $factory = $this->executionFactory();

        // First pass: execution created + recorded, then the publish crashes.
        $failing = $this->publisher(true);
        $this->flusher($workflow, $failing, $factory)->flush();

        $this->assertSame(1, $this->created);
        $this->assertSame([], $this->published); // nothing published yet
        $this->assertSame('flushing', $this->store->batch($batchId)['status']);
        $this->assertSame(1, (int) $this->store->batch($batchId)['execution_id']);

        // Grace period passes; the stale-flushing re-claim retries.
        $this->store->ageFlushing($batchId, '2000-01-01 00:00:00');
        $succeeding = $this->publisher();
        $flushed = $this->flusher($workflow, $succeeding, $factory)->flush();

        $this->assertSame(1, $flushed);
        // NO second execution created — the recorded one is re-published.
        $this->assertSame(1, $this->created);
        $this->assertSame(['1'], $this->published);
        $this->assertSame('flushed', $this->store->batch($batchId)['status']);
    }

    public function testScheduleUnderMinItemsDropsWithoutExecution(): void
    {
        $workflow = $this->scheduleWorkflow(2);
        $batchId = $this->dueBatch(1); // 1 item < min_items 2

        $flushed = $this->flusher($workflow, $this->publisher(), $this->executionFactory())->flush();

        $this->assertSame(0, $flushed);
        $this->assertSame(0, $this->created);
        // Schedule mode: dropped (closed) with no execution.
        $this->assertSame('flushed', $this->store->batch($batchId)['status']);
    }

    public function testIntervalUnderMinItemsCarriesToNextWindow(): void
    {
        $workflow = new WorkflowStub([
            'workflow_id' => 5,
            'name' => 'Interval digest',
            'entity_type' => 'catalog_product',
            'definition' => '{"schema":1,"entry":"s1","steps":{"s1":{"type":"stop"}}}',
            'aggregation' => json_encode([
                'mode' => 'window',
                'window' => ['type' => 'interval', 'duration' => 'PT1H'],
                'min_items' => 3,
            ]),
        ]);
        $batchId = $this->store->openBatch(5, '2026-07-04T13:00:00Z', '2000-01-01 00:00:00', 0);
        $this->store->upsertItem($batchId, 1, json_encode(['entity_id' => 1]));
        $this->store->syncItemCount($batchId);

        $flushed = $this->flusher($workflow, $this->publisher(), $this->executionFactory())->flush();

        $this->assertSame(0, $flushed);
        $this->assertSame(0, $this->created);
        // Interval mode: carried back to open with a bumped flush_due_at.
        $batch = $this->store->batch($batchId);
        $this->assertSame('open', $batch['status']);
        $this->assertTrue($batch['flush_due_at'] > '2000-01-01 00:00:00');
        // Items are retained for the next window.
        $this->assertSame(1, $this->store->itemCount($batchId));
    }
}
