<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\Variable;

use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Model\Secrets\ConfigSecretsProvider;
use MageOS\Workflows\Model\Variable\SecretsProviderInterface;
use MageOS\Workflows\Model\Variable\VariableResolver;
use MageOS\Workflows\Model\WorkflowExecution;
use PHPUnit\Framework\TestCase;

/**
 * Plan #16 (docs/20-integration-test-plan.md §5) — the restricted
 * mustache-style variable resolver (docs/07 §Variable resolution) exercised
 * end-to-end against a real execution context, and the secret redaction seam
 * (RedactingSecretsProvider via the VariableResolverForDryRun virtualType):
 * secrets resolve to their real value through the production resolver (the
 * outbound payload) but to ***key*** through the redacting resolver used for
 * persisted traces/logs — so a real secret value never lands in a step result.
 *
 * @magentoDbIsolation enabled
 */
class ResolverTest extends TestCase
{
    /**
     * Virtual type name (di.xml): VariableResolver whose secretsProvider is
     * the RedactingSecretsProvider decorator.
     */
    private const DRY_RUN_RESOLVER = 'MageOS\\Workflows\\Model\\Variable\\VariableResolverForDryRun';

    private VariableResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = Bootstrap::getObjectManager()->get(VariableResolver::class);
    }

    public function testScalarNestedAndStepTokensResolve(): void
    {
        $ctx = $this->context(
            trigger: [
                'customer_email' => 'Shopper@Example.COM',
                'grand_total' => '199.5',
                'customer' => ['email' => 'nested@example.com'],
            ],
            steps: ['fraud' => ['response' => ['score' => 88]]],
            workflow: ['name' => 'My Flow'],
        );

        $this->assertSame('shopper@example.com', $this->resolver->resolve('{{ trigger.customer_email|lower }}', $ctx));
        $this->assertSame('nested@example.com', $this->resolver->resolve('{{ trigger.customer.email }}', $ctx));
        $this->assertSame('88', $this->resolver->resolve('{{ steps.fraud.response.score }}', $ctx));
        $this->assertSame('My Flow', $this->resolver->resolve('{{ workflow.name }}', $ctx));
    }

    /**
     * Formerly a KNOWN DIVERGENCE: the placeholder grammar only accepted
     * quoted filter arguments, so the documented `|number:2` failed the whole
     * placeholder match and rendered literally. The grammar now accepts
     * unquoted arguments (no spaces/quotes/pipes/braces), so this pin runs in
     * the blocking gate like any other test.
     */
    public function testNumberFilterFormatsUnquotedDecimalsArgument(): void
    {
        $ctx = $this->context(trigger: ['grand_total' => '199.5']);
        $this->assertSame('199.50', $this->resolver->resolve('{{ trigger.grand_total|number:2 }}', $ctx));
    }

    public function testUnknownTokensAndInvalidRootsResolveToEmpty(): void
    {
        $ctx = $this->context(trigger: ['coupon_code' => '']);

        $this->assertSame('', $this->resolver->resolve('{{ trigger.does_not_exist }}', $ctx));
        // Root outside the allowlist (trigger/steps/workflow/secrets) is null => ''
        $this->assertSame('', $this->resolver->resolve('{{ bogusroot.value }}', $ctx));
        // default filter substitutes for an empty/missing value
        $this->assertSame('none', $this->resolver->resolve("{{ trigger.coupon_code|default:'none' }}", $ctx));
    }

    public function testCollectionFiltersOverItems(): void
    {
        $ctx = $this->context(trigger: [
            'items' => [
                ['sku' => 'A', 'qty' => 1],
                ['sku' => 'B', 'qty' => 2],
                ['sku' => 'C', 'qty' => 3],
            ],
        ]);

        $this->assertSame('3', $this->resolver->resolve('{{ trigger.items|count }}', $ctx));
        $this->assertSame('A, B, C', $this->resolver->resolve("{{ trigger.items|pluck:'sku'|join:', ' }}", $ctx));
    }

    public function testResolveConfigRecursesValuesOnly(): void
    {
        $ctx = $this->context(trigger: ['customer_email' => 'a@b.test']);
        $resolved = $this->resolver->resolveConfig(
            [
                'to' => '{{ trigger.customer_email }}',
                'nested' => ['subject' => 'Hi {{ trigger.customer_email|upper }}'],
                // Keys are never interpolated: values, not structure.
                '{{ trigger.customer_email }}' => 'literal-key',
            ],
            $ctx
        );

        $this->assertSame('a@b.test', $resolved['to']);
        $this->assertSame('Hi A@B.TEST', $resolved['nested']['subject']);
        $this->assertArrayHasKey('{{ trigger.customer_email }}', $resolved, 'Config keys must not be interpolated');
    }

    /**
     * Secrets resolve to the real value through the production resolver (bound
     * to ConfigSecretsProvider), so the outbound payload carries the true
     * value.
     */
    public function testSecretResolvesToRealValueInProduction(): void
    {
        /** @var SecretsProviderInterface $secrets */
        $secrets = Bootstrap::getObjectManager()->get(ConfigSecretsProvider::class);
        $secrets->set('api_key', 'SUPER-SECRET-VALUE');

        $ctx = $this->context();
        $this->assertSame(
            'Bearer SUPER-SECRET-VALUE',
            $this->resolver->resolve('Bearer {{ secrets.api_key }}', $ctx)
        );
    }

    /**
     * The redacting resolver (VariableResolverForDryRun virtualType) masks the
     * secret to ***key*** — this is the resolver bound into the dry-run Walker
     * and the Executor's redactingVariableResolver, i.e. the surface that
     * feeds persisted step results / traces / logs. The real value must never
     * reach it.
     */
    public function testSecretIsRedactedThroughTheDryRunResolver(): void
    {
        /** @var SecretsProviderInterface $secrets */
        $secrets = Bootstrap::getObjectManager()->get(ConfigSecretsProvider::class);
        $secrets->set('api_key', 'SUPER-SECRET-VALUE');

        /** @var VariableResolver $redacting */
        $redacting = Bootstrap::getObjectManager()->get(self::DRY_RUN_RESOLVER);
        $rendered = $redacting->resolve('Bearer {{ secrets.api_key }}', $this->context());

        $this->assertStringNotContainsString('SUPER-SECRET-VALUE', $rendered, 'Real secret must never appear in a trace');
        $this->assertSame('Bearer ***api_key***', $rendered);
    }

    /**
     * A missing secret resolves to null => empty string, identical in both
     * resolvers (simulation/production agree on missing-secret behavior).
     */
    public function testMissingSecretResolvesToEmpty(): void
    {
        $ctx = $this->context();
        $this->assertSame('token=', $this->resolver->resolve('token={{ secrets.absent_key }}', $ctx));

        /** @var VariableResolver $redacting */
        $redacting = Bootstrap::getObjectManager()->get(self::DRY_RUN_RESOLVER);
        $this->assertSame('token=', $redacting->resolve('token={{ secrets.absent_key }}', $ctx));
    }

    private function context(array $trigger = [], array $steps = [], array $workflow = []): ExecutionContext
    {
        /** @var WorkflowExecution $execution */
        $execution = Bootstrap::getObjectManager()->create(WorkflowExecution::class);
        $execution->setUuid('55555555-5555-5555-5555-555555555555');
        $execution->setEntityId(1);
        $execution->setStoreId(1);
        return new ExecutionContext($execution, $trigger, $steps, $workflow);
    }
}
