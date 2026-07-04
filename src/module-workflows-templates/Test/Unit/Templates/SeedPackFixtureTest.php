<?php
declare(strict_types=1);

namespace MageOS\WorkflowsTemplates\Test\Unit\Templates;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\AuthorizationInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\Action\ActionPool;
use MageOS\Workflows\Model\DryRun\DryRunRequest;
use MageOS\Workflows\Model\DryRun\DryRunService;
use MageOS\Workflows\Model\DryRun\FanOutTracePreview;
use MageOS\Workflows\Model\DryRun\Walker;
use MageOS\Workflows\Model\Engine\DelayCalculator;
use MageOS\Workflows\Model\Import\ImportResult;
use MageOS\Workflows\Model\Import\WorkflowImporter;
use MageOS\Workflows\Model\Relation\RelationContext;
use MageOS\Workflows\Model\Relation\RelationPool;
use MageOS\Workflows\Model\Template\BundledTemplateSource;
use MageOS\Workflows\Model\Template\CompatibilityChecker;
use MageOS\Workflows\Model\Template\InstallProvenance;
use MageOS\Workflows\Model\Template\ParameterEngine;
use MageOS\Workflows\Model\Template\TemplateInstaller;
use MageOS\Workflows\Model\Template\TemplateInstallRequest;
use MageOS\Workflows\Model\Trigger\TriggerRegistry;
use MageOS\Workflows\Model\Validation\Check\ActionAuthorizationCheck;
use MageOS\Workflows\Model\Validation\Check\ActionCodesCheck;
use MageOS\Workflows\Model\Validation\Check\ConditionsShapeCheck;
use MageOS\Workflows\Model\Validation\Check\FanOutAlignmentCheck;
use MageOS\Workflows\Model\Validation\Check\GraphCheck;
use MageOS\Workflows\Model\Validation\Check\ProfileCheck;
use MageOS\Workflows\Model\Validation\Check\RelationConditionsCheck;
use MageOS\Workflows\Model\Validation\Check\StructuralCheck;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationSubject;
use MageOS\Workflows\Model\Validation\WorkflowValidator;
use MageOS\Workflows\Model\Variable\SecretsProviderInterface;
use MageOS\Workflows\Model\Variable\VariableResolver;
use MageOS\Workflows\Test\Unit\Stub\SecretsProviderStub;
use MageOS\Workflows\Test\Unit\Stub\StubAction;
use MageOS\Workflows\Test\Unit\Stub\StubConditionEvaluator;
use MageOS\Workflows\Test\Unit\Stub\StubHydrationProvider;
use MageOS\Workflows\Test\Unit\Stub\StubScopeConfig;
use MageOS\Workflows\Test\Unit\Stub\StubSimulateableAction;
use MageOS\Workflows\Test\Unit\Stub\StubSimulationContextFactory;
use MageOS\Workflows\Test\Unit\Stub\StubStoreManager;
use MageOS\Workflows\Test\Unit\Stub\WorkflowStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * 06 stage 5 CI fixture test: the "honesty mechanism" the README asks every
 * shipped template to carry (src/module-workflows-templates/templates/
 * README.md "CI fixture test"). Loops over every templates/*.json (the
 * standalone runner does not support @dataProvider) and for each one:
 *
 *  1. Parses via the real BundledTemplateSource (fake ModuleDirReader
 *     pointed at this module's own templates/ dir) -- the same source/parse
 *     path the gallery uses -- and asserts the envelope's template/workflow
 *     split and format tag.
 *  2. Runs the real CompatibilityChecker against a real ActionPool (every
 *     action code shipped in module-workflows-actions-core) and a real
 *     TriggerRegistry (every event in module-workflows-triggers-core's
 *     workflow_triggers.xml), with every module present and an Enterprise+B2B
 *     edition so even the deliberately edition-gated template reports
 *     compatible in this environment.
 *  3. Runs the real TemplateInstaller (ParameterEngine + a fake importer that
 *     performs the exact structural-envelope-assert + WorkflowValidator pass
 *     the real WorkflowImporter runs, using the real 8-check validator pool
 *     in di.xml order) with representative parameter values for every
 *     required parameter (declared defaults cover the rest): asserts the
 *     lifted envelope has no leftover %param.*% tokens and the real validator
 *     reports zero errors.
 *  4. For every template whose definition contains a branch/switch/wait step
 *     (the ones actually exercising a decision), runs a DryRunService smoke:
 *     the synthetic walk must not error.
 */
class SeedPackFixtureTest extends TestCase
{
    /** Every action code registered in module-workflows-actions-core/etc/di.xml. */
    private const ALL_ACTION_CODES = [
        'order.add_comment', 'order.change_status', 'order.hold', 'order.unhold',
        'order.create_invoice', 'order.create_shipment', 'order.create_creditmemo', 'order.cancel',
        'customer.assign_group', 'customer.set_attribute', 'customer.newsletter', 'customer.anonymize',
        'product.set_attribute', 'product.set_status', 'product.set_stock',
        'product.set_categories', 'product.set_special_price',
        'marketing.generate_coupon',
        'notify.email', 'notify.webhook', 'notify.admin',
        'flow.set_variable',
    ];

    /** Every event registered in module-workflows-triggers-core/etc/workflow_triggers.xml. */
    private const ALL_TRIGGER_EVENTS = [
        'sales.order.created' => 'sales_order',
        'sales.order.updated' => 'sales_order',
        'sales.order.status_changed' => 'sales_order',
        'sales.invoice.created' => 'sales_order',
        'sales.shipment.created' => 'sales_order',
        'sales.creditmemo.created' => 'sales_order',
        'customer.created' => 'customer',
        'customer.updated' => 'customer',
        'customer.group_changed' => 'customer',
        'catalog.product.review_submitted' => 'catalog_product',
        'inventory.stock_threshold_crossed' => 'catalog_product',
        'quote.abandoned' => 'quote',
    ];

    /**
     * Representative values for the parameters this pack declares `required`
     * with no default -- everything else falls through to its declared
     * default, exactly as a real install-with-defaults would.
     *
     * @var array<string, array<string, string>>
     */
    private const REQUIRED_PARAM_VALUES = [
        'abandoned-cart-recovery-coupon' => ['coupon_rule_id' => '7'],
        'high-value-order-fraud-hold' => [
            'fraud_webhook_url' => 'https://fraud.example.test/score',
            'fraud_signing_secret' => 'fraud_hmac_key',
        ],
        'vip-auto-group-assignment' => ['vip_group_id' => '5'],
        'new-customer-welcome-series' => ['incentive_rule_id' => '3'],
        'stock-threshold-supplier-webhook' => [
            'supplier_webhook_url' => 'https://supplier.example.test/reorder',
            'supplier_signing_secret' => 'supplier_hmac_key',
        ],
        'product-review-triage' => ['reward_rule_id' => '9'],
        'vip-order-notification' => ['vip_group_id' => '42'],
    ];

    /** Template codes whose definition contains a branch/switch/wait decision, worth a dry-run smoke. */
    private const DRY_RUN_CANDIDATES = [
        'abandoned-cart-recovery-coupon',
        'high-value-order-fraud-hold',
        'guest-order-registration-invite',
        'product-review-triage',
        'order-stuck-in-processing-escalation',
    ];

    public function testEverySeedTemplatePassesTheFullPipeline(): void
    {
        $source = $this->bundledSource();
        $summaries = $source->list();

        $codes = array_map(static fn ($s) => $s->getCode(), $summaries);
        sort($codes);
        $this->assertTrue(count($codes) >= 12, 'The seed pack must ship at least 12 templates.');
        $this->assertTrue(count($codes) <= 15, 'The seed pack should ship at most 15 templates.');
        $this->assertSame($codes, array_values(array_unique($codes)), 'Template codes must be unique.');

        $compatibilityChecker = $this->compatibilityChecker();
        $validator = $this->fullWorkflowValidator();

        $sawWait = false;
        $sawSwitch = false;

        foreach ($summaries as $summary) {
            $code = $summary->getCode();

            // 1. Parsed via the real bundled source: format tag + template/workflow split.
            $raw = json_decode($source->get($code), true);
            $this->assertTrue(is_array($raw), "$code: envelope must decode to an object");
            $this->assertSame('mageos-workflow-template/1', $raw['format'] ?? null, "$code: format tag");
            $this->assertTrue(is_array($raw['template'] ?? null), "$code: template node");
            $this->assertTrue(is_array($raw['workflow'] ?? null), "$code: workflow node");
            $this->assertTrue(($raw['template']['code'] ?? '') !== '', "$code: template.code");
            $this->assertTrue(
                preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', (string) $raw['template']['code']) === 1,
                "$code: code must be kebab-case"
            );
            $this->assertTrue(
                count($raw['template']['parameters'] ?? []) <= 3,
                "$code: templates must declare at most 3 parameters"
            );
            foreach (['name', 'entity_type', 'trigger_type', 'trigger_ref', 'definition'] as $field) {
                $this->assertArrayHasKey($field, $raw['workflow'], "$code: workflow.$field required");
            }

            // 2. Real CompatibilityChecker against the real (fully populated) pools.
            $compat = $compatibilityChecker->check($summary);
            $this->assertTrue(
                $compat->isCompatible(),
                sprintf('%s: expected compatible, got: %s', $code, implode(' ', $compat->getMessages()))
            );

            // 3. TemplateInstaller: default-substituted install, lifted envelope
            //    passes the real validator with zero leftover tokens.
            $importer = $this->recordingImporter($validator);
            $installer = new TemplateInstaller(
                $source,
                $compatibilityChecker,
                new ParameterEngine(),
                $importer,
                $this->noopProvenance(),
                $this->noopSecrets(),
                new NullLogger()
            );

            $paramValues = self::REQUIRED_PARAM_VALUES[$code] ?? [];
            $result = $installer->install(new TemplateInstallRequest(
                $code,
                $paramValues,
                WorkflowInterface::STATUS_DISABLED,
                ValidationContext::MODE_ADMIN_CONTEXT,
                'test.fixture',
                []
            ));

            $this->assertNotNull($result->getWorkflow(), "$code: install must return a workflow");
            $this->assertCount(1, $importer->calls, "$code: exactly one import call");
            $envelope = $importer->calls[0]['envelope'];
            $this->assertSame(WorkflowImporter::FORMAT, $envelope['format'], "$code: lifted envelope format tag");
            $encoded = (string) json_encode($envelope);
            $this->assertTrue(!str_contains($encoded, '%param.'), "$code: no leftover %param.*% tokens");

            $definitionArray = $envelope['definition'];
            foreach ($definitionArray['steps'] ?? [] as $step) {
                if (($step['type'] ?? null) === 'wait') {
                    $sawWait = true;
                }
                if (($step['type'] ?? null) === 'switch') {
                    $sawSwitch = true;
                }
            }

            // 4. DryRunService smoke for the decision-bearing templates.
            if (in_array($code, self::DRY_RUN_CANDIDATES, true)) {
                $this->assertDryRunSmoke($code, $envelope);
            }
        }

        $this->assertTrue($sawWait, 'At least one seed template must showcase a schema-2 wait step.');
        $this->assertTrue($sawSwitch, 'At least one seed template must showcase a schema-3 switch step.');
    }

    private function bundledSource(): BundledTemplateSource
    {
        $base = dirname(__DIR__, 3);
        $reader = new class ($base) extends ModuleDirReader {
            public function __construct(private readonly string $base)
            {
            }

            public function getModuleDir($type, $moduleName)
            {
                return $moduleName === 'MageOS_WorkflowsTemplates' ? $this->base : '';
            }
        };

        return new BundledTemplateSource($reader, ['mageos' => 'MageOS_WorkflowsTemplates']);
    }

    private function compatibilityChecker(): CompatibilityChecker
    {
        $pool = new ActionPool(array_combine(
            self::ALL_ACTION_CODES,
            array_map(static fn (string $code): StubAction => new StubAction($code, $code), self::ALL_ACTION_CODES)
        ));

        $triggers = self::ALL_TRIGGER_EVENTS;
        $registry = new class ($triggers) extends TriggerRegistry {
            /** @param array<string, string> $triggers event => entity */
            public function __construct(private readonly array $triggers)
            {
            }

            public function getByEvent(string $event): ?array
            {
                return isset($this->triggers[$event])
                    ? ['event' => $event, 'entity' => $this->triggers[$event], 'label' => $event]
                    : null;
            }

            public function getAll(): array
            {
                $all = [];
                foreach ($this->triggers as $event => $entity) {
                    $all[$event] = ['event' => $event, 'entity' => $entity, 'label' => $event];
                }
                return $all;
            }
        };

        // Every module present; Enterprise + B2B so even the deliberately
        // edition-gated b2b-net-terms-payment-reminder template reports
        // compatible in this fully-populated environment.
        $moduleManager = new class extends ModuleManager {
            public function __construct()
            {
            }

            public function isEnabled($moduleName)
            {
                return true;
            }
        };

        $metadata = new class implements ProductMetadataInterface {
            public function getEdition()
            {
                return 'Enterprise';
            }

            public function getVersion()
            {
                return '2.4.7';
            }

            public function getName()
            {
                return 'Mage-OS';
            }
        };

        return new CompatibilityChecker($pool, $registry, $moduleManager, $metadata);
    }

    /**
     * The real 8-check pool, in the exact order registered in
     * src/module-workflows/etc/di.xml.
     */
    private function fullWorkflowValidator(): WorkflowValidator
    {
        $pool = new ActionPool(array_combine(
            self::ALL_ACTION_CODES,
            array_map(static fn (string $code): StubAction => new StubAction($code, $code), self::ALL_ACTION_CODES)
        ));
        $triggers = self::ALL_TRIGGER_EVENTS;
        $triggerRegistry = new class ($triggers) extends TriggerRegistry {
            /** @param array<string, string> $triggers */
            public function __construct(private readonly array $triggers)
            {
            }

            public function getByEvent(string $event): ?array
            {
                return isset($this->triggers[$event])
                    ? ['event' => $event, 'entity' => $this->triggers[$event], 'label' => $event]
                    : null;
            }
        };

        return new WorkflowValidator([
            'structural' => new StructuralCheck(),
            'graph' => new GraphCheck(),
            'profile' => new ProfileCheck([]),
            'action_codes' => new ActionCodesCheck($pool),
            'action_authorization' => new ActionAuthorizationCheck($pool, $this->permissiveAuthorization()),
            'conditions_shape' => new ConditionsShapeCheck(),
            'relation_conditions' => new RelationConditionsCheck(),
            'fan_out_alignment' => new FanOutAlignmentCheck(new RelationPool([]), $triggerRegistry),
        ]);
    }

    private function permissiveAuthorization(): AuthorizationInterface
    {
        return new class implements AuthorizationInterface {
            public function isAllowed($resource, $privilege = null)
            {
                return true;
            }
        };
    }

    /**
     * A fake WorkflowImporter that performs the SAME structural-assert +
     * WorkflowValidator pass the real WorkflowImporter runs (Model/Import/
     * WorkflowImporter.php import()), against the real 8-check validator
     * pool -- without needing the generated WorkflowFactory/repository this
     * standalone environment cannot construct.
     */
    private function recordingImporter(WorkflowValidator $validator): WorkflowImporter
    {
        return new class ($validator) extends WorkflowImporter {
            /** @var array<int, array{envelope: array, authMode: string, status: int}> */
            public array $calls = [];

            public function __construct(private readonly WorkflowValidator $validator)
            {
            }

            public function import(array $envelope, string $authMode, int $status = 0): ImportResult
            {
                $this->calls[] = ['envelope' => $envelope, 'authMode' => $authMode, 'status' => $status];

                WorkflowImporter::assertEnvelope($envelope);

                $conditionsSerialized = $envelope['conditions_serialized'] ?? null;
                $conditionsSerialized = is_string($conditionsSerialized) ? $conditionsSerialized : null;

                $result = $this->validator->validate(
                    new ValidationSubject((string) json_encode($envelope['definition']), $conditionsSerialized),
                    new ValidationContext($authMode)
                );
                if ($result->hasErrors()) {
                    throw new LocalizedException(__(
                        'The imported workflow is invalid: %1',
                        implode(' ', array_map(
                            static fn ($m): string => rtrim($m->getMessage(), '.') . '.',
                            $result->getErrors()
                        ))
                    ));
                }

                return new ImportResult((new WorkflowStub())->setWorkflowId(1), $result);
            }
        };
    }

    private function noopProvenance(): InstallProvenance
    {
        return new class extends InstallProvenance {
            public function __construct()
            {
            }

            public function record(
                int $workflowId,
                string $templateCode,
                string $templateVersion,
                array $parameters,
                string $installedBy
            ): int {
                return 1;
            }
        };
    }

    private function noopSecrets(): SecretsProviderInterface
    {
        return new class implements SecretsProviderInterface {
            public function get(string $key): ?string
            {
                return null;
            }

            public function set(string $key, string $value): void
            {
            }

            public function delete(string $key): void
            {
            }

            public function listKeys(): array
            {
                return [];
            }
        };
    }

    /**
     * @param array<string, mixed> $envelope the lifted mageos-workflow-export/1 envelope
     */
    private function assertDryRunSmoke(string $code, array $envelope): void
    {
        $definitionJson = (string) json_encode($envelope['definition']);
        $conditionsSerialized = is_string($envelope['conditions_serialized'] ?? null)
            ? $envelope['conditions_serialized']
            : null;
        $entityType = (string) $envelope['entity_type'];

        $actionCodes = [];
        foreach ($envelope['definition']['steps'] ?? [] as $step) {
            if (($step['type'] ?? null) === 'action') {
                $actionCodes[] = (string) $step['action'];
            }
        }
        $actions = [];
        foreach (array_unique($actionCodes) as $actionCode) {
            $actions[$actionCode] = new StubSimulateableAction($actionCode);
        }
        $pool = new ActionPool($actions);

        $triggerPayload = [
            'entity_id' => 1,
            'store_id' => 0,
            'customer_email' => 'shopper@example.test',
            'grand_total' => '150.00',
            'increment_id' => '100000001',
            'sku' => 'TEST-SKU',
            'rating' => 5,
            'to_status' => 'processing',
        ];

        $validator = new WorkflowValidator([
            new StructuralCheck(),
            new GraphCheck(),
            new ActionCodesCheck($pool),
            new ConditionsShapeCheck(),
        ]);
        $conditionEvaluator = new StubConditionEvaluator();
        $hydrationProvider = new StubHydrationProvider([]);
        $walker = new Walker(
            $conditionEvaluator,
            $hydrationProvider,
            new VariableResolver(new SecretsProviderStub()),
            $pool,
            new DelayCalculator(),
            new StubScopeConfig(['general/locale/timezone' => 'UTC'])
        );
        $dryRunService = new DryRunService(
            $validator,
            $conditionEvaluator,
            $hydrationProvider,
            $walker,
            new StubSimulationContextFactory(),
            new FanOutTracePreview(
                new RelationPool([]),
                new RelationContext(new RelationPool([]), new StubStoreManager(), new StubScopeConfig([]), new NullLogger()),
                $hydrationProvider,
                new DataObjectFactory(),
                new StubScopeConfig([])
            )
        );

        $trace = $dryRunService->run(new DryRunRequest(
            $definitionJson,
            $conditionsSerialized,
            $entityType,
            null,
            $triggerPayload,
            1,
            $code
        ));

        $this->assertFalse(
            $trace->hasErrors(),
            sprintf('%s: dry-run smoke must not error: %s', $code, implode(' ', array_map(
                static fn ($m) => $m->getMessage(),
                $trace->getValidation()
            )))
        );
    }
}
