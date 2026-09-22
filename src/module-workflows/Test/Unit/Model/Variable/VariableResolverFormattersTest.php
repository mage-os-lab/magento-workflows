<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Variable;

use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Model\Variable\VariableResolver;
use MageOS\Workflows\Test\Unit\Stub\SecretsProviderStub;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use PHPUnit\Framework\TestCase;

class VariableResolverFormattersTest extends TestCase
{
    private VariableResolver $resolver;

    public function setUp(): void
    {
        $this->resolver = new VariableResolver(new SecretsProviderStub());
    }

    private function makeContext(
        array $trigger = [],
        array $steps = [],
        array $workflow = []
    ): ExecutionContext {
        return new ExecutionContext(new WorkflowExecutionStub(), $trigger, $steps, $workflow);
    }

    public function testUpperFilter(): void
    {
        $ctx = $this->makeContext(['email' => 'Test@Example.com']);

        $result = $this->resolver->resolve('{{ trigger.email|upper }}', $ctx);

        $this->assertSame('TEST@EXAMPLE.COM', $result);
    }

    public function testLowerFilter(): void
    {
        $ctx = $this->makeContext(['email' => 'Test@Example.com']);

        $result = $this->resolver->resolve('{{ trigger.email|lower }}', $ctx);

        $this->assertSame('test@example.com', $result);
    }

    public function testTrimFilter(): void
    {
        $ctx = $this->makeContext(['message' => '  hello world  ']);

        $result = $this->resolver->resolve('{{ trigger.message|trim }}', $ctx);

        $this->assertSame('hello world', $result);
    }

    public function testNumberFilterWithExplicitDecimals(): void
    {
        $ctx = $this->makeContext(['total' => '1234.5']);

        $result = $this->resolver->resolve('{{ trigger.total|number:\'2\' }}', $ctx);

        $this->assertSame('1,234.50', $result);
    }

    public function testNumberFilterDefaultsToTwoDecimals(): void
    {
        $ctx = $this->makeContext(['total' => '1234.567']);

        $result = $this->resolver->resolve('{{ trigger.total|number }}', $ctx);

        $this->assertSame('1,234.57', $result);
    }

    public function testNumberFilterOnNonNumericPasses(): void
    {
        $ctx = $this->makeContext(['value' => 'not-a-number']);

        $result = $this->resolver->resolve('{{ trigger.value|number }}', $ctx);

        $this->assertSame('not-a-number', $result);
    }

    public function testNumberFilterWithZeroDecimals(): void
    {
        $ctx = $this->makeContext(['total' => '1234.567']);

        $result = $this->resolver->resolve('{{ trigger.total|number:\'0\' }}', $ctx);

        $this->assertSame('1,235', $result);
    }

    public function testNumberFilterWithHighDecimals(): void
    {
        $ctx = $this->makeContext(['total' => '12.3456789']);

        $result = $this->resolver->resolve('{{ trigger.total|number:\'5\' }}', $ctx);

        $this->assertSame('12.34568', $result);
    }

    public function testDateFilterWithCustomFormat(): void
    {
        $ctx = $this->makeContext(['created_at' => '2026-07-01 14:30:00']);

        $result = $this->resolver->resolve('{{ trigger.created_at|date:\'Y-m-d\' }}', $ctx);

        $this->assertSame('2026-07-01', $result);
    }

    public function testDateFilterDefaultFormat(): void
    {
        $ctx = $this->makeContext(['created_at' => '2026-07-01 14:30:00']);

        $result = $this->resolver->resolve('{{ trigger.created_at|date }}', $ctx);

        $this->assertSame('2026-07-01 14:30:00', $result);
    }

    public function testDateFilterWithInvalidDatePasses(): void
    {
        $ctx = $this->makeContext(['created_at' => 'not-a-date']);

        $result = $this->resolver->resolve('{{ trigger.created_at|date:\'Y-m-d\' }}', $ctx);

        $this->assertSame('not-a-date', $result);
    }

    public function testDateFilterWithEmptyStringPasses(): void
    {
        $ctx = $this->makeContext(['created_at' => '']);

        $result = $this->resolver->resolve('{{ trigger.created_at|date:\'Y-m-d\' }}', $ctx);

        $this->assertSame('', $result);
    }

    public function testDefaultFilterOnEmptyString(): void
    {
        $ctx = $this->makeContext(['coupon' => '']);

        $result = $this->resolver->resolve('{{ trigger.coupon|default:\'none\' }}', $ctx);

        $this->assertSame('none', $result);
    }

    public function testDefaultFilterOnNonEmptyValue(): void
    {
        $ctx = $this->makeContext(['coupon' => 'SAVE20']);

        $result = $this->resolver->resolve('{{ trigger.coupon|default:\'none\' }}', $ctx);

        $this->assertSame('SAVE20', $result);
    }

    public function testDefaultFilterWithEmptyDefaultArg(): void
    {
        $ctx = $this->makeContext(['value' => '']);

        $result = $this->resolver->resolve('{{ trigger.value|default:\'\' }}', $ctx);

        $this->assertSame('', $result);
    }

    public function testChainedFiltersLeftToRight(): void
    {
        $ctx = $this->makeContext(['email' => '  Test@Example.com  ']);

        $result = $this->resolver->resolve('{{ trigger.email|trim|upper }}', $ctx);

        $this->assertSame('TEST@EXAMPLE.COM', $result);
    }

    public function testChainedThreeFilters(): void
    {
        $ctx = $this->makeContext(['email' => '  TEST@EXAMPLE.COM  ']);

        $result = $this->resolver->resolve('{{ trigger.email|trim|lower|upper }}', $ctx);

        $this->assertSame('TEST@EXAMPLE.COM', $result);
    }

    public function testUnknownFilterIgnored(): void
    {
        $ctx = $this->makeContext(['email' => 'test@example.com']);

        $result = $this->resolver->resolve('{{ trigger.email|bogus }}', $ctx);

        $this->assertSame('test@example.com', $result);
    }

    public function testUnknownFilterInChainIgnoredButOthersApply(): void
    {
        $ctx = $this->makeContext(['email' => 'test@example.com']);

        $result = $this->resolver->resolve('{{ trigger.email|upper|bogus|lower }}', $ctx);

        $this->assertSame('test@example.com', $result);
    }

    public function testNoFilterPlaceholderStillWorks(): void
    {
        $ctx = $this->makeContext(['message' => 'hello world']);

        $result = $this->resolver->resolve('Message: {{ trigger.message }}', $ctx);

        $this->assertSame('Message: hello world', $result);
    }

    public function testMultiplePlaceholdersWithFilters(): void
    {
        $ctx = $this->makeContext([
            'customer_name' => 'john doe',
            'grand_total' => '1500.75',
        ]);

        $result = $this->resolver->resolve(
            'Customer: {{ trigger.customer_name|upper }}, Total: {{ trigger.grand_total|number:\'2\' }}',
            $ctx
        );

        $this->assertSame('Customer: JOHN DOE, Total: 1,500.75', $result);
    }

    public function testFilterWithPathInNestedSteps(): void
    {
        $ctx = $this->makeContext([], ['fraud' => ['response' => ['comment' => '  test comment  ']]]);

        $result = $this->resolver->resolve('{{ steps.fraud.response.comment|trim|upper }}', $ctx);

        $this->assertSame('TEST COMMENT', $result);
    }

    public function testNumberFilterIgnoresNonDigitArg(): void
    {
        $ctx = $this->makeContext(['total' => '1234.567']);

        // Non-digit arg should be treated as default (2)
        $result = $this->resolver->resolve('{{ trigger.total|number:\'abc\' }}', $ctx);

        $this->assertSame('1,234.57', $result);
    }

    public function testDefaultFilterOnMissingPathResolvesToEmptyThenDefault(): void
    {
        $ctx = $this->makeContext(['coupon' => 'REAL20']);

        $result = $this->resolver->resolve('{{ trigger.missing_field|default:\'fallback\' }}', $ctx);

        $this->assertSame('fallback', $result);
    }

    public function testNumberFilterWithNegativeValue(): void
    {
        $ctx = $this->makeContext(['amount' => '-1234.567']);

        $result = $this->resolver->resolve('{{ trigger.amount|number:\'2\' }}', $ctx);

        $this->assertSame('-1,234.57', $result);
    }

    public function testDateFilterWith12HourFormat(): void
    {
        $ctx = $this->makeContext(['created_at' => '2026-07-01 14:30:00']);

        $result = $this->resolver->resolve('{{ trigger.created_at|date:\'Y-m-d h:i A\' }}', $ctx);

        $this->assertSame('2026-07-01 02:30 PM', $result);
    }

    public function testTrimFilterWithTabs(): void
    {
        $ctx = $this->makeContext(['message' => "\t\thello\t\t"]);

        $result = $this->resolver->resolve('{{ trigger.message|trim }}', $ctx);

        $this->assertSame('hello', $result);
    }

    public function testTrimFilterWithNewlines(): void
    {
        $ctx = $this->makeContext(['message' => "\n\nhello\n\n"]);

        $result = $this->resolver->resolve('{{ trigger.message|trim }}', $ctx);

        $this->assertSame('hello', $result);
    }

    public function testUpperFilterWithUnicodeCharacters(): void
    {
        $ctx = $this->makeContext(['text' => 'café']);

        $result = $this->resolver->resolve('{{ trigger.text|upper }}', $ctx);

        $this->assertSame('CAFÉ', $result);
    }

    public function testLowerFilterWithUnicodeCharacters(): void
    {
        $ctx = $this->makeContext(['text' => 'CAFÉ']);

        $result = $this->resolver->resolve('{{ trigger.text|lower }}', $ctx);

        $this->assertSame('café', $result);
    }

    public function testFilterChainWithDefault(): void
    {
        $ctx = $this->makeContext(['name' => '']);

        $result = $this->resolver->resolve('{{ trigger.name|upper|default:\'GUEST\' }}', $ctx);

        $this->assertSame('GUEST', $result);
    }

    public function testNumberFilterWithInteger(): void
    {
        $ctx = $this->makeContext(['count' => '42']);

        $result = $this->resolver->resolve('{{ trigger.count|number:\'0\' }}', $ctx);

        $this->assertSame('42', $result);
    }

    public function testDateFilterWithTimestamp(): void
    {
        $ctx = $this->makeContext(['timestamp' => '@1719829800']);

        $result = $this->resolver->resolve('{{ trigger.timestamp|date:\'Y-m-d\' }}', $ctx);

        // Use gmdate expectation since the code uses gmdate
        $this->assertSame('2024-07-01', $result);
    }

    public function testFilterSpaceToleranceInChain(): void
    {
        $ctx = $this->makeContext(['email' => 'Test@Example.com']);

        $result = $this->resolver->resolve('{{ trigger.email | upper | lower }}', $ctx);

        $this->assertSame('test@example.com', $result);
    }

    public function testDefaultFilterOnZeroStringValue(): void
    {
        $ctx = $this->makeContext(['value' => '0']);

        $result = $this->resolver->resolve('{{ trigger.value|default:\'not-set\' }}', $ctx);

        $this->assertSame('0', $result);
    }

    public function testDefaultFilterOnFalseValue(): void
    {
        $ctx = $this->makeContext([], ['flag' => ['value' => false]]);

        // False renders to empty string in resolve() before filters are applied
        $result = $this->resolver->resolve('{{ steps.flag.value|default:\'not-set\' }}', $ctx);

        $this->assertSame('not-set', $result);
    }

    public function testNumberFilterOnZeroValue(): void
    {
        $ctx = $this->makeContext(['amount' => '0']);

        $result = $this->resolver->resolve('{{ trigger.amount|number:\'2\' }}', $ctx);

        $this->assertSame('0.00', $result);
    }

    public function testComplexFormatterChainWithDefaultAndNumber(): void
    {
        $ctx = $this->makeContext(['price' => '99.999']);

        $result = $this->resolver->resolve('{{ trigger.price|number:\'1\'|default:\'0.0\' }}', $ctx);

        $this->assertSame('100.0', $result);
    }
}
