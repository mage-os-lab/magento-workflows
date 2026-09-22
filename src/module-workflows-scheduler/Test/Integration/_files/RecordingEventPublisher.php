<?php
/**
 * Test-only recording double for the trigger layer's EventPublisher
 * (docs/20-integration-test-plan.md §6, suite #24b/#24c). The scheduler's
 * detectors resolve the publisher by class name through the object manager
 * (soft dependency); wiring this preference in lets a test assert the exact
 * async-event payload a detector publishes without touching the real
 * async-events transport, and keeps the detector's flag/dedupe logic intact.
 *
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Integration\_files;

use MageOS\WorkflowsTriggersCore\Service\EventPublisher;

class RecordingEventPublisher extends EventPublisher
{
    /**
     * @var array<int, array{event: string, data: array<string, mixed>}>
     */
    private array $published = [];

    /**
     * @SuppressWarnings(PHPMD.MissingParentConstructorCall)
     */
    public function __construct()
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function publish(string $eventName, array $data): void
    {
        $this->published[] = ['event' => $eventName, 'data' => $data];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function payloadsFor(string $eventName): array
    {
        $rows = [];
        foreach ($this->published as $record) {
            if ($record['event'] === $eventName) {
                $rows[] = $record['data'];
            }
        }
        return $rows;
    }

    public function count(): int
    {
        return count($this->published);
    }
}
