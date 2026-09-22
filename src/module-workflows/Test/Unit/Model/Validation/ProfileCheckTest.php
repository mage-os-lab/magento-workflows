<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Validation;

use MageOS\Workflows\Api\ActionInterface;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\BatchCapableActionInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Model\Action\ActionPool;
use MageOS\Workflows\Model\Action\ActionResult;
use MageOS\Workflows\Model\Rule\AttributeClassifier;
use MageOS\Workflows\Model\Trigger\SnapshotShapeProvider;
use MageOS\Workflows\Model\Validation\Check\ProfileCheck;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationSubject;
use MageOS\Workflows\Test\Unit\Stub\StubAction;
use PHPUnit\Framework\TestCase;

/**
 * The batch profile-check matrix: each forbidden step / action / condition
 * class maps to its specific stable error code.
 */
class ProfileCheckTest extends TestCase
{
    private const FORCING_TYPE = 'MageOS\\Workflows\\Model\\Rule\\Condition\\RelatedEntity\\Combine';

    private function batchCapableAction(string $code): ActionInterface
    {
        return new class ($code) implements ActionInterface, BatchCapableActionInterface {
            public function __construct(private readonly string $code)
            {
            }

            public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
            {
                return ActionResult::success([]);
            }

            public function getCode(): string
            {
                return $this->code;
            }
        };
    }

    private function check(): ProfileCheck
    {
        $pool = new ActionPool([
            'notify.email' => $this->batchCapableAction('notify.email'),
            'order.hold' => new StubAction('order.hold', 'Hold Order'),
        ]);
        $classifier = new AttributeClassifier(['related_entity' => self::FORCING_TYPE]);
        $snapshot = new SnapshotShapeProvider([
            'sales_order' => ['entity_id', 'status', 'grand_total'],
        ]);

        return new ProfileCheck(
            [
                'aggregated' => [
                    'step_types' => ['action', 'delay', 'branch', 'switch', 'stop'],
                    'require_batch_capable_actions' => true,
                    'forbid_revalidate' => true,
                    'require_in_snapshot_conditions' => true,
                ],
            ],
            $pool,
            $classifier,
            $snapshot
        );
    }

    private function subject(array $definition, ?string $conditions = null): ValidationSubject
    {
        return new ValidationSubject((string) json_encode($definition), $conditions);
    }

    private function aggregatedContext(): ValidationContext
    {
        return new ValidationContext(
            ValidationContext::MODE_ADMIN_CONTEXT,
            ValidationContext::KIND_AGGREGATED,
            false,
            'sales_order'
        );
    }

    private function definition(array $steps, string $entry = 's1'): array
    {
        return ['schema' => 3, 'entry' => $entry, 'steps' => $steps];
    }

    private function codes(array $messages): array
    {
        return array_map(static fn ($m) => $m->getCode(), $messages);
    }

    public function testValidAggregatedWorkflowPasses(): void
    {
        $definition = $this->definition([
            's1' => ['type' => 'action', 'action' => 'notify.email', 'next' => 's2'],
            's2' => ['type' => 'stop'],
        ]);
        $conditions = json_encode(['type' => 'combine', 'conditions' => [
            ['type' => 'order.attr', 'attribute' => 'status', 'operator' => '==', 'value' => 'complete'],
        ]]);

        $messages = $this->check()->check($this->subject($definition, $conditions), $this->aggregatedContext());

        $this->assertSame([], $messages);
    }

    public function testWaitStepForbidden(): void
    {
        $definition = $this->definition([
            's1' => [
                'type' => 'wait',
                'config' => ['event' => 'sales.order.updated', 'timeout' => 'PT1H'],
                'on_event' => 's2',
                'on_timeout' => 's2',
            ],
            's2' => ['type' => 'stop'],
        ]);

        $messages = $this->check()->check($this->subject($definition), $this->aggregatedContext());

        $codes = $this->codes($messages);
        $this->assertTrue(in_array(ProfileCheck::CODE_STEP_TYPE_FORBIDDEN, $codes, true));
        $this->assertSame('s1', $messages[0]->getStepKey());
    }

    public function testNonBatchCapableActionForbidden(): void
    {
        $definition = $this->definition([
            's1' => ['type' => 'action', 'action' => 'order.hold', 'next' => null],
        ]);

        $messages = $this->check()->check($this->subject($definition), $this->aggregatedContext());

        $this->assertTrue(in_array(ProfileCheck::CODE_ACTION_NOT_BATCH_CAPABLE, $this->codes($messages), true));
    }

    public function testRevalidateEntityForbidden(): void
    {
        $definition = $this->definition([
            's1' => [
                'type' => 'branch',
                'revalidate_entity' => true,
                'conditions_serialized' => null,
                'on_true' => null,
                'on_false' => null,
            ],
        ]);

        $messages = $this->check()->check($this->subject($definition), $this->aggregatedContext());

        $this->assertTrue(in_array(ProfileCheck::CODE_REVALIDATE_FORBIDDEN, $this->codes($messages), true));
    }

    public function testAbsentRevalidateOnBranchForbidden(): void
    {
        // Branch/switch default to revalidate_entity = true when the key is
        // absent (the executor's `?? true` convention) — an omitted key in an
        // aggregated workflow must be flagged exactly like an explicit true,
        // or REST/CLI-authored definitions pass validation and fail-close at
        // run time against entity_id = 0.
        $definition = $this->definition([
            's1' => [
                'type' => 'branch',
                'conditions_serialized' => null,
                'on_true' => null,
                'on_false' => null,
            ],
        ]);

        $messages = $this->check()->check($this->subject($definition), $this->aggregatedContext());

        $this->assertTrue(in_array(ProfileCheck::CODE_REVALIDATE_FORBIDDEN, $this->codes($messages), true));
    }

    public function testExplicitFalseRevalidateOnBranchAllowed(): void
    {
        $definition = $this->definition([
            's1' => [
                'type' => 'branch',
                'revalidate_entity' => false,
                'conditions_serialized' => null,
                'on_true' => null,
                'on_false' => null,
            ],
        ]);

        $messages = $this->check()->check($this->subject($definition), $this->aggregatedContext());

        $this->assertFalse(in_array(ProfileCheck::CODE_REVALIDATE_FORBIDDEN, $this->codes($messages), true));
    }

    public function testCrossEntityRootConditionForbidden(): void
    {
        $definition = $this->definition([
            's1' => ['type' => 'stop'],
        ]);
        $conditions = json_encode(['type' => 'combine', 'conditions' => [
            ['type' => self::FORCING_TYPE, 'conditions' => []],
        ]]);

        $messages = $this->check()->check($this->subject($definition, $conditions), $this->aggregatedContext());

        $this->assertTrue(in_array(ProfileCheck::CODE_CONDITION_NOT_IN_SNAPSHOT, $this->codes($messages), true));
    }

    public function testOutOfSnapshotAttributeRootConditionForbidden(): void
    {
        $definition = $this->definition([
            's1' => ['type' => 'stop'],
        ]);
        // customer_group is NOT in the declared sales_order snapshot shape.
        $conditions = json_encode(['type' => 'combine', 'conditions' => [
            ['type' => 'order.attr', 'attribute' => 'customer_group', 'operator' => '==', 'value' => '1'],
        ]]);

        $messages = $this->check()->check($this->subject($definition, $conditions), $this->aggregatedContext());

        $codes = $this->codes($messages);
        $this->assertTrue(in_array(ProfileCheck::CODE_CONDITION_NOT_IN_SNAPSHOT, $codes, true));
        $this->assertStringContainsString('customer_group', $messages[0]->getMessage());
    }

    public function testInSnapshotAttributeRootConditionPasses(): void
    {
        $definition = $this->definition(['s1' => ['type' => 'stop']]);
        $conditions = json_encode(['type' => 'combine', 'conditions' => [
            ['type' => 'order.attr', 'attribute' => 'grand_total', 'operator' => '>=', 'value' => '1000'],
        ]]);

        $messages = $this->check()->check($this->subject($definition, $conditions), $this->aggregatedContext());

        $this->assertSame([], $messages);
    }

    public function testBranchGateWithCrossEntityConditionForbidden(): void
    {
        $definition = $this->definition([
            's1' => [
                'type' => 'branch',
                'conditions_serialized' => json_encode(['type' => 'combine', 'conditions' => [
                    ['type' => self::FORCING_TYPE, 'conditions' => []],
                ]]),
                'on_true' => null,
                'on_false' => null,
            ],
        ]);

        $messages = $this->check()->check($this->subject($definition), $this->aggregatedContext());

        $codes = $this->codes($messages);
        $this->assertTrue(in_array(ProfileCheck::CODE_CONDITION_NOT_IN_SNAPSHOT, $codes, true));
        foreach ($messages as $message) {
            if ($message->getCode() === ProfileCheck::CODE_CONDITION_NOT_IN_SNAPSHOT) {
                $this->assertSame('s1', $message->getStepKey());
            }
        }
    }

    public function testStandardWorkflowUnaffected(): void
    {
        // A standard (non-aggregated) workflow with a wait step and a mutating
        // action passes the profile check untouched — the profile only binds
        // the 'aggregated' kind.
        $definition = $this->definition([
            's1' => ['type' => 'wait', 'on_event' => 'x', 'on_timeout' => 's2'],
            's2' => ['type' => 'action', 'action' => 'order.hold', 'next' => null],
        ]);

        $messages = $this->check()->check(
            $this->subject($definition),
            new ValidationContext(ValidationContext::MODE_ADMIN_CONTEXT, ValidationContext::KIND_STANDARD)
        );

        $this->assertSame([], $messages);
    }

    public function testUnknownSnapshotShapeStillRejectsCrossEntity(): void
    {
        // No snapshot shape declared for 'customer' → per-attribute checks are
        // skipped, but the cross-entity node is still rejected.
        $context = new ValidationContext(
            ValidationContext::MODE_ADMIN_CONTEXT,
            ValidationContext::KIND_AGGREGATED,
            false,
            'customer'
        );
        $definition = $this->definition(['s1' => ['type' => 'stop']]);
        $conditions = json_encode(['type' => 'combine', 'conditions' => [
            ['type' => self::FORCING_TYPE, 'conditions' => []],
            ['type' => 'customer.attr', 'attribute' => 'some_unknown_attr', 'operator' => '==', 'value' => 'x'],
        ]]);

        $messages = $this->check()->check($this->subject($definition, $conditions), $context);

        // Exactly one error: the forcing node type. The unknown attribute is
        // not second-guessed because the shape is undeclared.
        $this->assertCount(1, $messages);
        $this->assertSame(ProfileCheck::CODE_CONDITION_NOT_IN_SNAPSHOT, $messages[0]->getCode());
    }
}
