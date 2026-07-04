<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Webapi;

use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Model\Webapi\ExecutionStepState;
use MageOS\Workflows\Model\Webapi\StepDetailRedactor;
use MageOS\Workflows\Model\Webapi\WorkflowExecutionStepsProvider;
use PHPUnit\Framework\TestCase;

/**
 * GET /V1/workflow-executions/:id/steps (07): SAFE projection, edge derivation,
 * 404, and — the security pin — that a secret-bearing result/error never
 * reaches the response verbatim.
 */
class WorkflowExecutionStepsProviderTest extends TestCase
{
    private function stepModel(
        string $key,
        string $status,
        ?string $started,
        ?string $finished,
        ?string $result,
        ?string $error
    ): object {
        return new class ($key, $status, $started, $finished, $result, $error) {
            public function __construct(
                private readonly string $key,
                private readonly string $status,
                private readonly ?string $started,
                private readonly ?string $finished,
                private readonly ?string $result,
                private readonly ?string $error
            ) {
            }

            public function getStepKey(): string
            {
                return $this->key;
            }

            public function getStatus(): string
            {
                return $this->status;
            }

            public function getStartedAt(): ?string
            {
                return $this->started;
            }

            public function getFinishedAt(): ?string
            {
                return $this->finished;
            }

            public function getResult(): ?string
            {
                return $this->result;
            }

            public function getError(): ?string
            {
                return $this->error;
            }
        };
    }

    /**
     * @param object[] $rows
     * @param array<string, string> $secrets
     */
    private function provider(array $rows, bool $exists = true, array $secrets = []): WorkflowExecutionStepsProvider
    {
        return new class ($rows, $exists, $secrets) extends WorkflowExecutionStepsProvider {
            /**
             * @param object[] $rows
             * @param array<string, string> $secrets
             */
            public function __construct(
                private readonly array $rows,
                private readonly bool $exists,
                private readonly array $secrets
            ) {
            }

            protected function requireExecution(int $executionId): void
            {
                if (!$this->exists) {
                    throw new NoSuchEntityException(__('No such execution'));
                }
            }

            protected function loadStepModels(int $executionId): array
            {
                return $this->rows;
            }

            protected function secretValues(): array
            {
                return $this->secrets;
            }

            protected function getRedactor(): StepDetailRedactor
            {
                // The REAL redactor, exercised end-to-end through the provider.
                return new StepDetailRedactor();
            }
        };
    }

    public function testProjectsSafeFieldsAndDerivesEdges(): void
    {
        $provider = $this->provider([
            $this->stepModel('a', 'complete', '2026-07-04 10:00:00', '2026-07-04 10:00:02', '{"result":true}', null),
            $this->stepModel('b', 'complete', '2026-07-04 10:00:02', '2026-07-04 10:00:03', '{"result":false}', null),
            $this->stepModel('c', 'complete', null, null, '{"matched":"high"}', null),
            $this->stepModel('d', 'complete', null, null, '{"matched":null}', null),
            $this->stepModel('e', 'complete', null, null, '{"resolution":"event","event":{"x":1}}', null),
            $this->stepModel('f', 'complete', null, null, '{"resolution":"timeout"}', null),
            $this->stepModel('g', 'complete', null, null, '{"status":"success","output":{"foo":"bar"}}', null),
            $this->stepModel('h', 'pending', null, null, null, null),
        ]);

        $steps = $provider->getSteps(7);

        $this->assertCount(8, $steps);
        $this->assertInstanceOf(ExecutionStepState::class, $steps[0]);
        $this->assertSame('on_true', $steps[0]->getEdgeTaken());
        $this->assertSame('on_false', $steps[1]->getEdgeTaken());
        $this->assertSame('case:high', $steps[2]->getEdgeTaken());
        $this->assertSame('default', $steps[3]->getEdgeTaken());
        $this->assertSame('on_event', $steps[4]->getEdgeTaken());
        $this->assertSame('on_timeout', $steps[5]->getEdgeTaken());
        // Action step: output is never read, so no edge.
        $this->assertNull($steps[6]->getEdgeTaken());
        $this->assertNull($steps[7]->getEdgeTaken());
        $this->assertSame('pending', $steps[7]->getStatus());
    }

    public function testActionOutputAndErrorNeverLeakSecretsVerbatim(): void
    {
        $sentinel = 'sk_live_SENTINEL_9f8e7d6c5b4a';
        $rows = [
            // Secret embedded in an action step's interpolated output blob.
            $this->stepModel(
                'notify',
                'complete',
                null,
                null,
                '{"status":"success","output":{"webhook":"https://hooks.test/x?token=' . $sentinel . '"}}',
                null
            ),
            // Secret embedded in a failure message.
            $this->stepModel(
                'charge',
                'failed',
                null,
                null,
                null,
                "Payment failed calling https://api:{$sentinel}@gw.test/charge\nstack frame 2"
            ),
        ];

        $provider = $this->provider($rows, true, ['stripe_key' => $sentinel]);
        $steps = $provider->getSteps(7);

        // Serialize the entire projection and assert the sentinel is nowhere.
        $dump = '';
        foreach ($steps as $s) {
            $dump .= implode('|', [
                $s->getStepKey(),
                $s->getStatus(),
                (string) $s->getEdgeTaken(),
                (string) $s->getErrorSummary(),
            ]);
        }
        $this->assertFalse(
            str_contains($dump, $sentinel),
            'A secret value must never reach the steps API response verbatim'
        );
        // The action output blob is dropped entirely (no edge, no leak).
        $this->assertNull($steps[0]->getEdgeTaken());
        // The error is summarized to a redacted first line, and it exists.
        $this->assertNotNull($steps[1]->getErrorSummary());
        $this->assertStringContainsString('***', (string) $steps[1]->getErrorSummary());
    }

    public function testUnknownExecution404s(): void
    {
        $this->expectException(NoSuchEntityException::class);
        $this->provider([], false)->getSteps(999);
    }
}
