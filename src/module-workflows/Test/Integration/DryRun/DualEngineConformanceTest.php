<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\DryRun;

use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\DispatcherInterface;
use MageOS\Workflows\Model\DryRun\DryRunRequest;
use MageOS\Workflows\Model\DryRun\DryRunService;
use MageOS\Workflows\Model\DryRun\Trace;
use MageOS\Workflows\Model\Import\WorkflowImporter;
use MageOS\Workflows\Model\Queue\ExecuteConsumer;
use MageOS\Workflows\Model\Queue\ResumeConsumer;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Test\Integration\_files\WorkflowEngineTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Plan #13 (docs/20 §4) — layer-2 of the dual-engine conformance suite, now
 * IMPLEMENTED (the skip is removed). Each published spec fixture is imported in
 * shadow status, dispatched against a seeded order through the real DB-backed
 * {@see \MageOS\Workflows\Model\Engine\Executor}, and its executed step
 * sequence is diffed against the {@see DryRunService} path over the same
 * definition + entity.
 *
 * Contract (per the original class docblock): the executor's ordered executed
 * step keys must be a subset of the dry-run trace's step keys — dry-run is a
 * superset at waits/branches it cannot resolve, and both engines must agree on
 * a root-condition skip. Any routing divergence between the two walkers fails.
 *
 * Both engines resolve condition attributes by hydrating the real order (the
 * trigger snapshot carries only ids), so their condition results are identical
 * by construction — the test isolates ROUTING equivalence, not data drift.
 *
 * @magentoDbIsolation enabled
 * @magentoDataFixture Magento/Sales/_files/order.php
 */
class DualEngineConformanceTest extends TestCase
{
    use WorkflowEngineTestTrait;

    /**
     * @var string[] fixtures both engines must route identically
     */
    private const FIXTURES = [
        'high-value-order-fraud-check.json',
        'multi-region-order-routing.json',
        'abandoned-cart-wait-recovery.json',
    ];

    public function testEachFixtureRoutesIdenticallyThroughBothEngines(): void
    {
        $order = $this->seededOrder();
        $orderId = (int) $order->getId();
        $storeId = (int) $order->getStoreId();

        foreach (self::FIXTURES as $fixture) {
            $workflow = $this->importShadow($fixture);

            // Executor path: dispatch a manual execution and drain to terminal.
            $execution = $this->om()->get(DispatcherInterface::class)->dispatch(
                (int) $workflow->getWorkflowId(),
                ['entity_id' => $orderId, 'store_id' => $storeId],
                'manual'
            );
            $this->assertNotNull($execution, sprintf('[%s] shadow dispatch should create an execution', $fixture));
            $executionId = (int) $execution->getExecutionId();
            $this->drainToTerminal($executionId);

            $finalStatus = $this->reloadExecution($executionId)->getStatus();
            $executorKeys = $this->distinct($this->stepKeys($executionId));

            // Dry-run path over the same definition + entity.
            $trace = $this->dryRun($workflow, $orderId);
            $this->assertFalse($trace->hasErrors(), sprintf('[%s] dry-run should not report blocking errors', $fixture));
            $dryRunKeys = array_map(
                static fn ($s): string => $s->getStepKey(),
                $trace->getSteps()
            );

            if ($finalStatus === WorkflowExecutionInterface::STATUS_SKIPPED) {
                $this->assertTrue(
                    $trace->isSkipped(),
                    sprintf('[%s] executor skipped on root conditions; dry-run must agree', $fixture)
                );
                continue;
            }

            $missing = array_diff($executorKeys, $dryRunKeys);
            $this->assertSame(
                [],
                $missing,
                sprintf(
                    '[%s] every executor step must appear in the dry-run trace (superset). '
                    . 'Executor=[%s] DryRun=[%s]',
                    $fixture,
                    implode(',', $executorKeys),
                    implode(',', $dryRunKeys)
                )
            );
        }
    }

    private function importShadow(string $fixture): WorkflowInterface
    {
        $path = $this->specPath('fixtures/' . $fixture);
        $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $envelope = $this->asEnvelope($data);

        $result = $this->om()->get(WorkflowImporter::class)->import(
            $envelope,
            ValidationContext::MODE_SYSTEM,
            WorkflowInterface::STATUS_SHADOW
        );
        return $result->getWorkflow();
    }

    /**
     * Wrap a bare definition fixture in the export envelope; pass real envelopes through.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function asEnvelope(array $data): array
    {
        if (($data['format'] ?? null) === WorkflowImporter::FORMAT) {
            return $data;
        }
        return [
            'format' => WorkflowImporter::FORMAT,
            'name' => 'conformance ' . uniqid('', true),
            'entity_type' => 'sales_order',
            'trigger_type' => 'event',
            'trigger_ref' => 'sales.order.created',
            'conditions_serialized' => null,
            'definition' => $data,
        ];
    }

    private function dryRun(WorkflowInterface $workflow, int $orderId): Trace
    {
        $request = new DryRunRequest(
            $workflow->getDefinition(),
            $workflow->getConditionsSerialized(),
            'sales_order',
            $orderId,
            null,
            (int) $workflow->getWorkflowId(),
            $workflow->getName(),
            $workflow->getFanOut()
        );
        return $this->om()->get(DryRunService::class)->run($request);
    }

    /**
     * Drive execute + resume until the execution reaches a terminal status,
     * resolving delays/waits by rewinding resume_at and resuming (timeout edge).
     */
    private function drainToTerminal(int $executionId): void
    {
        $terminal = [
            WorkflowExecutionInterface::STATUS_COMPLETE,
            WorkflowExecutionInterface::STATUS_FAILED,
            WorkflowExecutionInterface::STATUS_SKIPPED,
            WorkflowExecutionInterface::STATUS_CANCELLED,
        ];

        $this->om()->get(ExecuteConsumer::class)->process((string) $executionId);

        for ($i = 0; $i < 40; $i++) {
            $status = $this->reloadExecution($executionId)->getStatus();
            if (in_array($status, $terminal, true)) {
                return;
            }
            if ($status !== WorkflowExecutionInterface::STATUS_WAITING) {
                return; // not progressing (should not happen in shadow drains)
            }
            $this->rewindTimestamp(
                'mageos_workflow_execution_step',
                'resume_at',
                $this->gmPast(120),
                'execution_id',
                $executionId
            );
            try {
                $this->om()->get(ResumeConsumer::class)->process((string) $executionId);
            } catch (\Throwable $e) {
                return; // a retryable escape ends the drain; the diff below still runs
            }
        }
    }

    /**
     * @param string[] $keys
     * @return string[]
     */
    private function distinct(array $keys): array
    {
        return array_values(array_unique($keys));
    }

    /**
     * Resolves the monorepo's published spec/ directory from either layout,
     * same idiom as the sibling Test/Integration/Console/ImportExportRoundTripTest
     * (this class is adjusted from Test/Unit/Model/DryRun/ConformanceRoutingTest,
     * which uses dirname(__DIR__, 6) from Test/Unit/Model/DryRun).
     *
     * __DIR__ here is .../Test/Integration/DryRun. Counting path segments to
     * the sibling that contains spec/:
     *   dirname(__DIR__, 1) = .../Test/Integration
     *   dirname(__DIR__, 2) = .../Test
     *   dirname(__DIR__, 3) = .../<module root>              (e.g. src/module-workflows, or vendor/mage-os/workflows)
     *   dirname(__DIR__, 4) = .../<module vendor namespace>  (e.g. src, or vendor/mage-os)
     *   dirname(__DIR__, 5) = .../<repo root or vendor>       (repo-root/spec in dev, <magento>/vendor/spec in CI)
     * So depth 5 covers both the local dev checkout (<repo>/spec) and a
     * composer-installed Magento where CI stages the monorepo spec/ dir to
     * <magento>/vendor/spec (docs/20 §2.2, this file lives at
     * <magento>/vendor/mage-os/workflows/Test/Integration/DryRun/...). A
     * couple of neighboring depths are tried defensively since the exact
     * vendor path depends on the installed package layout.
     */
    private function specPath(string $relative): string
    {
        foreach ([5, 4, 6] as $depth) {
            $candidate = dirname(__DIR__, $depth) . '/spec';
            if (is_dir($candidate)) {
                return $candidate . '/' . ltrim($relative, '/');
            }
        }
        throw new \RuntimeException('Could not locate the spec/ directory from ' . __DIR__);
    }
}
