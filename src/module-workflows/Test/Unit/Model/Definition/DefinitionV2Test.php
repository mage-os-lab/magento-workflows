<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Definition;

use MageOS\Workflows\Model\Definition\Definition;
use PHPUnit\Framework\TestCase;

class DefinitionV2Test extends TestCase
{
    public function testSchemaTwoAcceptedAndNormalized(): void
    {
        $definition = Definition::fromArray([
            'schema' => 2,
            'entry' => 's1',
            'steps' => [
                's1' => ['type' => Definition::STEP_STOP],
            ],
        ]);

        $this->assertSame(Definition::SCHEMA_VERSION, $definition->getSchemaVersion());
    }

    public function testSchemaOneAcceptedAndNormalized(): void
    {
        $definition = Definition::fromArray([
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => ['type' => Definition::STEP_STOP],
            ],
        ]);

        $this->assertSame(Definition::SCHEMA_VERSION, $definition->getSchemaVersion());
        $this->assertSame(Definition::SCHEMA_VERSION, $definition->toArray()['schema']);
    }

    public function testUnsupportedFutureSchemaRejected(): void
    {
        // Schema 4 became valid with the approval step; the next unreleased
        // version is still rejected as an unsupported schema.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported definition schema');

        Definition::fromArray([
            'schema' => 5,
            'entry' => 's1',
            'steps' => [
                's1' => ['type' => Definition::STEP_STOP],
            ],
        ]);
    }

    public function testWaitStepValidUnderSchema2(): void
    {
        $definition = Definition::fromArray([
            'schema' => 2,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => Definition::STEP_WAIT,
                    'config' => [
                        'event' => 'sales.order.updated',
                        'timeout' => 'PT1H',
                    ],
                    'on_event' => 's2',
                    'on_timeout' => 's3',
                ],
                's2' => ['type' => Definition::STEP_STOP],
                's3' => ['type' => Definition::STEP_STOP],
            ],
        ]);

        $this->assertTrue($definition->hasStep('s1'));
        $step = $definition->getStep('s1');
        $this->assertSame(Definition::STEP_WAIT, $step['type']);
    }

    public function testWaitStepAcceptedUnderLegacySchema1(): void
    {
        // Step types are no longer version-gated: a legacy schema number is
        // normalized to the current version on parse, so every step type is
        // legal under any accepted schema number.
        $definition = Definition::fromArray([
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => Definition::STEP_WAIT,
                    'config' => [
                        'event' => 'sales.order.updated',
                        'timeout' => 'PT1H',
                    ],
                    'on_event' => 's2',
                    'on_timeout' => 's3',
                ],
                's2' => ['type' => Definition::STEP_STOP],
                's3' => ['type' => Definition::STEP_STOP],
            ],
        ]);

        $this->assertSame(Definition::STEP_WAIT, $definition->getStep('s1')['type']);
        $this->assertSame(Definition::SCHEMA_VERSION, $definition->getSchemaVersion());
    }

    public function testWaitMissingConfigEvent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Wait step "s1" is missing a valid config.event');

        Definition::fromArray([
            'schema' => 2,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => Definition::STEP_WAIT,
                    'config' => [
                        'timeout' => 'PT1H',
                    ],
                    'on_event' => 's2',
                    'on_timeout' => 's3',
                ],
                's2' => ['type' => Definition::STEP_STOP],
                's3' => ['type' => Definition::STEP_STOP],
            ],
        ]);
    }

    public function testWaitEmptyEvent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Wait step "s1" is missing a valid config.event');

        Definition::fromArray([
            'schema' => 2,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => Definition::STEP_WAIT,
                    'config' => [
                        'event' => '',
                        'timeout' => 'PT1H',
                    ],
                    'on_event' => 's2',
                    'on_timeout' => 's3',
                ],
                's2' => ['type' => Definition::STEP_STOP],
                's3' => ['type' => Definition::STEP_STOP],
            ],
        ]);
    }

    public function testWaitEventWithInvalidCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Wait step "s1" is missing a valid config.event');

        Definition::fromArray([
            'schema' => 2,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => Definition::STEP_WAIT,
                    'config' => [
                        'event' => 'SALES.ORDER.UPDATED',
                        'timeout' => 'PT1H',
                    ],
                    'on_event' => 's2',
                    'on_timeout' => 's3',
                ],
                's2' => ['type' => Definition::STEP_STOP],
                's3' => ['type' => Definition::STEP_STOP],
            ],
        ]);
    }

    public function testWaitMissingConfigTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing config.timeout');

        Definition::fromArray([
            'schema' => 2,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => Definition::STEP_WAIT,
                    'config' => [
                        'event' => 'sales.order.updated',
                    ],
                    'on_event' => 's2',
                    'on_timeout' => 's3',
                ],
                's2' => ['type' => Definition::STEP_STOP],
                's3' => ['type' => Definition::STEP_STOP],
            ],
        ]);
    }

    public function testWaitInvalidTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('config.timeout');

        Definition::fromArray([
            'schema' => 2,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => Definition::STEP_WAIT,
                    'config' => [
                        'event' => 'sales.order.updated',
                        'timeout' => 'not-iso8601',
                    ],
                    'on_event' => 's2',
                    'on_timeout' => 's3',
                ],
                's2' => ['type' => Definition::STEP_STOP],
                's3' => ['type' => Definition::STEP_STOP],
            ],
        ]);
    }

    public function testDelayBusinessDaysAcceptedUnderLegacySchema1(): void
    {
        $definition = Definition::fromArray([
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => Definition::STEP_DELAY,
                    'config' => [
                        'duration' => 'P2D',
                        'business_days' => true,
                    ],
                ],
            ],
        ]);

        $this->assertTrue($definition->hasStep('s1'));
    }

    public function testDelayAtAcceptedUnderLegacySchema1(): void
    {
        $definition = Definition::fromArray([
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => Definition::STEP_DELAY,
                    'config' => [
                        'duration' => 'PT1H',
                        'at' => '09:00',
                    ],
                ],
            ],
        ]);

        $this->assertTrue($definition->hasStep('s1'));
    }

    public function testDelayBusinessDaysValidUnderSchema2(): void
    {
        $definition = Definition::fromArray([
            'schema' => 2,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => Definition::STEP_DELAY,
                    'config' => [
                        'duration' => 'P2D',
                        'business_days' => true,
                    ],
                ],
            ],
        ]);

        $this->assertTrue($definition->hasStep('s1'));
    }

    public function testDelayAtValidUnderSchema2(): void
    {
        $definition = Definition::fromArray([
            'schema' => 2,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => Definition::STEP_DELAY,
                    'config' => [
                        'duration' => 'PT1H',
                        'at' => '09:00',
                    ],
                ],
            ],
        ]);

        $this->assertTrue($definition->hasStep('s1'));
    }

    public function testDelayAtBadFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('config.at must be "HH:MM"');

        Definition::fromArray([
            'schema' => 2,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => Definition::STEP_DELAY,
                    'config' => [
                        'duration' => 'PT1H',
                        'at' => '9:00',
                    ],
                ],
            ],
        ]);
    }

    public function testDelayAt24HourInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('config.at must be "HH:MM"');

        Definition::fromArray([
            'schema' => 2,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => Definition::STEP_DELAY,
                    'config' => [
                        'duration' => 'PT1H',
                        'at' => '24:00',
                    ],
                ],
            ],
        ]);
    }

    public function testDelayBusinessDaysNonBoolean(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('config.business_days must be boolean');

        Definition::fromArray([
            'schema' => 2,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => Definition::STEP_DELAY,
                    'config' => [
                        'duration' => 'P2D',
                        'business_days' => 'yes',
                    ],
                ],
            ],
        ]);
    }

    public function testGetWaitEventsReturnsUniqueList(): void
    {
        $definition = Definition::fromArray([
            'schema' => 2,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => Definition::STEP_WAIT,
                    'config' => [
                        'event' => 'sales.order.updated',
                        'timeout' => 'PT1H',
                    ],
                    'on_event' => 's2',
                    'on_timeout' => 's3',
                ],
                's2' => [
                    'type' => Definition::STEP_WAIT,
                    'config' => [
                        'event' => 'sales.order.updated',
                        'timeout' => 'PT2H',
                    ],
                    'on_event' => 's4',
                    'on_timeout' => 's3',
                ],
                's3' => [
                    'type' => Definition::STEP_WAIT,
                    'config' => [
                        'event' => 'order.shipped',
                        'timeout' => 'PT1H',
                    ],
                    'on_event' => 's4',
                    'on_timeout' => 's4',
                ],
                's4' => ['type' => Definition::STEP_STOP],
            ],
        ]);

        $events = $definition->getWaitEvents();
        sort($events);

        $this->assertCount(2, $events);
        $this->assertSame(['order.shipped', 'sales.order.updated'], $events);
    }

    public function testGetWaitEventsEmptyWhenNoWaitSteps(): void
    {
        $definition = Definition::fromArray([
            'schema' => 2,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => Definition::STEP_DELAY,
                    'config' => ['duration' => 'PT1H'],
                    'next' => 's2',
                ],
                's2' => ['type' => Definition::STEP_STOP],
            ],
        ]);

        $events = $definition->getWaitEvents();

        $this->assertCount(0, $events);
    }

    public function testToArrayNormalizesLegacySchema2(): void
    {
        $original = [
            'schema' => 2,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => Definition::STEP_WAIT,
                    'config' => [
                        'event' => 'sales.order.updated',
                        'timeout' => 'PT1H',
                    ],
                    'on_event' => 's2',
                    'on_timeout' => 's3',
                ],
                's2' => ['type' => Definition::STEP_STOP],
                's3' => ['type' => Definition::STEP_STOP],
            ],
        ];

        $definition = Definition::fromArray($original);
        $asArray = $definition->toArray();

        $this->assertSame(Definition::SCHEMA_VERSION, $asArray['schema']);
    }

    public function testRoundTripSchema2ViaFromArrayToArray(): void
    {
        $original = [
            'schema' => 2,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => Definition::STEP_DELAY,
                    'config' => [
                        'duration' => 'P2D',
                        'business_days' => true,
                        'at' => '09:00',
                    ],
                    'next' => 's2',
                ],
                's2' => [
                    'type' => Definition::STEP_WAIT,
                    'config' => [
                        'event' => 'sales.order.updated',
                        'timeout' => 'PT1H',
                    ],
                    'on_event' => 's3',
                    'on_timeout' => 's4',
                ],
                's3' => ['type' => Definition::STEP_STOP],
                's4' => ['type' => Definition::STEP_STOP],
            ],
        ];

        $definition = Definition::fromArray($original);
        $roundTripped = Definition::fromArray($definition->toArray());

        $this->assertSame(Definition::SCHEMA_VERSION, $roundTripped->getSchemaVersion());
        $this->assertCount(4, $roundTripped->getSteps());
        $this->assertSame('s1', $roundTripped->getEntryKey());
    }

    public function testWaitOnEventEdgeToUnknownStepRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('points to unknown step');

        Definition::fromArray([
            'schema' => 2,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => Definition::STEP_WAIT,
                    'config' => [
                        'event' => 'sales.order.updated',
                        'timeout' => 'PT1H',
                    ],
                    'on_event' => 'ghost-step',
                    'on_timeout' => 's2',
                ],
                's2' => ['type' => Definition::STEP_STOP],
            ],
        ]);
    }

    public function testWaitOnTimeoutEdgeToUnknownStepRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('points to unknown step');

        Definition::fromArray([
            'schema' => 2,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => Definition::STEP_WAIT,
                    'config' => [
                        'event' => 'sales.order.updated',
                        'timeout' => 'PT1H',
                    ],
                    'on_event' => 's2',
                    'on_timeout' => 'ghost-step',
                ],
                's2' => ['type' => Definition::STEP_STOP],
            ],
        ]);
    }

    public function testDelayWithAtValidFormat(): void
    {
        // Test all valid time formats: 00:00 to 23:59
        $definition = Definition::fromArray([
            'schema' => 2,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => Definition::STEP_DELAY,
                    'config' => [
                        'duration' => 'PT1H',
                        'at' => '23:59',
                    ],
                ],
            ],
        ]);

        $step = $definition->getStep('s1');
        $this->assertSame('23:59', $step['config']['at']);
    }

    public function testDelayWithAtMissingMinutes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('config.at must be "HH:MM"');

        Definition::fromArray([
            'schema' => 2,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => Definition::STEP_DELAY,
                    'config' => [
                        'duration' => 'PT1H',
                        'at' => '09',
                    ],
                ],
            ],
        ]);
    }

    public function testGetWaitEventsSingleEvent(): void
    {
        $definition = Definition::fromArray([
            'schema' => 2,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => Definition::STEP_WAIT,
                    'config' => [
                        'event' => 'customer.created',
                        'timeout' => 'PT30M',
                    ],
                    'on_event' => 's2',
                    'on_timeout' => 's2',
                ],
                's2' => ['type' => Definition::STEP_STOP],
            ],
        ]);

        $events = $definition->getWaitEvents();

        $this->assertCount(1, $events);
        $this->assertSame('customer.created', $events[0]);
    }
}
