<?php
declare(strict_types=1);

/**
 * The real ConditionEvaluator under test depends on WorkflowRuleFactory, a
 * DI-generated factory class that exists neither in the standalone shim
 * environment nor in a plain composer checkout (Magento generates it at
 * runtime). Define a minimal, signature-faithful stand-in only when nothing
 * else (a real Magento install's generated code) has supplied the class —
 * mirroring how dev/tests/shims/ defers to real Magento, but for a class
 * outside the Magento\ namespace that the shim autoloader cannot serve.
 */

namespace MageOS\Workflows\Model\Rule {

    if (!\class_exists(WorkflowRuleFactory::class, false)) {
        class WorkflowRuleFactory
        {
            /**
             * @param array $data
             * @return WorkflowRule
             */
            public function create(array $data = [])
            {
                throw new \BadMethodCallException(
                    'Test stand-in: override create() in a test fake (see FakeWorkflowRuleFactory)'
                );
            }
        }
    }
}

namespace MageOS\Workflows\Test\Unit\Rule {

    use Magento\Framework\DataObject;
    use Magento\Framework\DataObjectFactory;
    use MageOS\Workflows\Model\Execution\ExecutionContext;
    use MageOS\Workflows\Model\Relation\RelationContext;
    use MageOS\Workflows\Model\Relation\RelationPool;
    use MageOS\Workflows\Model\Rule\ConditionCombinePool;
    use MageOS\Workflows\Model\Rule\ConditionEvaluator;
    use MageOS\Workflows\Model\Rule\ConditionLeafPool;
    use MageOS\Workflows\Model\Rule\ConditionTypeAllowlist;
    use MageOS\Workflows\Model\Rule\HydrationProviderInterface;
    use MageOS\Workflows\Model\Rule\WorkflowRuleFactory;
    use MageOS\Workflows\Test\Unit\Model\Rule\FakeLeafObjectManager;
    use MageOS\Workflows\Test\Unit\Stub\StubHydrationProvider;
    use MageOS\Workflows\Test\Unit\Stub\StubScopeConfig;
    use MageOS\Workflows\Test\Unit\Stub\StubStoreManager;
    use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
    use MageOS\Workflows\Test\Unit\Stub\WorkflowStub;
    use Psr\Log\NullLogger;
    use PHPUnit\Framework\TestCase;

    /**
     * Behavior pins for the production ConditionEvaluator (docs/06-conditions.md):
     * an empty/absent condition tree means "always run" (true), malformed JSON
     * throws instead of silently firing or skipping, and post-delay revalidation
     * (revalidate_entity=true) fails CLOSED when the entity has vanished. The
     * rule/combine machinery is faked at the WorkflowRuleFactory seam; the
     * decode / model-building / hydration-key wiring under test is real.
     */
    class ConditionEvaluatorTest extends TestCase
    {
        private const TREE = '{"type":"combine","aggregator":"all","value":1,"conditions":[]}';

        private FakeWorkflowRuleFactory $ruleFactory;

        public function setUp(): void
        {
            // FakeLeafObjectManager is declared inside ConditionLeafPoolTest.php
            // (same technique as DispatcherTest's factory stand-in); force that
            // file to load before we type against it.
            \class_exists(\MageOS\Workflows\Test\Unit\Model\Rule\ConditionLeafPoolTest::class);
            $this->ruleFactory = new FakeWorkflowRuleFactory();
        }

        // --------------------------------------------------------------
        // Empty/absent tree => true (workflow always fires)
        // --------------------------------------------------------------

        public function testNullRootConditionsAlwaysFire(): void
        {
            $workflow = (new WorkflowStub(42))
                ->setEntityType('sales_order')
                ->setConditionsSerialized(null);

            $result = $this->evaluator()->evaluate($workflow, $this->context());

            $this->assertTrue($result);
            // "Always run" short-circuits before any rule machinery is built.
            $this->assertSame([], $this->ruleFactory->created);
        }

        public function testEmptyAndWhitespaceRootConditionsAlwaysFire(): void
        {
            $evaluator = $this->evaluator();
            $ctx = $this->context();

            $empty = (new WorkflowStub(42))->setEntityType('sales_order')->setConditionsSerialized('');
            $blank = (new WorkflowStub(43))->setEntityType('sales_order')->setConditionsSerialized('   ');

            $this->assertTrue($evaluator->evaluate($empty, $ctx));
            $this->assertTrue($evaluator->evaluate($blank, $ctx));
            $this->assertSame([], $this->ruleFactory->created);
        }

        public function testEmptyJsonTreesAlwaysFire(): void
        {
            $evaluator = $this->evaluator();
            $ctx = $this->context();

            $this->assertTrue($evaluator->evaluateSerialized('[]', 'sales_order', $ctx, false));
            $this->assertTrue($evaluator->evaluateSerialized('{}', 'sales_order', $ctx, false));
            $this->assertTrue($evaluator->evaluateSerialized('null', 'sales_order', $ctx, false));
            $this->assertSame([], $this->ruleFactory->created);
        }

        // --------------------------------------------------------------
        // Malformed conditions => throw, never silently true/false
        // --------------------------------------------------------------

        public function testMalformedJsonThrowsInsteadOfSilentlyFiring(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('not valid JSON');

            $this->evaluator()->evaluateSerialized('{"type":', 'sales_order', $this->context(), false);
        }

        public function testMalformedRootConditionsThrowThroughEvaluate(): void
        {
            $workflow = (new WorkflowStub(42))
                ->setEntityType('sales_order')
                ->setConditionsSerialized('{not json');

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('not valid JSON');

            $this->evaluator()->evaluate($workflow, $this->context());
        }

        public function testNonArrayJsonThrows(): void
        {
            // '"always"' IS valid JSON but not a condition tree: still a hard
            // error, never a silent "run everything".
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('condition-tree array');

            $this->evaluator()->evaluateSerialized('"always"', 'sales_order', $this->context(), false);
        }

        public function testTypeNamingAnUnregisteredExistingClassThrows(): void
        {
            // The security contract (ConditionTypeAllowlist): a stored `type`
            // that would instantiate a real class outside the registered
            // condition surface is malformed data, refused BEFORE loadArray
            // can hand it to the condition factory.
            $tree = '{"type":"combine","aggregator":"all","conditions":['
                . '{"type":"' . addslashes(\ArrayObject::class) . '","attribute":"x","operator":"==","value":"1"}]}';

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('not a registered condition class');

            $this->evaluator()->evaluateSerialized($tree, 'sales_order', $this->context(), false);
        }

        public function testInertNonClassTypeMarkersAreStillAccepted(): void
        {
            // "combine" is not a loadable class: it instantiates nothing, so
            // rejecting it would break stored trees without closing any hole.
            $this->ruleFactory->validateResult = true;

            $result = $this->evaluator()
                ->evaluateSerialized(self::TREE, 'sales_order', $this->context(), false);

            $this->assertTrue($result);
        }

        // --------------------------------------------------------------
        // Snapshot pass (revalidate=false) vs fresh revalidation
        // --------------------------------------------------------------

        public function testSnapshotPassValidatesTriggerDataWithHydrationKeys(): void
        {
            $this->ruleFactory->validateResult = true;
            // Provider is EMPTY: a snapshot pass must not need the entity.
            $evaluator = $this->evaluator(new StubHydrationProvider([]));

            $result = $evaluator->evaluateSerialized(self::TREE, 'sales_order', $this->context(), false);

            $this->assertTrue($result);
            // The tree was loaded into a rule scoped to the entity type.
            $this->assertCount(1, $this->ruleFactory->created);
            $rule = $this->ruleFactory->created[0];
            $this->assertSame('sales_order', $rule->entityType);
            $this->assertEquals(json_decode(self::TREE, true), $rule->conditions->loaded[0]);
            // The validated model wraps the frozen trigger snapshot…
            $model = $rule->conditions->validated[0];
            $this->assertInstanceOf(DataObject::class, $model);
            $this->assertSame('processing', $model->getData('status'));
            // …and carries the hydration convention keys for lazy phase 2.
            $this->assertSame('sales_order', $model->getData(HydrationProviderInterface::KEY_ENTITY_TYPE));
            $this->assertSame(7, $model->getData(HydrationProviderInterface::KEY_ENTITY_ID));
            $this->assertFalse($model->getData(HydrationProviderInterface::KEY_FRESH));
            $this->assertInstanceOf(
                HydrationProviderInterface::class,
                $model->getData(HydrationProviderInterface::KEY_PROVIDER)
            );
        }

        public function testRevalidateTrueHydratesTheEntityFresh(): void
        {
            $this->ruleFactory->validateResult = false;
            $entity = new DataObject(['status' => 'canceled']);
            $evaluator = $this->evaluator(new StubHydrationProvider(['sales_order:7' => $entity]));

            $result = $evaluator->evaluateSerialized(self::TREE, 'sales_order', $this->context(), true);

            // The combine's verdict passes through (here: no longer matches).
            $this->assertFalse($result);
            // Present-time state was validated, NOT the trigger snapshot.
            $model = $this->ruleFactory->created[0]->conditions->validated[0];
            $this->assertSame($entity, $model);
            $this->assertSame('canceled', $model->getData('status'));
            $this->assertTrue($model->getData(HydrationProviderInterface::KEY_FRESH));
        }

        public function testRevalidateTrueWithVanishedEntityFailsClosed(): void
        {
            $this->ruleFactory->validateResult = true; // would fire if consulted
            // Entity deleted during the delay: provider has nothing to return.
            $evaluator = $this->evaluator(new StubHydrationProvider([]));

            $result = $evaluator->evaluateSerialized(self::TREE, 'sales_order', $this->context(), true);

            // Fail CLOSED: false, no crash — and the tree was never validated
            // against a phantom model.
            $this->assertFalse($result);
            $this->assertSame([], $this->ruleFactory->created[0]->conditions->validated);
        }

        // --------------------------------------------------------------
        // Harness
        // --------------------------------------------------------------

        private function evaluator(?StubHydrationProvider $provider = null): ConditionEvaluator
        {
            // Empty pools: any type that is an EXISTING class is rejected,
            // while inert marker strings (self::TREE's "combine") pass — the
            // exact production contract with no domain packs installed.
            $om = new FakeLeafObjectManager([]);
            return new ConditionEvaluator(
                $this->ruleFactory,
                $provider ?? new StubHydrationProvider([]),
                $this->dataObjectFactory(),
                new RelationContext(
                    new RelationPool([]),
                    new StubStoreManager(),
                    new StubScopeConfig(),
                    new NullLogger()
                ),
                new ConditionTypeAllowlist(
                    new ConditionCombinePool($om, []),
                    new ConditionLeafPool($om, [])
                )
            );
        }

        private function context(): ExecutionContext
        {
            return new ExecutionContext(
                new WorkflowExecutionStub('exec-uuid', 7, 1),
                ['status' => 'processing', 'grand_total' => '49.99']
            );
        }

        /**
         * A DataObjectFactory double whose create(['data' => [...]]) returns a
         * DataObject seeded with that data (same double FanOutExpanderTest
         * uses: real Magento's factory routes through the object manager,
         * absent in a unit test).
         */
        private function dataObjectFactory(): DataObjectFactory
        {
            return new class extends DataObjectFactory {
                public function __construct()
                {
                }

                public function create(array $arguments = []): DataObject
                {
                    $data = $arguments['data'] ?? [];
                    return new DataObject(is_array($data) ? $data : []);
                }
            };
        }
    }

    /**
     * Factory fake at the WorkflowRuleFactory seam: every create() yields a
     * fresh recording rule whose combine returns $validateResult.
     */
    class FakeWorkflowRuleFactory extends WorkflowRuleFactory
    {
        /** @var FakeWorkflowRule[] */
        public array $created = [];

        public bool $validateResult = true;

        public function __construct()
        {
        }

        public function create(array $data = [])
        {
            $rule = new FakeWorkflowRule(new FakeConditionCombine($this->validateResult));
            $this->created[] = $rule;
            return $rule;
        }
    }

    /**
     * Recording stand-in for the transient WorkflowRule: captures the entity
     * type and hands out one combine. (Duck-typed — ConditionEvaluator only
     * calls setEntityType() and getConditions().)
     */
    class FakeWorkflowRule
    {
        public ?string $entityType = null;

        public function __construct(public readonly FakeConditionCombine $conditions)
        {
        }

        public function setEntityType(string $entityType): self
        {
            $this->entityType = $entityType;
            return $this;
        }

        public function getConditions(): FakeConditionCombine
        {
            return $this->conditions;
        }
    }

    /**
     * Recording stand-in for the root combine: remembers every loadArray()
     * tree and every validate() model, returning a canned verdict.
     */
    class FakeConditionCombine
    {
        /** @var array<int, array> */
        public array $loaded = [];

        /** @var array<int, mixed> models passed to validate() */
        public array $validated = [];

        public function __construct(private readonly bool $result)
        {
        }

        public function loadArray(array $tree): self
        {
            $this->loaded[] = $tree;
            return $this;
        }

        public function validate($model): bool
        {
            $this->validated[] = $model;
            return $this->result;
        }
    }
}
