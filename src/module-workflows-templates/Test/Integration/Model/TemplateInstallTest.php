<?php
declare(strict_types=1);

namespace MageOS\WorkflowsTemplates\Test\Integration\Model;

use Magento\Backend\Model\Auth;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use Magento\TestFramework\Bootstrap as TestBootstrap;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Console\Command\TemplateInstallCommand;
use MageOS\Workflows\Model\Template\BundledTemplateSource;
use MageOS\Workflows\Model\Template\CompatibilityChecker;
use MageOS\Workflows\Model\Template\InstallProvenance;
use MageOS\Workflows\Model\Template\TemplateInstallRequest;
use MageOS\Workflows\Model\Template\TemplateInstaller;
use MageOS\Workflows\Model\Template\TemplateNotFoundException;
use MageOS\Workflows\Model\Template\TemplateSourceInterface;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Variable\SecretsProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Plan #28 (docs/20-integration-test-plan.md §7): every bundled template
 * installs against the real DB via the real install path
 * (TemplateInstaller — the same service TemplateInstallCommand and the admin
 * gallery both call). Every installed workflow is saved through
 * WorkflowRepositoryInterface, so it also transits ValidateWorkflowOnSave —
 * a template drifting from the live validator fails loudly here.
 *
 * DIVERGENCE from the plan text ("all 14 bundled templates install"): the
 * pack ships 14 templates, but "b2b-net-terms-payment-reminder" declares
 * requires.edition=b2b (CompatibilityChecker::checkEdition). A Mage-OS /
 * Magento Open Source CI install reports ProductMetadataInterface::getEdition()
 * as "Community", which is NOT commerce, so the real CompatibilityChecker
 * reports it incompatible on exactly the platform this suite runs against.
 * That is correct, by-design gating, not a bug — this test pins 13/14
 * installing and the 14th being cleanly refused, rather than asserting all
 * 14 install (see testEditionGatedTemplateIsIncompatibleOnThisInstall).
 *
 * Re-install: InstallProvenance's own docblock and
 * docs/discovery/implementation/06-template-gallery.md §Provenance are
 * explicit that this is "fork-on-install; no upgrade path — documented".
 * The plan prose's "re-install is idempotent (no duplicate workflows)" is
 * the stale side of that disagreement; this test pins the documented
 * fork-on-install contract (testReinstallForksANewWorkflowRatherThanUpdating).
 *
 * @magentoDbIsolation enabled
 */
class TemplateInstallTest extends TestCase
{
    /**
     * Every template code EXCEPT the edition-gated one, with representative
     * values for parameters the pack declares `required` with no default
     * (mirrors module-workflows-templates/Test/Unit/Templates/SeedPackFixtureTest
     * REQUIRED_PARAM_VALUES — the same fixtures, install-tested for real here).
     *
     * @var array<string, array<string, string>>
     */
    private const INSTALLABLE_TEMPLATE_PARAMS = [
        'abandoned-cart-recovery-coupon' => ['coupon_rule_id' => '7'],
        'gdpr-anonymize-on-request' => [],
        'guest-order-registration-invite' => [],
        'high-value-order-fraud-hold' => [
            'fraud_webhook_url' => 'https://fraud.example.test/score',
            'fraud_signing_secret' => 'fraud_hmac_key',
        ],
        'new-customer-welcome-series' => ['incentive_rule_id' => '3'],
        'order-stuck-in-processing-escalation' => [],
        'post-purchase-review-request' => [],
        'product-review-triage' => ['reward_rule_id' => '9'],
        'refund-follow-up' => [],
        'stock-threshold-supplier-webhook' => [
            'supplier_webhook_url' => 'https://supplier.example.test/reorder',
            'supplier_signing_secret' => 'supplier_hmac_key',
        ],
        'unpaid-order-cleanup-sweep' => [],
        'vip-auto-group-assignment' => ['vip_group_id' => '5'],
        'vip-order-notification' => ['vip_group_id' => '42'],
    ];

    private const EDITION_GATED_TEMPLATE = 'b2b-net-terms-payment-reminder';

    private TemplateSourceInterface $templateSource;
    private TemplateInstaller $templateInstaller;
    private CompatibilityChecker $compatibilityChecker;
    private WorkflowRepositoryInterface $workflowRepository;
    private InstallProvenance $provenance;
    private ResourceConnection $resourceConnection;
    private SecretsProviderInterface $secretsProvider;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->templateSource = $objectManager->get(TemplateSourceInterface::class);
        $this->templateInstaller = $objectManager->get(TemplateInstaller::class);
        $this->compatibilityChecker = $objectManager->get(CompatibilityChecker::class);
        $this->workflowRepository = $objectManager->get(WorkflowRepositoryInterface::class);
        $this->provenance = $objectManager->get(InstallProvenance::class);
        $this->resourceConnection = $objectManager->get(ResourceConnection::class);
        $this->secretsProvider = $objectManager->get(SecretsProviderInterface::class);

        // Pre-seed the two secrets templates reference by key name (secret-typed
        // parameters carry the KEY, never the value; the value must already
        // exist server-side per the CLI/gallery contract).
        $this->secretsProvider->set('fraud_hmac_key', 'test-fraud-secret');
        $this->secretsProvider->set('supplier_hmac_key', 'test-supplier-secret');
    }

    protected function tearDown(): void
    {
        // Guarded logout so an admin authenticated by a MODE_ADMIN_CONTEXT test
        // does not leak into a sibling test sharing the same app instance.
        $auth = Bootstrap::getObjectManager()->get(Auth::class);
        if ($auth->isLoggedIn()) {
            $auth->logout();
        }
    }

    /**
     * Authenticate the default full-permission integration admin (role
     * "Administrators", all ACL) so ActionAuthorizationCheck — which runs in
     * MODE_ADMIN_CONTEXT (the admin-gallery install path this test drives) —
     * authorizes every per-action ACL resource the bundled templates require.
     * Without an authenticated principal the resolver grants an empty role and
     * per-action authoring is denied (docs/09).
     */
    private function loginFullAdmin(): void
    {
        $auth = Bootstrap::getObjectManager()->get(Auth::class);
        $auth->login(TestBootstrap::ADMIN_NAME, TestBootstrap::ADMIN_PASSWORD);
    }

    /**
     * The bundled pack ships exactly the 14 templates the discovery doc and
     * README both cite; catches an accidental addition/removal early.
     */
    public function testBundledSourceListsFourteenTemplates(): void
    {
        $summaries = $this->templateSource->list();
        $codes = array_map(static fn ($s) => $s->getCode(), $summaries);
        sort($codes);

        $expected = array_merge(array_keys(self::INSTALLABLE_TEMPLATE_PARAMS), [self::EDITION_GATED_TEMPLATE]);
        sort($expected);

        $this->assertSame($expected, $codes);
    }

    /**
     * Every installable template: real install path, saved workflow passes
     * live validation (it went through the repository), created disabled, and
     * a mageos_workflow_template_install row is written.
     *
     * Drives the admin-gallery install path (MODE_ADMIN_CONTEXT), so it runs in
     * the adminhtml area with a full-permission admin authenticated — the
     * per-action ACL re-authorization (ActionAuthorizationCheck) must pass.
     *
     * @magentoAppArea adminhtml
     * @magentoAppIsolation enabled
     */
    public function testEveryInstallableTemplateInstallsAndValidatesLive(): void
    {
        $this->loginFullAdmin();

        foreach (self::INSTALLABLE_TEMPLATE_PARAMS as $code => $params) {
            $result = $this->templateInstaller->install(new TemplateInstallRequest(
                $code,
                $params,
                WorkflowInterface::STATUS_DISABLED,
                ValidationContext::MODE_ADMIN_CONTEXT,
                'integration-test'
            ));

            $workflowId = (int) $result->getWorkflow()->getWorkflowId();
            $this->assertGreaterThan(0, $workflowId, "$code: install must persist a workflow id");

            // Re-load through the repository: proves the row is really there
            // and passed WorkflowRepository's own read path (not just the
            // in-memory result object from install()).
            $loaded = $this->workflowRepository->getById($workflowId);
            $this->assertSame(WorkflowInterface::STATUS_DISABLED, $loaded->getStatus(), "$code: installs disabled by default");
            $definition = json_decode($loaded->getDefinition(), true);
            $this->assertIsArray($definition, "$code: stored definition must decode");
            $encoded = (string) json_encode($definition);
            $this->assertStringNotContainsString('%param.', $encoded, "$code: no leftover template tokens persisted");

            // Provenance row.
            $row = $this->provenance->getByWorkflowId($workflowId);
            $this->assertIsArray($row, "$code: provenance row must exist");
            $this->assertSame($code, $row['template_code']);
            $this->assertSame($result->getTemplateVersion(), $row['template_version']);
            $this->assertSame('integration-test', $row['installed_by']);

            // Parameters snapshot never carries secret values, only key names.
            $paramSnapshot = json_decode((string) $row['parameters'], true);
            foreach ($paramSnapshot as $value) {
                $this->assertStringNotContainsString('test-fraud-secret', (string) $value);
                $this->assertStringNotContainsString('test-supplier-secret', (string) $value);
            }
        }
    }

    /**
     * The edition-gated template is refused cleanly (no partial writes) on a
     * Community/Mage-OS install rather than silently installing —
     * CompatibilityChecker is the real gate, not a doc-only claim.
     */
    public function testEditionGatedTemplateIsIncompatibleOnThisInstall(): void
    {
        $summaries = $this->templateSource->list();
        $summary = null;
        foreach ($summaries as $candidate) {
            if ($candidate->getCode() === self::EDITION_GATED_TEMPLATE) {
                $summary = $candidate;
                break;
            }
        }
        $this->assertNotNull($summary, 'b2b-net-terms-payment-reminder must be present in the bundled pack');

        $compat = $this->compatibilityChecker->check($summary);
        $this->assertFalse(
            $compat->isCompatible(),
            'Expected requires.edition=b2b to be incompatible on a Community/Mage-OS install'
        );

        $this->expectException(LocalizedException::class);
        $this->templateInstaller->install(new TemplateInstallRequest(self::EDITION_GATED_TEMPLATE));
    }

    /**
     * Installing the same template twice creates two independent workflows
     * and two provenance rows (fork-on-install; no update-in-place / upgrade
     * path — see class docblock for the plan-vs-implementation note).
     *
     * Drives the admin-gallery install path (MODE_ADMIN_CONTEXT default), so it
     * runs in the adminhtml area with a full-permission admin authenticated so
     * the per-action ACL re-authorization passes.
     *
     * @magentoAppArea adminhtml
     * @magentoAppIsolation enabled
     */
    public function testReinstallForksANewWorkflowRatherThanUpdating(): void
    {
        $this->loginFullAdmin();

        $request = new TemplateInstallRequest('vip-order-notification', ['vip_group_id' => '5']);

        $first = $this->templateInstaller->install($request);
        $second = $this->templateInstaller->install($request);

        $firstId = (int) $first->getWorkflow()->getWorkflowId();
        $secondId = (int) $second->getWorkflow()->getWorkflowId();

        $this->assertNotSame($firstId, $secondId, 'Each install() call must create its own workflow row');
        $this->assertNotSame(
            $first->getProvenanceId(),
            $second->getProvenanceId(),
            'Each install() call must write its own provenance row'
        );

        // Both rows independently readable — neither install clobbered the other.
        $this->assertNotNull($this->workflowRepository->getById($firstId)->getWorkflowId());
        $this->assertNotNull($this->workflowRepository->getById($secondId)->getWorkflowId());
    }

    /**
     * An unknown template code is rejected before any write (no workflow,
     * no provenance row created for a bad code).
     */
    public function testUnknownTemplateCodeIsRejectedCleanly(): void
    {
        $countBefore = $this->countWorkflows();

        $this->expectException(TemplateNotFoundException::class);
        try {
            $this->templateInstaller->install(new TemplateInstallRequest('does-not-exist'));
        } finally {
            $this->assertSame($countBefore, $this->countWorkflows(), 'A rejected install must not write a workflow row');
        }
    }

    /**
     * A template requiring an action code this install does not have
     * (module-workflows-actions-core ships the standard codes; a fixture pack
     * declares a made-up one) is refused by CompatibilityChecker before any
     * write — the "uninstalled-module actions ... rejected cleanly" pin from
     * the plan, exercised via a fixture pack registered directly on
     * BundledTemplateSource (mirrors SeedPackFixtureTest's fake ModuleDirReader
     * technique, against the REAL merged ActionPool/TriggerRegistry/di.xml).
     */
    public function testTemplateRequiringAnUnavailableActionIsRejectedCleanly(): void
    {
        $countBefore = $this->countWorkflows();

        $fixtureSource = $this->fixturePackSource();
        $summary = null;
        foreach ($fixtureSource->list() as $candidate) {
            if ($candidate->getCode() === 'missing-action-template') {
                $summary = $candidate;
            }
        }
        $this->assertNotNull($summary, 'Fixture pack must expose missing-action-template');

        $compat = $this->compatibilityChecker->check($summary);
        $this->assertFalse($compat->isCompatible(), 'A template requiring an unregistered action must be incompatible');

        $objectManager = Bootstrap::getObjectManager();
        $installer = $objectManager->create(TemplateInstaller::class, ['templateSource' => $fixtureSource]);

        try {
            $installer->install(new TemplateInstallRequest('missing-action-template'));
            $this->fail('Expected install() to refuse a template requiring an unavailable action');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('cannot be installed', $e->getMessage());
        }

        $this->assertSame($countBefore, $this->countWorkflows(), 'A rejected install must not write a workflow row');
    }

    /**
     * workflow:template:install (CLI, F3 SYSTEM mode): installs disabled by
     * default, prints the system-privileges warning, and the workflow is
     * really persisted.
     */
    public function testTemplateInstallCommandCreatesADisabledWorkflowByDefault(): void
    {
        $command = Bootstrap::getObjectManager()->get(TemplateInstallCommand::class);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'code' => 'unpaid-order-cleanup-sweep',
        ]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('does NOT', $display, 'CLI must print the system-privileges warning');
        $this->assertStringContainsString('unpaid-order-cleanup-sweep', $display);
        $this->assertStringContainsString('(disabled)', $display);
    }

    /**
     * --activate and --shadow are mutually exclusive; the command fails
     * before calling the installer at all (no workflow written).
     */
    public function testTemplateInstallCommandRejectsActivateAndShadowTogether(): void
    {
        $countBefore = $this->countWorkflows();
        $command = Bootstrap::getObjectManager()->get(TemplateInstallCommand::class);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'code' => 'refund-follow-up',
            '--activate' => true,
            '--shadow' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('mutually exclusive', $tester->getDisplay());
        $this->assertSame($countBefore, $this->countWorkflows());
    }

    /**
     * --params-file (base) merges with repeated --param (override) exactly
     * as ImportCommand-adjacent CLI parsing promises.
     */
    public function testTemplateInstallCommandMergesParamsFileWithRepeatedParamOverrides(): void
    {
        $paramsFile = sys_get_temp_dir() . '/mageos_workflow_template_params_' . uniqid('', true) . '.json';
        file_put_contents($paramsFile, json_encode(['vip_group_id' => '1']));

        try {
            $command = Bootstrap::getObjectManager()->get(TemplateInstallCommand::class);
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([
                'code' => 'vip-order-notification',
                '--params-file' => $paramsFile,
                '--param' => ['vip_group_id=9'],
                '--activate' => true,
            ]);

            $this->assertSame(0, $exitCode, $tester->getDisplay());
            $this->assertStringContainsString('(enabled)', $tester->getDisplay());
        } finally {
            @unlink($paramsFile);
        }
    }

    private function countWorkflows(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mageos_workflow');
        return (int) $connection->fetchOne($connection->select()->from($table, 'COUNT(*)'));
    }

    /**
     * A tiny second "pack" pointed at this test's own _files fixture dir,
     * registered directly on a fresh BundledTemplateSource instance (not the
     * production DI-wired one) so production template files are never
     * touched, while still using the REAL BundledTemplateSource class and the
     * real merged ActionPool/TriggerRegistry via CompatibilityChecker.
     */
    private function fixturePackSource(): BundledTemplateSource
    {
        $base = __DIR__ . '/../_files/pack';
        $reader = new class ($base) extends ModuleDirReader {
            public function __construct(private readonly string $base)
            {
            }

            public function getModuleDir($type, $moduleName)
            {
                return $moduleName === 'FixtureTemplatePack' ? $this->base : '';
            }
        };

        return new BundledTemplateSource($reader, ['fixture' => 'FixtureTemplatePack']);
    }
}
