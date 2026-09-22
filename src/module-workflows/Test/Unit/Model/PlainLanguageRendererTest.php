<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model;

use Magento\Framework\DataObject;
use MageOS\Workflows\Api\RelationInterface;
use MageOS\Workflows\Model\Action\ActionPool;
use MageOS\Workflows\Model\PlainLanguageRenderer;
use MageOS\Workflows\Model\Relation\RelationPool;
use MageOS\Workflows\Model\Rule\Condition\RelatedEntity\Combine as RelatedEntityCombine;
use MageOS\Workflows\Model\Trigger\TriggerRegistry;
use MageOS\Workflows\Test\Unit\Stub\StubAction;
use PHPUnit\Framework\TestCase;

class PlainLanguageRendererTest extends TestCase
{
    private function relationPool(): RelationPool
    {
        $relation = new class implements RelationInterface {
            public function getCode(): string
            {
                return 'order.customer_by_email';
            }

            public function getLabel(): string
            {
                return 'a customer account matching the order email';
            }

            public function getSourceEntityType(): string
            {
                return 'sales_order';
            }

            public function getTargetEntityType(): string
            {
                return 'customer';
            }

            public function getCardinality(): string
            {
                return self::CARDINALITY_ONE;
            }

            public function resolveIds(DataObject $source, ?int $websiteId): array
            {
                return [];
            }
        };
        return new RelationPool(['order.customer_by_email' => $relation]);
    }

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
            $registry,
            $this->relationPool()
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

    public function testFanOutLeadsWithPerTargetPhrasingAndCap(): void
    {
        $sentence = $this->renderer()->renderFromFields(
            'event',
            'sales.order.created',
            'sales_order',
            null,
            (string) json_encode([
                'schema' => 1,
                'entry' => 's1',
                'steps' => ['s1' => ['type' => 'action', 'action' => 'order.add_comment']],
            ]),
            (string) json_encode(['relation' => 'order.customer_by_email', 'cap' => 25])
        );

        $this->assertSame(
            'When Order Created, for each of a customer account matching the order email (up to 25), '
            . 'then: Add Order Comment.',
            $sentence
        );
    }

    public function testFanOutWithoutCapShowsGlobalDefault(): void
    {
        $sentence = $this->renderer()->renderFromFields(
            'event',
            'sales.order.created',
            'sales_order',
            null,
            (string) json_encode(['schema' => 1, 'entry' => null, 'steps' => []]),
            (string) json_encode(['relation' => 'order.customer_by_email'])
        );

        $this->assertStringContainsString(
            'for each of a customer account matching the order email (up to 100)',
            $sentence
        );
    }

    public function testNoFanOutRendersUnchanged(): void
    {
        $sentence = $this->render([
            'schema' => 1,
            'entry' => 's1',
            'steps' => ['s1' => ['type' => 'action', 'action' => 'order.add_comment']],
        ]);

        $this->assertSame('When Order Created, then: Add Order Comment.', $sentence);
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

    public function testApprovalStepRendersAllThreeOutcomesInline(): void
    {
        $sentence = $this->render([
            'schema' => 4,
            'entry' => 'gate',
            'steps' => [
                'gate' => [
                    'type' => 'approval',
                    'config' => [
                        'title' => 'Approve credit',
                        'timeout' => 'P3D',
                        'assignee_role' => 'sales_managers',
                    ],
                    'on_approved' => 'act',
                    'on_rejected' => null,
                    'on_timeout' => null,
                ],
                'act' => ['type' => 'action', 'action' => 'order.add_comment', 'next' => null],
            ],
        ]);

        $this->assertSame(
            'When Order Created, then: wait up to 3 days for a decision (role: sales_managers): '
            . 'if approved → Add Order Comment, if rejected → the workflow ends, '
            . 'if no decision by then → the workflow ends.',
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

    public function testBareNotExistsRelationRendersAsClause(): void
    {
        // The flagship guest check: childless NOT EXISTS carries zero attribute
        // leaves, so the count is 0 — the relation clause must still describe it.
        $conditions = json_encode([
            'type' => 'combine',
            'conditions' => [
                [
                    'type' => RelatedEntityCombine::class,
                    'relation' => 'order.customer_by_email',
                    'value' => '0',
                ],
            ],
        ]);

        $sentence = $this->render(['schema' => 1, 'steps' => [], 'entry' => null], (string) $conditions);

        $this->assertSame(
            'When Order Created, if a customer account matching the order email does not exist.',
            $sentence
        );
    }

    public function testExistsRelationCombinesWithAttributeCount(): void
    {
        $conditions = json_encode([
            'type' => 'combine',
            'conditions' => [
                ['attribute' => 'grand_total'],
                [
                    'type' => RelatedEntityCombine::class,
                    'relation' => 'order.customer_by_email',
                    'value' => '1',
                ],
            ],
        ]);

        $sentence = $this->render(['schema' => 1, 'steps' => [], 'entry' => null], (string) $conditions);

        $this->assertSame(
            'When Order Created, if 1 condition and a customer account matching the order email exists.',
            $sentence
        );
    }

    private function renderBatch(array $definition, string $aggregationJson, ?string $conditions = null): string
    {
        return $this->renderer()->renderFromFields(
            'event',
            'sales.order.created',
            'sales_order',
            $conditions,
            (string) json_encode($definition),
            null,
            $aggregationJson
        );
    }

    public function testAggregatedScheduleWorkflowRendersBatchLanguage(): void
    {
        $definition = [
            'schema' => 1,
            'entry' => 's1',
            'steps' => ['s1' => ['type' => 'action', 'action' => 'order.add_comment', 'next' => null]],
        ];
        $aggregation = json_encode([
            'mode' => 'window',
            'window' => ['type' => 'schedule', 'cron' => '0 9 * * *', 'timezone' => 'UTC'],
        ]);
        $conditions = json_encode(['conditions' => [['attribute' => 'status']]]);

        $sentence = $this->renderBatch($definition, (string) $aggregation, (string) $conditions);

        $this->assertSame(
            'Once a day, as one digest, for everything that matches 1 condition, then: Add Order Comment.',
            $sentence
        );
    }

    public function testAggregatedIntervalWorkflowRendersEveryHour(): void
    {
        $definition = [
            'schema' => 1,
            'entry' => 's1',
            'steps' => ['s1' => ['type' => 'stop']],
        ];
        $aggregation = json_encode([
            'mode' => 'window',
            'window' => ['type' => 'interval', 'duration' => 'PT1H'],
        ]);

        $sentence = $this->renderBatch($definition, (string) $aggregation);

        $this->assertSame('Every 1 hour, as one digest, for everything, then: stop.', $sentence);
    }
}
