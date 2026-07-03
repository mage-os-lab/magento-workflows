<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Variable;

use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Model\Variable\VariableResolver;
use MageOS\Workflows\Test\Unit\Stub\SecretsProviderStub;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use PHPUnit\Framework\TestCase;

class VariableResolverTest extends TestCase
{
    private function makeContext(
        array $trigger = [],
        array $steps = [],
        array $workflow = []
    ): ExecutionContext {
        return new ExecutionContext(new WorkflowExecutionStub(), $trigger, $steps, $workflow);
    }

    public function testTriggerPathInterpolation(): void
    {
        $resolver = new VariableResolver(new SecretsProviderStub());
        $ctx = $this->makeContext(['grand_total' => '650.00']);

        $result = $resolver->resolve('High-value order flagged ({{ trigger.grand_total }})', $ctx);

        $this->assertSame('High-value order flagged (650.00)', $result);
    }

    public function testStepsNestedPathInterpolation(): void
    {
        $resolver = new VariableResolver(new SecretsProviderStub());
        $ctx = $this->makeContext([], ['fraud' => ['response' => ['score' => 42]]]);

        $result = $resolver->resolve('score={{ steps.fraud.response.score }}', $ctx);

        $this->assertSame('score=42', $result);
    }

    public function testUnknownRootResolvesToEmptyString(): void
    {
        $resolver = new VariableResolver(new SecretsProviderStub());
        $ctx = $this->makeContext();

        $result = $resolver->resolve('[{{ nonsense.path }}]', $ctx);

        $this->assertSame('[]', $result);
    }

    public function testDisallowedRootReturnsEmptyViaResolvePath(): void
    {
        $resolver = new VariableResolver(new SecretsProviderStub());
        $ctx = $this->makeContext();

        $this->assertNull($resolver->resolvePath('system.x', $ctx));
        $this->assertSame('', $resolver->resolve('{{ system.x }}', $ctx));
    }

    public function testSecretsResolutionViaStub(): void
    {
        $secrets = new SecretsProviderStub(['fraud_hmac' => 'super-secret-value']);
        $resolver = new VariableResolver($secrets);
        $ctx = $this->makeContext();

        $result = $resolver->resolve('{{ secrets.fraud_hmac }}', $ctx);

        $this->assertSame('super-secret-value', $result);
    }

    public function testUnknownSecretResolvesToEmptyString(): void
    {
        $resolver = new VariableResolver(new SecretsProviderStub());
        $ctx = $this->makeContext();

        $result = $resolver->resolve('{{ secrets.missing_key }}', $ctx);

        $this->assertSame('', $result);
    }

    public function testArraysRenderEmpty(): void
    {
        $resolver = new VariableResolver(new SecretsProviderStub());
        $ctx = $this->makeContext([], ['fraud' => ['response' => ['tags' => ['a', 'b']]]]);

        $result = $resolver->resolve('[{{ steps.fraud.response.tags }}]', $ctx);

        $this->assertSame('[]', $result);
    }

    public function testBoolRendering(): void
    {
        $resolver = new VariableResolver(new SecretsProviderStub());
        $ctxTrue = $this->makeContext([], ['flag' => ['value' => true]]);
        $ctxFalse = $this->makeContext([], ['flag' => ['value' => false]]);

        $this->assertSame('1', $resolver->resolve('{{ steps.flag.value }}', $ctxTrue));
        $this->assertSame('', $resolver->resolve('{{ steps.flag.value }}', $ctxFalse));
    }

    public function testResolveConfigRecursesValuesButNeverKeys(): void
    {
        $resolver = new VariableResolver(new SecretsProviderStub());
        $ctx = $this->makeContext(['grand_total' => '650.00']);

        $config = [
            'comment' => 'Total: {{ trigger.grand_total }}',
            '{{ trigger.grand_total }}' => 'literal-key-untouched',
            'nested' => [
                'url' => 'https://example.test/{{ trigger.grand_total }}',
            ],
        ];

        $resolved = $resolver->resolveConfig($config, $ctx);

        $this->assertSame('Total: 650.00', $resolved['comment']);
        $this->assertArrayHasKey('{{ trigger.grand_total }}', $resolved);
        $this->assertSame('literal-key-untouched', $resolved['{{ trigger.grand_total }}']);
        $this->assertSame('https://example.test/650.00', $resolved['nested']['url']);
    }
}
