<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Definition;

use MageOS\Workflows\Model\Definition\Definition;
use PHPUnit\Framework\TestCase;

class DefinitionTest extends TestCase
{
    private function loadFraudCheckFixture(): array
    {
        $path = dirname(__DIR__, 6) . '/spec/fixtures/high-value-order-fraud-check.json';
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        return $decoded['definition'];
    }

    public function testValidGraphParsesFromFixture(): void
    {
        $definition = Definition::fromArray($this->loadFraudCheckFixture());

        $this->assertSame('s1', $definition->getEntryKey());
        $this->assertTrue($definition->hasStep('s1'));
        $this->assertTrue($definition->hasStep('s2'));
        $this->assertTrue($definition->hasStep('s3'));
        $this->assertTrue($definition->hasStep('s4'));
        $this->assertFalse($definition->hasStep('does-not-exist'));
        $this->assertCount(4, $definition->getSteps());
        $this->assertSame('action', $definition->getStep('s1')['type']);
    }

    public function testUnknownSchemaRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Definition::fromArray([
            'schema' => 99,
            'entry' => 's1',
            'steps' => [
                's1' => ['type' => Definition::STEP_STOP],
            ],
        ]);
    }

    public function testDanglingEdgeRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Definition::fromArray([
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => ['type' => Definition::STEP_STOP, 'next' => 'ghost-step'],
            ],
        ]);
    }

    public function testInvalidStepKeyRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Definition::fromArray([
            'schema' => 1,
            'entry' => 'bad key!',
            'steps' => [
                'bad key!' => ['type' => Definition::STEP_STOP],
            ],
        ]);
    }

    public function testDelayWithoutDurationRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Definition::fromArray([
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => ['type' => Definition::STEP_DELAY, 'config' => []],
            ],
        ]);
    }

    public function testBadIso8601DurationRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Definition::fromArray([
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => ['type' => Definition::STEP_DELAY, 'config' => ['duration' => 'not-a-duration']],
            ],
        ]);
    }

    public function testMissingEntryRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Definition::fromArray([
            'schema' => 1,
            'steps' => [
                's1' => ['type' => Definition::STEP_STOP],
            ],
        ]);
    }

    public function testActionStepMissingActionRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Definition::fromArray([
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => ['type' => Definition::STEP_ACTION],
            ],
        ]);
    }

    public function testGetActionCodesDedupes(): void
    {
        $definition = Definition::fromArray([
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => ['type' => Definition::STEP_ACTION, 'action' => 'order.add_comment', 'next' => 's2'],
                's2' => ['type' => Definition::STEP_ACTION, 'action' => 'order.add_comment', 'next' => 's3'],
                's3' => ['type' => Definition::STEP_ACTION, 'action' => 'notify.webhook', 'next' => null],
            ],
        ]);

        $codes = $definition->getActionCodes();
        sort($codes);

        $this->assertCount(2, $codes);
        $this->assertSame(['notify.webhook', 'order.add_comment'], $codes);
    }

    public function testToJsonFromJsonRoundTrip(): void
    {
        $original = Definition::fromArray($this->loadFraudCheckFixture());
        $json = $original->toJson();

        $roundTripped = Definition::fromJson($json);

        $this->assertSame($original->getEntryKey(), $roundTripped->getEntryKey());
        $this->assertEquals($original->getSteps(), $roundTripped->getSteps());
        $this->assertEquals($original->getActionCodes(), $roundTripped->getActionCodes());
        $this->assertEquals($original->toArray(), $roundTripped->toArray());
    }

    public function testFromJsonRejectsInvalidJson(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Definition::fromJson('{not valid json');
    }
}
