<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCatalog\Test\Unit\Stub;

use MageOS\WorkflowsTriggersCore\Service\EventPublisher;

/**
 * EventPublisher stand-in: records publishes, optionally throwing for a chosen
 * event name to exercise the observer's log-and-continue contract.
 */
class RecordingEventPublisher extends EventPublisher
{
    /** @var array<int, array{event: string, data: array}> */
    public array $published = [];

    public function __construct(private readonly ?string $throwsFor = null)
    {
    }

    public function publish(string $eventName, array $data): void
    {
        $this->published[] = ['event' => $eventName, 'data' => $data];
        if ($this->throwsFor !== null && $this->throwsFor === $eventName) {
            throw new \RuntimeException('amqp connection refused');
        }
    }

    /**
     * @return array<int, array{event: string, data: array}> published entries for one event name
     */
    public function only(string $eventName): array
    {
        return array_values(array_filter($this->published, static fn (array $p): bool => $p['event'] === $eventName));
    }

    /**
     * @return string[] published event names, in order
     */
    public function eventNames(): array
    {
        return array_map(static fn (array $p): string => $p['event'], $this->published);
    }
}
