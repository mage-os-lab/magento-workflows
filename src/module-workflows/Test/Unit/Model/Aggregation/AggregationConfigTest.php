<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Aggregation;

use MageOS\Workflows\Model\Aggregation\AggregationConfig;
use PHPUnit\Framework\TestCase;

class AggregationConfigTest extends TestCase
{
    public function testNullColumnIsPerEntityWorkflow(): void
    {
        $this->assertNull(AggregationConfig::fromJson(null));
        $this->assertNull(AggregationConfig::fromJson(''));
        $this->assertNull(AggregationConfig::fromJson('   '));
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AggregationConfig::fromJson('{not json');
    }

    public function testNonObjectThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AggregationConfig::fromJson('42');
    }

    public function testCollectedMode(): void
    {
        $config = AggregationConfig::fromJson('{"mode":"collected","item_cap":100}');

        $this->assertNotNull($config);
        $this->assertTrue($config->isCollected());
        $this->assertFalse($config->isWindow());
        $this->assertSame(100, $config->getItemCap());
    }

    public function testScheduleWindow(): void
    {
        $config = AggregationConfig::fromJson(
            '{"mode":"window","window":{"type":"schedule","cron":"0 9 * * *","timezone":"America/New_York"}}'
        );

        $this->assertTrue($config->isWindow());
        $this->assertSame(AggregationConfig::WINDOW_SCHEDULE, $config->getWindowType());
        $this->assertSame('0 9 * * *', $config->getCron());
        $this->assertSame('America/New_York', $config->getTimezone());
        $this->assertNull($config->getDuration());
    }

    public function testIntervalWindow(): void
    {
        $config = AggregationConfig::fromJson('{"mode":"window","window":{"type":"interval","duration":"PT1H"}}');

        $this->assertSame(AggregationConfig::WINDOW_INTERVAL, $config->getWindowType());
        $this->assertSame('PT1H', $config->getDuration());
        $this->assertNull($config->getCron());
        $this->assertSame('UTC', $config->getTimezone());
    }

    public function testDefaults(): void
    {
        $config = AggregationConfig::fromJson('{"mode":"window"}');

        $this->assertSame(AggregationConfig::DEFAULT_ITEM_CAP, $config->getItemCap());
        $this->assertSame(AggregationConfig::DEFAULT_MIN_ITEMS, $config->getMinItems());
        $this->assertSame([], $config->getProjection());
        $this->assertFalse($config->aggregateSuppressedEvents());
    }

    public function testItemCapAndMinItemsFloor(): void
    {
        $config = AggregationConfig::fromJson('{"item_cap":0,"min_items":0}');

        // Non-positive values fall back to the documented defaults.
        $this->assertSame(AggregationConfig::DEFAULT_ITEM_CAP, $config->getItemCap());
        $this->assertSame(AggregationConfig::DEFAULT_MIN_ITEMS, $config->getMinItems());
    }

    public function testProjectionDedupesAndDropsNonStrings(): void
    {
        $config = AggregationConfig::fromJson('{"projection":["sku","name","sku","",5]}');

        $this->assertSame(['sku', 'name'], $config->getProjection());
    }

    public function testAggregateSuppressedEvents(): void
    {
        $config = AggregationConfig::fromJson('{"aggregate_suppressed_events":true}');

        $this->assertTrue($config->aggregateSuppressedEvents());
    }

    public function testMinItemsExplicit(): void
    {
        $config = AggregationConfig::fromJson('{"min_items":5}');

        $this->assertSame(5, $config->getMinItems());
    }
}
