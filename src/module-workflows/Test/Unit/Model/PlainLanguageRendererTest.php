<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model;

use MageOS\Workflows\Model\Action\ActionPool;
use MageOS\Workflows\Model\PlainLanguageRenderer;
use MageOS\Workflows\Model\Trigger\TriggerRegistry;
use MageOS\Workflows\Test\Unit\Stub\StubAction;
use PHPUnit\Framework\TestCase;

class PlainLanguageRendererTest extends TestCase
{
    private function renderer(): PlainLanguageRenderer
    {
        $registry = new class extends TriggerRegistry {
            public function __construct()
            {
            }

            public function getAll(): array
            {
                return [
                    'sales.order.created' => [
                        'event' => 'sales.order.created',
                        'entity' => 'sales_order',
                        'label' => 'Order Created',
                    ],
                ];
            }
        };

        return new PlainLanguageRenderer(
            new ActionPool([
                'order.add_comment' => new StubAction('order.add_comment', 'Add Order Comment'),
            ]),
            $registry
        );
    }

    private function render(array $definition, ?string $conditions = null): string
    {
        return $this->renderer()->renderFromFields(
            'event',
            'sales.order.created',
            'sales_order',
            $conditions,
            (string) json_encode($definition)
        );
    }

    public function testLinearChainRendersAsBefore(): void
    {
        $sentence = $this->render([
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => ['type' => 'action', 'action' => 'order.add_comment', 'next' => 's2'],
                's2' => ['type' => 'delay', 'config' => ['duration' => 'PT1H'], 'next' => 's3'],
                's3' => ['type' => 'stop'],
            ],
        ]);

        $this->assertSame(
            'When Order Created, then: Add Order Comment, wait 1 hour, stop.',
            $sentence
        );
    }

    public function testLegacyBranchWithNullOnFalseRendersUnchanged(): void
    {
        // The v1 form-assembler shape: on_false is always null. Output must
        // stay byte-identical to the pre-relocation renderer.
        $sentence = $this->render([
            'schema' => 1,
            'entry' => 'b1',
            'steps' => [
                'b1' => [
                    'type' => 'branch',
                    'conditions_serialized' => '{"type":"combine","conditions":'
                        . '[{"attribute":"status"},{"attribute":"grand_total"}]}',
                    'on_true' => 's1',
                    'on_false' => null,
                ],
                's1' => ['type' => 'stop'],
            ],
        ]);

        $this->assertSame(
            'When Order Created, then: if 2 more conditions still hold, stop.',
            $sentence
        );
    }

    public function testBothBranchEdgesRenderOtherwiseChain(): void
    {
        $sentence = $this->render([
            'schema' => 1,
            'entry' => 'b1',
            'steps' => [
                'b1' => [
                    'type' => 'branch',
                    'conditions_serialized' => '{"type":"combine","conditions":[{"attribute":"status"}]}',
                    'on_true' => 'yes',
                    'on_false' => 'no',
                ],
                'yes' => ['type' => 'action', 'action' => 'order.add_comment', 'next' => null],
                'no' => ['type' => 'stop'],
            ],
        ]);

        $this->assertSame(
            'When Order Created, then: if 1 more condition still holds (otherwise: stop), Add Order Comment.',
            $sentence
        );
    }

    public function testWaitStepRendered(): void
    {
        $sentence = $this->render([
            'schema' => 2,
            'entry' => 'w1',
            'steps' => [
                'w1' => [
                    'type' => 'wait',
                    'config' => ['event' => 'sales.order.updated', 'timeout' => 'PT2H'],
                    'on_event' => 's1',
                    'on_timeout' => null,
                ],
                's1' => ['type' => 'stop'],
            ],
        ]);

        $this->assertSame(
            'When Order Created, then: wait for "sales.order.updated" up to 2 hours, stop.',
            $sentence
        );
    }

    public function testSwitchStepRendersCaseKeysAndFollowsFirstCase(): void
    {
        $sentence = $this->render([
            'schema' => 3,
            'entry' => 'route',
            'steps' => [
                'route' => [
                    'type' => 'switch',
                    'cases' => [
                        ['key' => 'us', 'next' => 'us_flow'],
                        ['key' => 'eu', 'next' => 'eu_flow'],
                    ],
                    'default' => null,
                ],
                'us_flow' => ['type' => 'action', 'action' => 'order.add_comment', 'next' => null],
                'eu_flow' => ['type' => 'stop'],
            ],
        ]);

        $this->assertSame(
            'When Order Created, then: first match of 2 cases (us, eu), Add Order Comment.',
            $sentence
        );
    }

    public function testCycleTerminates(): void
    {
        $sentence = $this->render([
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => ['type' => 'action', 'action' => 'order.add_comment', 'next' => 's2'],
                's2' => ['type' => 'action', 'action' => 'order.add_comment', 'next' => 's1'],
            ],
        ]);

        $this->assertSame(
            'When Order Created, then: Add Order Comment, Add Order Comment.',
            $sentence
        );
    }

    public function testMalformedDefinitionDegradesToTriggerOnly(): void
    {
        $sentence = $this->renderer()->renderFromFields(
            'event',
            'sales.order.created',
            'sales_order',
            null,
            '{broken'
        );

        $this->assertSame('When Order Created.', $sentence);
    }

    public function testRootConditionCountRendered(): void
    {
        $sentence = $this->render(
            ['schema' => 1, 'steps' => [], 'entry' => null],
            '{"type":"combine","conditions":[{"attribute":"grand_total"}]}'
        );

        $this->assertSame('When Order Created, if 1 condition.', $sentence);
    }
}
