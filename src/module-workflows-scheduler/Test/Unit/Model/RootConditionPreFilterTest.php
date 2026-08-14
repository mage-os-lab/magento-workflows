<?php
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Unit\Model;

use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterfaceFactory;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Model\Rule\ConditionEvaluator;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsScheduler\Model\RootConditionPreFilter;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\WorkflowStub;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * The fallback-path pre-filter must reach the SAME verdict the engine's
 * first-run root gate would: the transient execution carries the REAL entity
 * id (hydration stays available — unlike the aggregation MembershipEvaluator's
 * entity_id = 0 pinning), the dispatch payload is the trigger snapshot, and
 * any evaluation error fails OPEN (dispatch; the engine is the authority).
 */
class RootConditionPreFilterTest extends TestCase
{
    private const CONDITIONS =
        '{"aggregator":"all","conditions":[{"attribute":"state","operator":"==","value":"new"}]}';

    public function setUp(): void
    {
        // The generated WorkflowExecutionInterfaceFactory stand-in is declared
        // inside DispatcherTest.php (bracketed multi-namespace technique);
        // force that file to load before we type against it.
        \class_exists(\MageOS\Workflows\Test\Unit\Model\Engine\DispatcherTest::class);
    }

    /**
     * Evaluator double capturing the context it was handed and returning (or
     * throwing) a canned verdict. The parent constructor is deliberately not
     * invoked; only evaluate() — the one method the pre-filter calls — is
     * overridden.
     */
    private function evaluator(bool $verdict, bool $throw = false): ConditionEvaluator
    {
        return new class ($verdict, $throw) extends ConditionEvaluator {
            public ?ExecutionContext $seenContext = null;

            public function __construct(private readonly bool $verdict, private readonly bool $throw)
            {
            }

            public function evaluate(WorkflowInterface $workflow, ExecutionContext $ctx): bool
            {
                $this->seenContext = $ctx;
                if ($this->throw) {
                    throw new \RuntimeException('condition pool exploded');
                }
                return $this->verdict;
            }
        };
    }

    private function preFilter(ConditionEvaluator $evaluator): RootConditionPreFilter
    {
        return new RootConditionPreFilter(
            $evaluator,
            new WorkflowExecutionInterfaceFactory(
                static fn (array $data = []): WorkflowExecutionInterface => new WorkflowExecutionStub()
            ),
            new NullLogger()
        );
    }

    private function workflow(?string $conditions = self::CONDITIONS): WorkflowStub
    {
        return new WorkflowStub([
            'workflow_id' => 11,
            'name' => 'Fallback schedule',
            'entity_type' => 'sales_order',
            'conditions_serialized' => $conditions,
        ]);
    }

    public function testEmptyConditionsMatchWithoutTouchingTheEvaluator(): void
    {
        $evaluator = $this->evaluator(false);

        $this->assertTrue($this->preFilter($evaluator)->matches($this->workflow(null), 5, ['entity_id' => 5]));
        $this->assertTrue($this->preFilter($evaluator)->matches($this->workflow('  '), 5, ['entity_id' => 5]));
        $this->assertNull($evaluator->seenContext, 'empty conditions must not reach the evaluator');
    }

    public function testDelegatesTheVerdictToTheEvaluator(): void
    {
        $payload = ['entity_id' => 42, 'state' => 'new', 'store_id' => 3];

        $this->assertTrue($this->preFilter($this->evaluator(true))->matches($this->workflow(), 42, $payload));
        $this->assertFalse($this->preFilter($this->evaluator(false))->matches($this->workflow(), 42, $payload));
    }

    public function testContextCarriesTheRealEntityIdAndPayloadAsTrigger(): void
    {
        $evaluator = $this->evaluator(true);
        $payload = ['entity_id' => 42, 'state' => 'new', 'store_id' => 3];

        $this->preFilter($evaluator)->matches($this->workflow(), 42, $payload);

        $ctx = $evaluator->seenContext;
        $this->assertNotNull($ctx);
        // Real entity id => the evaluator's phase-2 hydration stays available,
        // which is what makes the verdict engine-equivalent and skipping safe.
        $this->assertSame(42, $ctx->getEntityId());
        $this->assertSame(3, (int) $ctx->getExecution()->getStoreId());
        $this->assertSame($payload, $ctx->getTrigger());
    }

    public function testEvaluationErrorsFailOpen(): void
    {
        $result = $this->preFilter($this->evaluator(false, true))
            ->matches($this->workflow(), 42, ['entity_id' => 42]);

        $this->assertTrue($result, 'an evaluation error must dispatch (fail open), never silently skip');
    }
}
