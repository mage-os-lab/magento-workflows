<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Aggregation;

use MageOS\Workflows\Model\Aggregation\AggregationConfig;
use MageOS\Workflows\Model\Suppression\WorkflowSuppression;
use MageOS\Workflows\Test\Unit\Stub\StubScopeConfig;
use PHPUnit\Framework\TestCase;

/**
 * The suppression-synergy flag (05 §6): the exact guard the dispatcher applies
 * at its suppression branch — an aggregated workflow keeps accumulating during
 * a bulk-suppression storm only when it opts in via
 * aggregate_suppressed_events. Exercised against the real WorkflowSuppression
 * static state so the config flag + suppression flag compose as the dispatcher
 * composes them.
 */
class SuppressionBypassTest extends TestCase
{
    private WorkflowSuppression $suppression;

    public function setUp(): void
    {
        $this->suppression = new WorkflowSuppression(new StubScopeConfig());
    }

    public function tearDown(): void
    {
        // Ensure no suppression depth leaks between tests.
        while ($this->suppression->isSuppressed()) {
            WorkflowSuppression::restore();
        }
    }

    private function config(bool $optIn): AggregationConfig
    {
        return AggregationConfig::fromJson(json_encode([
            'mode' => 'window',
            'window' => ['type' => 'interval', 'duration' => 'PT1H'],
            'aggregate_suppressed_events' => $optIn,
        ]));
    }

    public function testNotSuppressedAlwaysAccumulates(): void
    {
        $this->assertFalse($this->suppression->isSuppressed());
        $this->assertTrue($this->config(false)->shouldAccumulateUnderSuppression($this->suppression->isSuppressed()));
        $this->assertTrue($this->config(true)->shouldAccumulateUnderSuppression($this->suppression->isSuppressed()));
    }

    public function testSuppressedWithoutOptInDrops(): void
    {
        WorkflowSuppression::suppress();
        try {
            $this->assertTrue($this->suppression->isSuppressed());
            $this->assertFalse(
                $this->config(false)->shouldAccumulateUnderSuppression($this->suppression->isSuppressed())
            );
        } finally {
            WorkflowSuppression::restore();
        }
    }

    public function testSuppressedWithOptInKeepsAccumulating(): void
    {
        WorkflowSuppression::suppress();
        try {
            $this->assertTrue($this->suppression->isSuppressed());
            // The storm becomes one digest instead of silent drops.
            $this->assertTrue(
                $this->config(true)->shouldAccumulateUnderSuppression($this->suppression->isSuppressed())
            );
        } finally {
            WorkflowSuppression::restore();
        }
    }
}
