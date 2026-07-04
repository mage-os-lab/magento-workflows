<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Webapi;

use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Model\Webapi\ExecutionStepState;
use MageOS\Workflows\Model\Webapi\WorkflowExecutionStepsProvider;
use PHPUnit\Framework\TestCase;

/**
 * GET /V1/workflow-executions/:id/steps (07): projection + 404 behaviour.
 * The collection/repository I/O is stubbed via the provider's protected seams
 * so the projection logic is exercised without the generated CollectionFactory.
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
     */
    private function provider(array $rows, bool $exists = true): WorkflowExecutionStepsProvider
    {
        return new class ($rows, $exists) extends WorkflowExecutionStepsProvider {
            /** @param object[] $rows */
            public function __construct(private readonly array $rows, private readonly bool $exists)
            {
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
        };
    }

    public function testProjectsStepRows(): void
    {
        $provider = $this->provider([
            $this->stepModel('s1', 'complete', '2026-07-04 10:00:00', '2026-07-04 10:00:02', '{"ok":true}', null),
            $this->stepModel('s2', 'failed', '2026-07-04 10:00:02', '2026-07-04 10:00:03', null, 'boom'),
            $this->stepModel('s3', 'pending', null, null, null, null),
        ]);

        $steps = $provider->getSteps(7);

        $this->assertCount(3, $steps);
        $this->assertInstanceOf(ExecutionStepState::class, $steps[0]);
        $this->assertSame('s1', $steps[0]->getStepKey());
        $this->assertSame('complete', $steps[0]->getStatus());
        $this->assertSame('2026-07-04 10:00:00', $steps[0]->getStartedAt());
        $this->assertSame('2026-07-04 10:00:02', $steps[0]->getFinishedAt());
        $this->assertSame('{"ok":true}', $steps[0]->getResult());
        $this->assertSame('boom', $steps[1]->getError());
        $this->assertNull($steps[2]->getStartedAt());
    }

    public function testUnknownExecution404s(): void
    {
        $this->expectException(NoSuchEntityException::class);
        $this->provider([], false)->getSteps(999);
    }
}
