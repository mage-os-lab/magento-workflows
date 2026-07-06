<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\DryRun;

use MageOS\Workflows\Model\Aggregation\AggregationConfig;
use MageOS\Workflows\Model\Aggregation\BatchContextBuilder;
use MageOS\Workflows\Model\Aggregation\ItemProjector;
use MageOS\Workflows\Model\DryRun\SyntheticBatchFactory;
use PHPUnit\Framework\TestCase;

class SyntheticBatchFactoryTest extends TestCase
{
    private SyntheticBatchFactory $factory;

    public function setUp(): void
    {
        $this->factory = new SyntheticBatchFactory(new ItemProjector(), new BatchContextBuilder());
    }

    public function testFabricatesBatchContextWithSampleItems(): void
    {
        $config = AggregationConfig::fromJson('{"mode":"window","projection":["entity_id","sku","name"]}');

        $context = $this->factory->fabricate($config, null, 3);

        $this->assertTrue($context['batch']);
        $this->assertSame(3, $context['count']);
        $this->assertFalse($context['overflow']);
        $this->assertCount(3, $context['items']);
        $this->assertSame(1, $context['items'][0]['entity_id']);
        $this->assertSame('sample-sku-1', $context['items'][0]['sku']);
        $this->assertSame('sample-name-2', $context['items'][1]['name']);
    }

    public function testDerivesFieldsFromRootConditionsWhenNoExplicitProjection(): void
    {
        $config = AggregationConfig::fromJson('{"mode":"window"}');
        $conditions = json_encode(['conditions' => [
            ['attribute' => 'status', 'operator' => '==', 'value' => 'complete'],
        ]]);

        $context = $this->factory->fabricate($config, $conditions, 2);

        // Identity + the root-condition attribute.
        $this->assertArrayHasKey('status', $context['items'][0]);
        $this->assertSame('sample-status-1', $context['items'][0]['status']);
    }

    public function testOverflowWhenSampleCountExceedsItemCap(): void
    {
        $config = AggregationConfig::fromJson('{"mode":"window","item_cap":2,"projection":["entity_id"]}');

        $context = $this->factory->fabricate($config, null, 5);

        // count reflects the requested sample size; items capped; overflow set.
        $this->assertSame(5, $context['count']);
        $this->assertCount(2, $context['items']);
        $this->assertTrue($context['overflow']);
    }

    public function testAtLeastOneSample(): void
    {
        $config = AggregationConfig::fromJson('{"mode":"window","projection":["entity_id"]}');

        $context = $this->factory->fabricate($config, null, 0);

        $this->assertSame(1, $context['count']);
        $this->assertCount(1, $context['items']);
    }
}
