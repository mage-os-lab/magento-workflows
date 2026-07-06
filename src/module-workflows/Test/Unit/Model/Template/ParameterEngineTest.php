<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Template;

use Magento\Framework\Exception\LocalizedException;
use MageOS\Workflows\Model\Template\ParameterEngine;
use PHPUnit\Framework\TestCase;

/**
 * %param.<key>% substitution: defaults, required/unknown/option enforcement,
 * embedded + whole-value tokens, and the leftover-token hard error — all before
 * any write.
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
}
