<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Validation;

use MageOS\Workflows\Model\Validation\Check\GraphCheck;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationSubject;
use PHPUnit\Framework\TestCase;

class GraphCheckTest extends TestCase
{
    private function check(array $definition): array
    {
        return (new GraphCheck())->check(
            new ValidationSubject((string) json_encode($definition)),
            new ValidationContext()
        );
    }

    private function codes(array $messages): array
    {
        return array_map(static fn ($m) => $m->getCode(), $messages);
    }

    public function testCleanLinearGraphYieldsNoMessages(): void
    {
        $messages = $this->check([
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => ['type' => 'action', 'action' => 'a.b', 'next' => 's2'],
                's2' => ['type' => 'stop'],
            ],
        ]);

        $this->assertSame([], $messages);
    }

    public function testCycleReachableFromEntryIsError(): void
    {
        $messages = $this->check([
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => ['type' => 'action', 'action' => 'a.b', 'next' => 's2'],
                's2' => ['type' => 'action', 'action' => 'a.b', 'next' => 's1'],
            ],
        ]);

        $this->assertCount(1, $messages);
        $this->assertSame(GraphCheck::CODE_CYCLE, $messages[0]->getCode());
        $this->assertSame('error', $messages[0]->getSeverity());
        $this->assertSame('s2', $messages[0]->getStepKey());
        $this->assertSame('next', $messages[0]->getEdge());
    }

    public function testSelfLoopIsCycleError(): void
    {
        $messages = $this->check([
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => ['type' => 'action', 'action' => 'a.b', 'next' => 's1'],
            ],
        ]);

        $this->assertSame([GraphCheck::CODE_CYCLE], $this->codes($messages));
    }

    public function testUnreachableStepIsWarning(): void
    {
        $messages = $this->check([
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => ['type' => 'stop'],
                'orphan' => ['type' => 'stop'],
            ],
        ]);

        $this->assertCount(1, $messages);
        $this->assertSame(GraphCheck::CODE_UNREACHABLE_STEP, $messages[0]->getCode());
        $this->assertSame('warning', $messages[0]->getSeverity());
        $this->assertSame('orphan', $messages[0]->getStepKey());
    }

    public function testBothEdgesNullBranchIsWarningNotError(): void
    {
        // The degenerate assembler output: the form's last-row branch has
        // on_true = on_false = null. It must stay re-savable.
        $messages = $this->check([
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => ['type' => 'branch', 'on_true' => null, 'on_false' => null],
            ],
        ]);

        $this->assertSame([GraphCheck::CODE_DEAD_EDGE], $this->codes($messages));
        $this->assertSame('warning', $messages[0]->getSeverity());
    }

    public function testAllNullSwitchEdgesIsWarning(): void
    {
        $messages = $this->check([
            'schema' => 3,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => 'switch',
                    'cases' => [['key' => 'only', 'next' => null]],
                    'default' => null,
                ],
            ],
        ]);

        $this->assertSame([GraphCheck::CODE_DEAD_EDGE], $this->codes($messages));
    }

    public function testPostDelayBranchWithoutRevalidationIsWarning(): void
    {
        $messages = $this->check([
            'schema' => 1,
            'entry' => 'd1',
            'steps' => [
                'd1' => ['type' => 'delay', 'config' => ['duration' => 'PT1H'], 'next' => 'b1'],
                'b1' => ['type' => 'branch', 'revalidate_entity' => false, 'on_true' => 's1', 'on_false' => null],
                's1' => ['type' => 'stop'],
            ],
        ]);

        $this->assertSame([GraphCheck::CODE_POST_DELAY_STALE], $this->codes($messages));
        $this->assertSame('b1', $messages[0]->getStepKey());
    }

    public function testPostDelayBranchWithRevalidationIsClean(): void
    {
        $messages = $this->check([
            'schema' => 1,
            'entry' => 'd1',
            'steps' => [
                'd1' => ['type' => 'delay', 'config' => ['duration' => 'PT1H'], 'next' => 'b1'],
                'b1' => ['type' => 'branch', 'revalidate_entity' => true, 'on_true' => 's1', 'on_false' => null],
                's1' => ['type' => 'stop'],
            ],
        ]);

        $this->assertSame([], $messages);
    }

    public function testPostApprovalBranchWithoutRevalidationIsWarning(): void
    {
        // A gate can park for days; a branch after it evaluating the frozen
        // trigger snapshot has the same staleness hazard as post-delay.
        $messages = $this->check([
            'schema' => 4,
            'entry' => 'gate',
            'steps' => [
                'gate' => [
                    'type' => 'approval',
                    'config' => ['title' => 'Approve', 'timeout' => 'P3D'],
                    'on_approved' => 'b1',
                    'on_rejected' => null,
                    'on_timeout' => null,
                ],
                'b1' => ['type' => 'branch', 'revalidate_entity' => false, 'on_true' => 's1', 'on_false' => null],
                's1' => ['type' => 'stop'],
            ],
        ]);

        $this->assertSame([GraphCheck::CODE_POST_DELAY_STALE], $this->codes($messages));
        $this->assertSame('b1', $messages[0]->getStepKey());
    }

    public function testPostApprovalBranchWithRevalidationIsClean(): void
    {
        $messages = $this->check([
            'schema' => 4,
            'entry' => 'gate',
            'steps' => [
                'gate' => [
                    'type' => 'approval',
                    'config' => ['title' => 'Approve', 'timeout' => 'P3D'],
                    'on_approved' => 'b1',
                    'on_rejected' => null,
                    'on_timeout' => null,
                ],
                'b1' => ['type' => 'branch', 'revalidate_entity' => true, 'on_true' => 's1', 'on_false' => null],
                's1' => ['type' => 'stop'],
            ],
        ]);

        $this->assertSame([], $messages);
    }

    public function testEmptyDefinitionYieldsNoMessages(): void
    {
        $this->assertSame([], $this->check(['schema' => 1, 'steps' => [], 'entry' => null]));
    }

    public function testUnparseableDefinitionYieldsNoMessages(): void
    {
        $messages = (new GraphCheck())->check(
            new ValidationSubject('{not json'),
            new ValidationContext()
        );

        $this->assertSame([], $messages);
    }

    public function testSwitchFixtureGraphIsClean(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../../spec/fixtures/multi-region-order-routing.json'),
            true
        );

        $messages = $this->check($fixture['definition']);

        $this->assertSame([], $messages);
    }
}
