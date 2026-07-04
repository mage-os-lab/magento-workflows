<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Aggregation;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\Aggregation\AggregationConfig;
use MageOS\Workflows\Model\Aggregation\BatchAccumulator;
use MageOS\Workflows\Model\Aggregation\BatchContextBuilder;
use MageOS\Workflows\Model\Aggregation\BatchExecutionFactoryInterface;
use MageOS\Workflows\Model\Aggregation\BatchFlusher;
use MageOS\Workflows\Model\Aggregation\CronSchedule;
use MageOS\Workflows\Model\Aggregation\ItemProjector;
use MageOS\Workflows\Model\Aggregation\MembershipEvaluatorInterface;
use MageOS\Workflows\Model\Aggregation\WindowKeyCalculator;
use MageOS\Workflows\Test\Unit\Stub\FakeBatchStore;
use MageOS\Workflows\Test\Unit\Stub\WorkflowStub;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * The storm keystone: 10k events accumulate into one batch, and the flush
 * releases exactly one execution.
 */
class StormTest extends TestCase
{
    public int $created = 0;

    public array $published = [];

    public function setUp(): void
    {
        $this->created = 0;
        $this->published = [];
    }

    private function workflow(): WorkflowStub
    {
        return new WorkflowStub([
            'workflow_id' => 1,
            'name' => 'Import digest',
            'entity_type' => 'catalog_product',
            'definition' => '{"schema":1,"entry":"s1","steps":{"s1":{"type":"stop"}}}',
            'aggregation' => json_encode([
                'mode' => 'window',
                'window' => ['type' => 'schedule', 'cron' => '0 9 * * *', 'timezone' => 'UTC'],
                'projection' => ['entity_id', 'sku'],
                'item_cap' => 500,
            ]),
        ]);
    }

    public function testTenThousandEventsYieldExactlyOneExecution(): void
    {
        $store = new FakeBatchStore();
        $workflow = $this->workflow();
        $config = AggregationConfig::fromJson($workflow->getAggregation());

        $membership = new class implements MembershipEvaluatorInterface {
            public function matches(WorkflowInterface $workflow, array $flatItem): bool
            {
                return true;
            }
        };

        $accumulator = new BatchAccumulator(
            $store,
            $membership,
            new ItemProjector(),
            new WindowKeyCalculator(new CronSchedule()),
            new NullLogger()
        );

        for ($i = 1; $i <= 10000; $i++) {
            $accumulator->accumulate($workflow, $config, ['entity_id' => $i, 'sku' => 'SKU' . $i]);
        }

        // One batch accrued all 10k (schedule window_key is deterministic).
        $this->assertSame(1, $store->openBatchCount());
        $open = $store->findOpenBatch(1);
        $this->assertSame(10000, (int) $open['item_count']);

        // Close the window and flush.
        $store->makeDue((int) $open['batch_id']);
        $flusher = new BatchFlusher(
            $store,
            $this->workflowRepository($workflow),
            $this->executionFactory(),
            new BatchContextBuilder(),
            new WindowKeyCalculator(new CronSchedule()),
            $this->publisher(),
            new NullLogger()
        );
        $flushed = $flusher->flush();

        // Exactly one execution — the storm became one digest. Cap surfaced:
        // count 10000, items elided (overflow), never silently truncated.
        $this->assertSame(1, $flushed);
        $this->assertSame(1, $this->created);
        $this->assertCount(1, $this->published);
        $this->assertSame(10000, $this->lastContextCount);
        $this->assertTrue($this->lastContextOverflow);
        $this->assertCount(500, $this->lastContextItems);
    }

    private int $lastContextCount = 0;
    private bool $lastContextOverflow = false;
    private array $lastContextItems = [];

    private function executionFactory(): BatchExecutionFactoryInterface
    {
        $test = $this;
        return new class ($test) implements BatchExecutionFactoryInterface {
            public function __construct(private readonly StormTest $test)
            {
            }

            public function create(WorkflowInterface $workflow, array $batchContext, int $storeId): int
            {
                return $this->test->recordCreate($batchContext);
            }
        };
    }

    public function recordCreate(array $context): int
    {
        $this->created++;
        $this->lastContextCount = (int) $context['count'];
        $this->lastContextOverflow = (bool) $context['overflow'];
        $this->lastContextItems = $context['items'];
        return $this->created;
    }

    private function publisher(): PublisherInterface
    {
        $test = $this;
        return new class ($test) implements PublisherInterface {
            public function __construct(private readonly StormTest $test)
            {
            }

            public function publish($topicName, $data)
            {
                $this->test->published[] = (string) $data;
                return null;
            }
        };
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
}
