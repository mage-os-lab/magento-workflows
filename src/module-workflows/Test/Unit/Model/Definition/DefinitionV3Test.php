<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Definition;

use MageOS\Workflows\Model\Definition\Definition;
use PHPUnit\Framework\TestCase;

/**
 * Schema 3 (F1): switch step, the non-semantic ui block, and the
 * getStepEdges() edge-routing helper.
 */
class DefinitionV3Test extends TestCase
{
    private function switchDefinition(array $overrides = []): array
    {
        return array_replace_recursive([
            'schema' => 3,
            'entry' => 'route',
            'steps' => [
                'route' => [
                    'type' => Definition::STEP_SWITCH,
                    'cases' => [
                        ['key' => 'us', 'conditions_serialized' => '{"type":"combine"}', 'next' => 'us_flow'],
                        ['key' => 'eu', 'conditions_serialized' => '{"type":"combine"}', 'next' => 'eu_flow'],
                    ],
                    'revalidate_entity' => true,
                    'default' => 'rest',
                ],
                'us_flow' => ['type' => Definition::STEP_STOP],
                'eu_flow' => ['type' => Definition::STEP_STOP],
                'rest' => ['type' => Definition::STEP_STOP],
            ],
        ], $overrides);
    }

    public function testSchemaThreeAcceptedAndNormalized(): void
    {
        $definition = Definition::fromArray([
            'schema' => 3,
            'entry' => 's1',
            'steps' => ['s1' => ['type' => Definition::STEP_STOP]],
        ]);

        $this->assertSame(Definition::SCHEMA_VERSION, $definition->getSchemaVersion());
    }

    public function testSwitchStepValidUnderSchema3(): void
    {
        $definition = Definition::fromArray($this->switchDefinition());

        $step = $definition->getStep('route');
        $this->assertSame(Definition::STEP_SWITCH, $step['type']);
        $this->assertCount(2, $step['cases']);
    }

    public function testSwitchStepAcceptedUnderLegacySchema2(): void
    {
        // Legacy schema numbers normalize to the current version on parse;
        // step types are no longer version-gated.
        $definition = Definition::fromArray($this->switchDefinition(['schema' => 2]));

        $this->assertSame(Definition::STEP_SWITCH, $definition->getStep('route')['type']);
        $this->assertSame(Definition::SCHEMA_VERSION, $definition->getSchemaVersion());
    }

    public function testSwitchWithoutCasesRejected(): void
    {
        $data = $this->switchDefinition();
        $data['steps']['route']['cases'] = [];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty "cases" list');

        Definition::fromArray($data);
    }

    public function testSwitchCaseDanglingNextRejected(): void
    {
        $data = $this->switchDefinition();
        $data['steps']['route']['cases'][1]['next'] = 'nowhere';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('points to unknown step "nowhere"');

        Definition::fromArray($data);
    }

    public function testSwitchDanglingDefaultRejected(): void
    {
        $data = $this->switchDefinition();
        $data['steps']['route']['default'] = 'nowhere';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('default edge points to unknown step "nowhere"');

        Definition::fromArray($data);
    }

    public function testSwitchDuplicateCaseKeysRejected(): void
    {
        $data = $this->switchDefinition();
        $data['steps']['route']['cases'][1]['key'] = 'us';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate case key "us"');

        Definition::fromArray($data);
    }

    public function testSwitchNonBooleanRevalidateRejected(): void
    {
        $data = $this->switchDefinition();
        $data['steps']['route']['revalidate_entity'] = 'yes';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('revalidate_entity must be boolean');

        Definition::fromArray($data);
    }

    public function testSwitchNullableDefaultAndCaseNextAccepted(): void
    {
        $data = $this->switchDefinition();
        $data['steps']['route']['default'] = null;
        $data['steps']['route']['cases'][0]['next'] = null;

        $definition = Definition::fromArray($data);

        $edges = $definition->getStepEdges('route');
        $this->assertNull($edges['case:us']);
        $this->assertNull($edges['default']);
    }

    // ------------------------------------------------------------------
    // getStepEdges: every step type × every edge
    // ------------------------------------------------------------------

    public function testGetStepEdgesForEveryStepType(): void
    {
        $definition = Definition::fromArray([
            'schema' => 3,
            'entry' => 'a1',
            'steps' => [
                'a1' => ['type' => Definition::STEP_ACTION, 'action' => 'order.add_comment', 'next' => 'd1'],
                'd1' => ['type' => Definition::STEP_DELAY, 'config' => ['duration' => 'PT1H'], 'next' => 'b1'],
                'b1' => ['type' => Definition::STEP_BRANCH, 'on_true' => 'w1', 'on_false' => 'sw1'],
                'w1' => [
                    'type' => Definition::STEP_WAIT,
                    'config' => ['event' => 'sales.order.updated', 'timeout' => 'PT2H'],
                    'on_event' => 'stop1',
                    'on_timeout' => null,
                ],
                'sw1' => [
                    'type' => Definition::STEP_SWITCH,
                    'cases' => [
                        ['key' => 'us', 'next' => 'stop1'],
                        ['key' => 'eu', 'next' => null],
                    ],
                    'default' => 'stop1',
                ],
                'stop1' => ['type' => Definition::STEP_STOP],
            ],
        ]);

        $this->assertSame(['next' => 'd1'], $definition->getStepEdges('a1'));
        $this->assertSame(['next' => 'b1'], $definition->getStepEdges('d1'));
        $this->assertSame(['on_true' => 'w1', 'on_false' => 'sw1'], $definition->getStepEdges('b1'));
        $this->assertSame(['on_event' => 'stop1', 'on_timeout' => null], $definition->getStepEdges('w1'));
        $this->assertSame(
            ['case:us' => 'stop1', 'case:eu' => null, 'default' => 'stop1'],
            $definition->getStepEdges('sw1')
        );
        $this->assertSame([], $definition->getStepEdges('stop1'));
    }

    public function testGetStepEdgesMissingEdgeFieldsAreNull(): void
    {
        $definition = Definition::fromArray([
            'schema' => 1,
            'entry' => 'a1',
            'steps' => [
                'a1' => ['type' => Definition::STEP_ACTION, 'action' => 'order.add_comment'],
                'b1' => ['type' => Definition::STEP_BRANCH],
            ],
        ]);

        $this->assertSame(['next' => null], $definition->getStepEdges('a1'));
        $this->assertSame(['on_true' => null, 'on_false' => null], $definition->getStepEdges('b1'));
    }

    public function testGetStepEdgesUnknownStepThrows(): void
    {
        $definition = Definition::fromArray(['schema' => 1, 'steps' => [], 'entry' => null]);

        $this->expectException(\InvalidArgumentException::class);

        $definition->getStepEdges('missing');
    }

    // ------------------------------------------------------------------
    // ui block preservation
    // ------------------------------------------------------------------

    public function testUiBlockPreservedVerbatimThroughRoundTrip(): void
    {
        $ui = [
            'canvas' => ['zoom' => 0.75, 'pan' => ['x' => 12, 'y' => -40]],
            'nodes' => ['s1' => ['x' => 100, 'y' => 220, 'collapsed' => false]],
        ];
        $data = [
            'schema' => 2,
            'entry' => 's1',
            'steps' => ['s1' => ['type' => Definition::STEP_STOP]],
            'ui' => $ui,
        ];

        $definition = Definition::fromArray($data);

        $this->assertSame($ui, $definition->getUi());
        $this->assertSame($ui, $definition->toArray()['ui']);

        // byte-for-byte through the JSON path too
        $roundTripped = Definition::fromJson($definition->toJson());
        $this->assertSame($ui, $roundTripped->toArray()['ui']);
    }

    public function testUiBlockLegalAtSchemaOne(): void
    {
        $definition = Definition::fromArray([
            'schema' => 1,
            'entry' => 's1',
            'steps' => ['s1' => ['type' => Definition::STEP_STOP]],
            'ui' => ['nodes' => []],
        ]);

        $this->assertSame(['nodes' => []], $definition->getUi());
    }

    public function testAbsentUiBlockOmittedFromToArray(): void
    {
        $definition = Definition::fromArray([
            'schema' => 1,
            'entry' => 's1',
            'steps' => ['s1' => ['type' => Definition::STEP_STOP]],
        ]);

        $this->assertNull($definition->getUi());
        $this->assertFalse(array_key_exists('ui', $definition->toArray()));
    }

    public function testNonObjectUiRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"ui" must be an object');

        Definition::fromArray([
            'schema' => 1,
            'entry' => 's1',
            'steps' => ['s1' => ['type' => Definition::STEP_STOP]],
            'ui' => 'layout',
        ]);
    }

    public function testOtherUnknownTopLevelKeysAreNotPreserved(): void
    {
        $definition = Definition::fromArray([
            'schema' => 1,
            'entry' => 's1',
            'steps' => ['s1' => ['type' => Definition::STEP_STOP]],
            'x_vendor' => ['anything' => true],
        ]);

        $this->assertFalse(array_key_exists('x_vendor', $definition->toArray()));
    }
}
