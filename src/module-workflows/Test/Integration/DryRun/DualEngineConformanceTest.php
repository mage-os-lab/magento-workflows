<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\DryRun;

use PHPUnit\Framework\TestCase;

/**
 * Layer-2 of the dual-engine conformance suite (discovery §5, plan stage 3):
 * the full diff. Each published spec fixture is imported, dispatched through
 * the REAL DB-backed {@see \MageOS\Workflows\Model\Engine\Executor} in shadow
 * status (side-effect-free, but the exact production walk), and its step
 * sequence is diffed against the {@see \MageOS\Workflows\Model\DryRun\DryRunService}
 * path over the same fixture. Any routing divergence between the two walkers
 * fails here.
 *
 * This is AUTHORED now but GATED: it is executed at the live-install
 * integration milestone that already gates GA (docs/16-capability-roadmap.md).
 * The unit harness cannot run the executor — it persists via repository saves
 * and raw ResourceConnection SQL, and there is no Magento install in the
 * zero-dependency runner (which discovers Test/Unit only, so this file never
 * runs there). Layer-1 (ConformanceRoutingTest) is the divergence control that
 * ships and runs today; this is its DB-backed superset.
 *
 * When the integration harness lands, remove the skip and implement the body:
 *   1. import each spec/fixtures/*.json via WorkflowImporter (shadow status);
 *   2. dispatch a manual execution per fixture against a seeded entity and
 *      drain the queue (delays/waits resolved by the sweeper) to collect the
 *      ordered list of executed step keys and their branch/switch results;
 *   3. run DryRunService against the same definition + entity;
 *   4. assert the dry-run trace's primary path (the edges production actually
 *      took) equals the executor's step sequence — waits/failures excepted,
 *      where dry-run intentionally explores more (assert superset).
 */
class DualEngineConformanceTest extends TestCase
{
    /**
     * @var string[] fixtures both engines must route identically
     */
    private const FIXTURES = [
        'high-value-order-fraud-check.json',
        'multi-region-order-routing.json',
        'abandoned-cart-wait-recovery.json',
    ];

    protected function setUp(): void
    {
        $this->markTestSkipped(
            'Dual-engine conformance is gated on the live-install integration milestone '
            . '(docs/16-capability-roadmap.md): the executor is DB-backed and cannot run in the '
            . 'unit harness. Layer-1 routing-equivalence (ConformanceRoutingTest) ships and runs today.'
        );
    }

    public function testEachFixtureRoutesIdenticallyThroughBothEngines(): void
    {
        // Gated body — see class docblock for the implementation checklist.
        foreach (self::FIXTURES as $fixture) {
            $this->assertIsString($fixture);
        }
    }
}
