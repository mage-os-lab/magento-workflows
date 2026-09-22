<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Template;

use Magento\Framework\Exception\LocalizedException;
use MageOS\Workflows\Api\OptionSourceInterface;
use MageOS\Workflows\Model\Option\EntityOptionSourceRegistry;
use MageOS\Workflows\Model\Option\OptionSourcePool;
use MageOS\Workflows\Model\Template\ParameterEngine;
use PHPUnit\Framework\TestCase;

/**
 * %param.<key>% substitution: defaults, required/unknown/option enforcement,
 * embedded + whole-value tokens, and the leftover-token hard error — all before
 * any write. Plus the typed checks (number/duration/url/entity:*), their
 * preview bypass, and the skip rules that keep a bare engine usable.
 */
class ParameterEngineTest extends TestCase
{
    private function workflow(): array
    {
        return [
            'name' => 'Reminder for %param.channel%',
            'entity_type' => 'sales_order',
            'trigger_type' => 'event',
            'trigger_ref' => 'sales.order.created',
            'definition' => [
                'schema' => 1,
                'entry' => 's1',
                'steps' => [
                    's1' => [
                        'type' => 'delay',
                        'config' => ['duration' => '%param.wait%'],
                        'next' => 's2',
                    ],
                    's2' => [
                        'type' => 'action',
                        'action' => 'marketing.generate_coupon',
                        'config' => ['rule_id' => '%param.rule_id%'],
                        'next' => null,
                    ],
                ],
            ],
        ];
    }

    private function defs(): array
    {
        return [
            ['key' => 'wait', 'type' => 'duration', 'default' => 'PT4H'],
            ['key' => 'rule_id', 'type' => 'entity:salesrule', 'required' => true],
            ['key' => 'channel', 'type' => 'select', 'default' => 'email',
                'options' => [['value' => 'email', 'label' => 'Email'], ['value' => 'sms', 'label' => 'SMS']]],
        ];
    }

    public function testSubstitutesWholeValueAndEmbeddedTokens(): void
    {
        $out = (new ParameterEngine())->apply($this->workflow(), $this->defs(), [
            'wait' => 'P1D',
            'rule_id' => '7',
            'channel' => 'sms',
        ]);

        $this->assertSame('Reminder for sms', $out['name']);
        $this->assertSame('P1D', $out['definition']['steps']['s1']['config']['duration']);
        $this->assertSame('7', $out['definition']['steps']['s2']['config']['rule_id']);
    }

    public function testDefaultsApplyWhenValueOmitted(): void
    {
        $out = (new ParameterEngine())->apply($this->workflow(), $this->defs(), ['rule_id' => '9']);
        $this->assertSame('PT4H', $out['definition']['steps']['s1']['config']['duration']);
        $this->assertSame('Reminder for email', $out['name']);
    }

    public function testRequiredParameterMissingIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('is required');
        (new ParameterEngine())->apply($this->workflow(), $this->defs(), []);
    }

    public function testUnknownParameterIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Unknown template parameter');
        (new ParameterEngine())->apply($this->workflow(), $this->defs(), [
            'rule_id' => '1',
            'bogus' => 'x',
        ]);
    }

    public function testOptionOutsideDeclaredSetIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('not a valid option');
        (new ParameterEngine())->apply($this->workflow(), $this->defs(), [
            'rule_id' => '1',
            'channel' => 'carrier-pigeon',
        ]);
    }

    public function testLeftoverTokenIsHardError(): void
    {
        // A workflow token with no matching parameter definition.
        $workflow = ['name' => 'x', 'config' => ['v' => '%param.undeclared%']];
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Unresolved template token');
        (new ParameterEngine())->apply($workflow, [], []);
    }

    /**
     * Single-parameter body, so a typed check is the only thing under test.
     */
    private function oneParamWorkflow(string $key): array
    {
        return ['name' => 'x', 'config' => ['v' => '%param.' . $key . '%']];
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function oneParamDefs(string $key, string $type, array $extra = []): array
    {
        return [array_merge(['key' => $key, 'type' => $type], $extra)];
    }

    public function testNumberAcceptsNumericValue(): void
    {
        $out = (new ParameterEngine())->apply(
            $this->oneParamWorkflow('limit'),
            $this->oneParamDefs('limit', 'number'),
            ['limit' => '42']
        );
        $this->assertSame('42', $out['config']['v']);
    }

    public function testNumberRejectsNonNumericValue(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be a number');
        (new ParameterEngine())->apply(
            $this->oneParamWorkflow('limit'),
            $this->oneParamDefs('limit', 'number'),
            ['limit' => 'twelve']
        );
    }

    public function testNumberBelowDeclaredMinimumIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be at least');
        (new ParameterEngine())->apply(
            $this->oneParamWorkflow('limit'),
            $this->oneParamDefs('limit', 'number', ['min' => 5]),
            ['limit' => '4']
        );
    }

    public function testNumberAboveDeclaredMaximumIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be at most');
        (new ParameterEngine())->apply(
            $this->oneParamWorkflow('limit'),
            $this->oneParamDefs('limit', 'number', ['min' => 1, 'max' => 10]),
            ['limit' => '11']
        );
    }

    public function testDurationAcceptsIso8601Intervals(): void
    {
        $engine = new ParameterEngine();
        foreach (['P3D', 'PT4H'] as $duration) {
            $out = $engine->apply(
                $this->oneParamWorkflow('wait'),
                $this->oneParamDefs('wait', 'duration'),
                ['wait' => $duration]
            );
            $this->assertSame($duration, $out['config']['v']);
        }
    }

    public function testDurationRejectsMalformedValue(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('ISO-8601 duration');
        (new ParameterEngine())->apply(
            $this->oneParamWorkflow('wait'),
            $this->oneParamDefs('wait', 'duration'),
            ['wait' => '3 days']
        );
    }

    public function testUrlAcceptsHttpsUrl(): void
    {
        $out = (new ParameterEngine())->apply(
            $this->oneParamWorkflow('endpoint'),
            $this->oneParamDefs('endpoint', 'url'),
            ['endpoint' => 'https://example.com/hook?x=1']
        );
        $this->assertSame('https://example.com/hook?x=1', $out['config']['v']);
    }

    public function testUrlRejectsMissingScheme(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('valid http(s) URL');
        (new ParameterEngine())->apply(
            $this->oneParamWorkflow('endpoint'),
            $this->oneParamDefs('endpoint', 'url'),
            ['endpoint' => 'example.com/x']
        );
    }

    public function testUrlRejectsNonHttpScheme(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('valid http(s) URL');
        (new ParameterEngine())->apply(
            $this->oneParamWorkflow('endpoint'),
            $this->oneParamDefs('endpoint', 'url'),
            ['endpoint' => 'javascript:alert(1)']
        );
    }

    /**
     * Hand-written option source over a fixed catalogue (no mocks).
     *
     * @param string[] $values
     */
    private function source(string $code, array $values): OptionSourceInterface
    {
        return new class ($code, $values) implements OptionSourceInterface {
            /** @param string[] $values */
            public function __construct(private readonly string $code, private readonly array $values)
            {
            }

            public function getCode(): string
            {
                return $this->code;
            }

            public function fetch(?string $query = null): array
            {
                return $this->all();
            }

            public function all(): array
            {
                return array_map(
                    static fn (string $v): array => ['value' => $v, 'label' => 'Rule ' . $v],
                    $this->values
                );
            }

            public function hasValue(string $value): bool
            {
                return in_array($value, $this->values, true);
            }
        };
    }

    /**
     * Engine wired the way di.xml wires it: real pool + real registry.
     *
     * @param array<string, array{source: string, bounded?: bool, min_chars?: int}> $map
     */
    private function wiredEngine(array $map = ['salesrule' => ['source' => 'cart_price_rules']]): ParameterEngine
    {
        return new ParameterEngine(
            new OptionSourcePool(['cart_price_rules' => $this->source('cart_price_rules', ['7', '8'])]),
            new EntityOptionSourceRegistry($map)
        );
    }

    public function testEntityValueMatchingAnExistingRecordPasses(): void
    {
        $out = $this->wiredEngine()->apply($this->workflow(), $this->defs(), ['rule_id' => '7']);
        $this->assertSame('7', $out['definition']['steps']['s2']['config']['rule_id']);
    }

    public function testEntityValueWithNoMatchingRecordIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('does not match any existing record');
        $this->wiredEngine()->apply($this->workflow(), $this->defs(), ['rule_id' => '999']);
    }

    public function testEntityCheckSkippedWhenEngineHasNoPoolOrRegistry(): void
    {
        // Bare construction (CLI/unit path): the type checks still run, the
        // existence check cannot and must not fail.
        $out = (new ParameterEngine())->apply($this->workflow(), $this->defs(), ['rule_id' => '999']);
        $this->assertSame('999', $out['definition']['steps']['s2']['config']['rule_id']);
    }

    public function testEntityCheckSkippedWhenAliasIsUnregistered(): void
    {
        $engine = $this->wiredEngine(['order_status' => ['source' => 'order_statuses', 'bounded' => true]]);
        $out = $engine->apply($this->workflow(), $this->defs(), ['rule_id' => '999']);
        $this->assertSame('999', $out['definition']['steps']['s2']['config']['rule_id']);
    }

    public function testInlineOptionsTakePrecedenceOverTheEntityMapping(): void
    {
        $defs = [
            ['key' => 'rule_id', 'type' => 'entity:salesrule', 'required' => true,
                'options' => [['value' => '999', 'label' => 'Authored']]],
        ];
        $out = $this->wiredEngine()->apply($this->oneParamWorkflow('rule_id'), $defs, ['rule_id' => '999']);
        $this->assertSame('999', $out['config']['v']);
    }

    public function testValidateTypesFalseBypassesTypedChecks(): void
    {
        $out = (new ParameterEngine())->apply(
            $this->oneParamWorkflow('limit'),
            $this->oneParamDefs('limit', 'number'),
            ['limit' => 'twelve'],
            false
        );
        $this->assertSame('twelve', $out['config']['v']);
    }

    public function testEmptyOptionalValueSkipsTypedValidation(): void
    {
        // No value, no default: resolves to '' and no typed check runs.
        $out = (new ParameterEngine())->apply(
            $this->oneParamWorkflow('endpoint'),
            $this->oneParamDefs('endpoint', 'url'),
            []
        );
        $this->assertSame('', $out['config']['v']);
    }

    public function testAuthoredDefaultIsValidatedToo(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be a number');
        (new ParameterEngine())->apply(
            $this->oneParamWorkflow('limit'),
            $this->oneParamDefs('limit', 'number', ['default' => 'twelve']),
            []
        );
    }
}
