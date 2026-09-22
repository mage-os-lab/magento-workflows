<?php
/**
 * Test-only recording double for the gap-fill publisher seam
 * (docs/20-integration-test-plan.md §6, suite #22). Extends the production
 * EventPublisher but overrides its constructor so it needs no real
 * async-events EventDispatcher, and records every publish() call instead of
 * dispatching. Wired in via an object-manager preference under
 *
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 *
 * @magentoAppIsolation so observers reached through the real merged
 * events.xml deliver here — letting the test assert the event name + payload
 * shape the observer produced, without touching the async-events transport.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Test\Integration\_files;

use MageOS\WorkflowsTriggersCore\Service\EventPublisher;

class RecordingEventPublisher extends EventPublisher
{
    /**
     * @var array<int, array{event: string, data: array<string, mixed>}>
     */
    private array $published = [];

    /**
     * Intentionally does NOT call parent::__construct(): the production
     * constructor requires the real async-events EventDispatcher, which this
     * double replaces entirely.
     *
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
     * @return array<int, array{event: string, data: array<string, mixed>}>
     */
    public function getPublished(): array
    {
        return $this->published;
    }

    /**
     * All payloads published under the given event name.
     *
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

    public function reset(): void
    {
        $this->published = [];
    }
}
