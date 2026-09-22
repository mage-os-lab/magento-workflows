<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Variable;

use MageOS\Workflows\Model\Aggregation\ItemProjector;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Model\Variable\VariableResolver;
use MageOS\Workflows\Test\Unit\Stub\SecretsProviderStub;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use PHPUnit\Framework\TestCase;

/**
 * Batch collection formatters over the raw-array pipeline stage: count, pluck,
 * join, table (escaped), json — plus chaining, non-list fail-open, and
 * B1/B2 item-shape parity for pluck/table.
 */
class VariableResolverCollectionFormattersTest extends TestCase
{
    private VariableResolver $resolver;

    public function setUp(): void
    {
        $this->resolver = new VariableResolver(new SecretsProviderStub());
    }

    private function context(array $items): ExecutionContext
    {
        return new ExecutionContext(new WorkflowExecutionStub(), ['batch' => true, 'items' => $items]);
    }

    public function testCount(): void
    {
        $ctx = $this->context([['sku' => 'A'], ['sku' => 'B'], ['sku' => 'C']]);

        $this->assertSame('3', $this->resolver->resolve('{{ trigger.items|count }}', $ctx));
    }

    public function testPluckThenJoin(): void
    {
        $ctx = $this->context([['sku' => 'A'], ['sku' => 'B'], ['sku' => 'C']]);

        $result = $this->resolver->resolve("{{ trigger.items|pluck:'sku'|join:', ' }}", $ctx);

        $this->assertSame('A, B, C', $result);
    }

    public function testJoinOnFlatList(): void
    {
        $ctx = $this->context(['A', 'B']);

        $this->assertSame('A | B', $this->resolver->resolve("{{ trigger.items|join:' | ' }}", $ctx));
    }

    public function testTableEscapesCells(): void
    {
        $ctx = $this->context([
            ['sku' => 'A<1>', 'name' => 'Tom & Jerry'],
            ['sku' => 'B', 'name' => 'Plain'],
        ]);

        $result = $this->resolver->resolve("{{ trigger.items|table:'sku,name' }}", $ctx);

        $this->assertStringContainsString('<table>', $result);
        $this->assertStringContainsString('<th>sku</th><th>name</th>', $result);
        // Cell content HTML-escaped.
        $this->assertStringContainsString('A&lt;1&gt;', $result);
        $this->assertStringContainsString('Tom &amp; Jerry', $result);
        $this->assertTrue(!str_contains($result, '<1>'));
    }

    public function testJson(): void
    {
        $ctx = $this->context([['sku' => 'A']]);

        $result = $this->resolver->resolve('{{ trigger.items|json }}', $ctx);

        $this->assertSame('[{"sku":"A"}]', $result);
    }

    public function testCountOnNonListFailsOpen(): void
    {
        // A scalar value with a collection filter: the value is not a list, so
        // count fails open and the scalar passes through unchanged.
        $ctx = new ExecutionContext(new WorkflowExecutionStub(), ['name' => 'hello']);

        $this->assertSame('hello', $this->resolver->resolve('{{ trigger.name|count }}', $ctx));
    }

    public function testArrayWithoutCollectionFilterStillRendersEmpty(): void
    {
        // Legacy behaviour preserved: an array with no collection filter (or a
        // scalar filter first) renders to '' as before.
        $ctx = $this->context([['sku' => 'A']]);

        $this->assertSame('', $this->resolver->resolve('{{ trigger.items }}', $ctx));
        $this->assertSame('', $this->resolver->resolve('{{ trigger.items|upper }}', $ctx));
    }

    public function testPluckMissingFieldYieldsEmptyJoin(): void
    {
        $ctx = $this->context([['sku' => 'A'], ['other' => 'B']]);

        $result = $this->resolver->resolve("{{ trigger.items|pluck:'sku'|join:',' }}", $ctx);

        // Only the row that has 'sku' contributes.
        $this->assertSame('A', $result);
    }

    /**
     * pluck/table resolve an EAV attribute identically under the B1 and B2 item
     * shapes — both are flat projections produced by the same ItemProjector, so
     * a lifted EAV attribute sits at the same top-level key in each.
     */
    public function testPluckResolvesEavAttributeIdenticallyUnderBothItemShapes(): void
    {
        $projector = new ItemProjector();
        $fields = $projector->fieldsFor(['entity_id', 'my_eav'], null);

        // B2 event-snapshot item (EAV lifted to top level by EntityDataConverter).
        $b2Flat = ['entity_id' => 1, 'my_eav' => 'GOLD', 'noise' => 'x'];
        // B1 collected-mode item (same converter, same lift).
        $b1Flat = ['entity_id' => 1, 'my_eav' => 'GOLD', 'noise' => 'y'];

        $b2Item = $projector->project($b2Flat, $fields);
        $b1Item = $projector->project($b1Flat, $fields);

        $b2 = $this->resolver->resolve("{{ trigger.items|pluck:'my_eav'|join:',' }}", $this->context([$b2Item]));
        $b1 = $this->resolver->resolve("{{ trigger.items|pluck:'my_eav'|join:',' }}", $this->context([$b1Item]));

        $this->assertSame('GOLD', $b2);
        $this->assertSame($b2, $b1);
    }

    public function testCountThenNoScalarFilterInteraction(): void
    {
        // count yields a numeric string that scalar filters can still touch.
        $ctx = $this->context([['x' => 1], ['x' => 2]]);

        $this->assertSame('2 items', $this->resolver->resolve('{{ trigger.items|count }} items', $ctx));
    }
}
