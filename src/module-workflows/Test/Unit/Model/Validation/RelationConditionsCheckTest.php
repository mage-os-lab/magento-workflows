<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Validation;

use MageOS\Workflows\Model\Rule\Condition\RelatedEntity\Combine as RelatedEntityCombine;
use MageOS\Workflows\Model\Validation\Check\RelationConditionsCheck;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationMessage;
use MageOS\Workflows\Model\Validation\ValidationSubject;
use PHPUnit\Framework\TestCase;

/**
 * A NOT-EXISTS related-entity node with children is a hard save error
 * (entity cross-referencing §9): the operators stop being complements once
 * children exist, so "NOT EXISTS a customer with orders_count >= 3" would
 * silently never match.
 */
class RelationConditionsCheckTest extends TestCase
{
    private function context(): ValidationContext
    {
        return new ValidationContext(
            ValidationContext::MODE_ADMIN_CONTEXT,
            ValidationContext::KIND_STANDARD,
            false
        );
    }

    private function subject(string $conditions, string $definitionJson = '{"schema":1,"steps":[],"entry":null}'): ValidationSubject
    {
        return new ValidationSubject($definitionJson, $conditions);
    }

    private function relationNode(string $value, array $children): string
    {
        return (string) json_encode([
            'type' => 'combine',
            'conditions' => [
                [
                    'type' => RelatedEntityCombine::class,
                    'relation' => 'order.customer_by_email',
                    'value' => $value,
                    'conditions' => $children,
                ],
            ],
        ]);
    }

    public function testNotExistsWithChildrenIsError(): void
    {
        $check = new RelationConditionsCheck();
        $messages = $check->check(
            $this->subject($this->relationNode('0', [['attribute' => 'orders_count', 'operator' => '>=', 'value' => '3']])),
            $this->context()
        );

        $this->assertCount(1, $messages);
        $this->assertSame(RelationConditionsCheck::CODE_NOT_EXISTS_WITH_CHILDREN, $messages[0]->getCode());
        $this->assertSame(ValidationMessage::SEVERITY_ERROR, $messages[0]->getSeverity());
        $this->assertNull($messages[0]->getStepKey());
    }

    public function testExistsWithChildrenIsAllowed(): void
    {
        $check = new RelationConditionsCheck();
        $messages = $check->check(
            $this->subject($this->relationNode('1', [['attribute' => 'orders_count', 'operator' => '>=', 'value' => '3']])),
            $this->context()
        );

        $this->assertSame([], $messages);
    }

    public function testChildlessNotExistsIsAllowed(): void
    {
        $check = new RelationConditionsCheck();
        $messages = $check->check($this->subject($this->relationNode('0', [])), $this->context());

        $this->assertSame([], $messages);
    }

    public function testBranchStepNotExistsWithChildrenPinsToStepKey(): void
    {
        $definition = (string) json_encode([
            'schema' => 3,
            'entry' => 'gate',
            'steps' => [
                'gate' => [
                    'type' => 'branch',
                    'conditions_serialized' => $this->relationNode(
                        '0',
                        [['attribute' => 'orders_count', 'operator' => '>=', 'value' => '3']]
                    ),
                    'on_true' => null,
                    'on_false' => null,
                ],
            ],
        ]);

        $check = new RelationConditionsCheck();
        $messages = $check->check(new ValidationSubject($definition, null), $this->context());

        $this->assertCount(1, $messages);
        $this->assertSame('gate', $messages[0]->getStepKey());
    }

    public function testEmptyConditionsProduceNoMessages(): void
    {
        $check = new RelationConditionsCheck();
        $this->assertSame([], $check->check(new ValidationSubject('{"schema":1,"steps":[],"entry":null}', null), $this->context()));
    }
}
