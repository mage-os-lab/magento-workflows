<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Aggregation;

use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\Aggregation\AggregationConfig;
use MageOS\Workflows\Model\Aggregation\BatchAccumulator;
use MageOS\Workflows\Model\Aggregation\CronSchedule;
use MageOS\Workflows\Model\Aggregation\ItemProjector;
use MageOS\Workflows\Model\Aggregation\MembershipEvaluatorInterface;
use MageOS\Workflows\Model\Aggregation\WindowKeyCalculator;
use MageOS\Workflows\Test\Unit\Stub\FakeBatchStore;
use MageOS\Workflows\Test\Unit\Stub\WorkflowStub;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

class BatchAccumulatorTest extends TestCase
{
    private FakeBatchStore $store;

    public function setUp(): void
    {
        $this->store = new FakeBatchStore();
    }

    private function membership(bool $all = true): MembershipEvaluatorInterface
    {
        return new class ($all) implements MembershipEvaluatorInterface {
            public function __construct(private readonly bool $all)
            {
            }

            public function matches(WorkflowInterface $workflow, array $flatItem): bool
            {
                return $this->all || (int) ($flatItem['in_stock'] ?? 0) === 1;
            }
        };
    }

    private function accumulator(MembershipEvaluatorInterface $membership): BatchAccumulator
    {
        return new BatchAccumulator(
            $this->store,
            $membership,
            new ItemProjector(),
            new WindowKeyCalculator(new CronSchedule()),
            new NullLogger()
        );
    }

    private function scheduleWorkflow(): array
    {
        $workflow = new WorkflowStub([
            'workflow_id' => 5,
            'entity_type' => 'catalog_product',
            'aggregation' => json_encode([
                'mode' => 'window',
                'window' => ['type' => 'schedule', 'cron' => '0 9 * * *', 'timezone' => 'UTC'],
                'projection' => ['entity_id', 'sku'],
            ]),
        ]);
        return [$workflow, AggregationConfig::fromJson($workflow->getAggregation())];
    }

    public function testStormYieldsExactlyOneBatch(): void
    {
        [$workflow, $config] = $this->scheduleWorkflow();
        $accumulator = $this->accumulator($this->membership());

        for ($i = 1; $i <= 10000; $i++) {
            $accumulator->accumulate($workflow, $config, ['entity_id' => $i, 'sku' => 'SKU' . $i]);
        }

        // Schedule mode: deterministic window_key => one batch row.
        $open = $this->store->findOpenBatch(5);
        $this->assertNotNull($open);
        $this->assertSame(10000, $this->store->itemCount((int) $open['batch_id']));
    }

    public function testDedupeWithinBatch(): void
    {
        [$workflow, $config] = $this->scheduleWorkflow();
        $accumulator = $this->accumulator($this->membership());

        $accumulator->accumulate($workflow, $config, ['entity_id' => 42, 'sku' => 'A']);
        $accumulator->accumulate($workflow, $config, ['entity_id' => 42, 'sku' => 'A-updated']);
        $accumulator->accumulate($workflow, $config, ['entity_id' => 43, 'sku' => 'B']);

        $open = $this->store->findOpenBatch(5);
        // Entity 42 appears once (dedupe on (batch_id, entity_id)).
        $this->assertSame(2, $this->store->itemCount((int) $open['batch_id']));
    }

    public function testNonMembersNotAccumulated(): void
    {
        [$workflow, $config] = $this->scheduleWorkflow();
        $accumulator = $this->accumulator($this->membership(false));

        $this->assertTrue($accumulator->accumulate($workflow, $config, ['entity_id' => 1, 'in_stock' => 1]));
        $this->assertFalse($accumulator->accumulate($workflow, $config, ['entity_id' => 2, 'in_stock' => 0]));

        $open = $this->store->findOpenBatch(5);
        $this->assertSame(1, $this->store->itemCount((int) $open['batch_id']));
    }

    public function testEventWithoutEntityIdIgnored(): void
    {
        [$workflow, $config] = $this->scheduleWorkflow();
        $accumulator = $this->accumulator($this->membership());

        $this->assertFalse($accumulator->accumulate($workflow, $config, ['sku' => 'no-id']));
        $this->assertNull($this->store->findOpenBatch(5));
    }

    public function testIntervalModeReusesOpenBatch(): void
    {
        $workflow = new WorkflowStub([
            'workflow_id' => 8,
            'entity_type' => 'catalog_product',
            'aggregation' => json_encode([
                'mode' => 'window',
                'window' => ['type' => 'interval', 'duration' => 'PT1H'],
                'projection' => ['entity_id'],
            ]),
        ]);
        $config = AggregationConfig::fromJson($workflow->getAggregation());
        $accumulator = $this->accumulator($this->membership());

        $accumulator->accumulate($workflow, $config, ['entity_id' => 1]);
        $accumulator->accumulate($workflow, $config, ['entity_id' => 2]);

        // Both events land in the same open interval batch.
        $open = $this->store->findOpenBatch(8);
        $this->assertNotNull($open);
        $this->assertSame(2, $this->store->itemCount((int) $open['batch_id']));
    }
}
