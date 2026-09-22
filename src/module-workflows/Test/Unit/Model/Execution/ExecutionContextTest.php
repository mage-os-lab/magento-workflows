<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Execution;

use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use PHPUnit\Framework\TestCase;

class ExecutionContextTest extends TestCase
{
    public function testResolvePathHappyPaths(): void
    {
        $ctx = new ExecutionContext(
            new WorkflowExecutionStub(),
            ['grand_total' => '650.00'],
            ['fraud' => ['response' => ['score' => 42]]],
            ['id' => 7, 'name' => 'High-value order fraud check']
        );

        $this->assertSame('650.00', $ctx->resolvePath('trigger.grand_total'));
        $this->assertSame(42, $ctx->resolvePath('steps.fraud.response.score'));
        $this->assertSame('High-value order fraud check', $ctx->resolvePath('workflow.name'));
        $this->assertEquals(['grand_total' => '650.00'], $ctx->resolvePath('trigger'));
    }

    public function testResolvePathMissingPaths(): void
    {
        $ctx = new ExecutionContext(new WorkflowExecutionStub(), ['grand_total' => '650.00']);

        $this->assertNull($ctx->resolvePath('trigger.does_not_exist'));
        $this->assertNull($ctx->resolvePath('steps.fraud.response.score'));
        $this->assertNull($ctx->resolvePath('unknown_root.anything'));
        $this->assertNull($ctx->resolvePath('trigger.grand_total.too_deep'));
    }

    public function testSetStepOutputVisibleInResolvePath(): void
    {
        $ctx = new ExecutionContext(new WorkflowExecutionStub());

        $this->assertNull($ctx->resolvePath('steps.fraud.response.score'));

        $ctx->setStepOutput('fraud', ['response' => ['score' => 99]]);

        $this->assertSame(99, $ctx->resolvePath('steps.fraud.response.score'));
        $this->assertEquals(['fraud' => ['response' => ['score' => 99]]], $ctx->getSteps());
    }

    public function testGetDedupeKeyFormat(): void
    {
        $ctx = new ExecutionContext(new WorkflowExecutionStub('exec-uuid-123'));

        $this->assertSame('exec-uuid-123:s1', $ctx->getDedupeKey('s1'));
        $this->assertSame('exec-uuid-123:s2', $ctx->getDedupeKey('s2'));
    }

    public function testToArrayShape(): void
    {
        $ctx = new ExecutionContext(
            new WorkflowExecutionStub(),
            ['grand_total' => '650.00'],
            ['fraud' => ['response' => ['score' => 42]]],
            ['id' => 7]
        );

        $array = $ctx->toArray();

        $this->assertCount(3, $array);
        $this->assertArrayHasKey('trigger', $array);
        $this->assertArrayHasKey('steps', $array);
        $this->assertArrayHasKey('workflow', $array);
        $this->assertEquals(['grand_total' => '650.00'], $array['trigger']);
        $this->assertEquals(['fraud' => ['response' => ['score' => 42]]], $array['steps']);
        $this->assertEquals(['id' => 7], $array['workflow']);
    }

    public function testDelegatesEntityAndStoreIdToExecution(): void
    {
        $execution = new WorkflowExecutionStub('uuid', 55, 2);
        $ctx = new ExecutionContext($execution);

        $this->assertSame(55, $ctx->getEntityId());
        $this->assertSame(2, $ctx->getStoreId());
        $this->assertFalse($ctx->isSimulation());
        $this->assertSame($execution, $ctx->getExecution());
    }

    public function testSimulationFlag(): void
    {
        $ctx = new ExecutionContext(new WorkflowExecutionStub(), [], [], [], true);

        $this->assertTrue($ctx->isSimulation());
    }
}
