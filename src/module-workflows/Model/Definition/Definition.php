<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Definition;

/**
 * Parsed, validated step-graph definition (docs/04-definition-format.md).
 * The single contract shared by the form UI, canvas, import/export, executor.
 *
 * Schema versions: v1 is the original action/delay/branch/stop set. v2 adds
 * the "wait" step (park until an event fires for the same entity, with a
 * timeout edge) and optional delay fields business_days / at. A v1 document
 * using v2 features is rejected — bump "schema" to 2 to use them.
 */
class Definition
{
    public const SCHEMA_VERSION = 2;
    public const SCHEMA_VERSIONS = [1, 2];

    public const STEP_ACTION = 'action';
    public const STEP_DELAY = 'delay';
    public const STEP_BRANCH = 'branch';
    public const STEP_STOP = 'stop';
    public const STEP_WAIT = 'wait';

    public const STEP_TYPES = [
        self::STEP_ACTION,
        self::STEP_DELAY,
        self::STEP_BRANCH,
        self::STEP_STOP,
        self::STEP_WAIT,
    ];

    /**
     * @param array<string, array> $steps step_key => step node
     */
    private function __construct(
        private readonly array $steps,
        private readonly ?string $entry,
        private readonly int $schema
    ) {
    }

    /**
     * @throws \InvalidArgumentException on malformed definitions
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Definition is not valid JSON: ' . $e->getMessage(), 0, $e);
        }
        return self::fromArray(is_array($data) ? $data : []);
    }

    /**
     * @throws \InvalidArgumentException on malformed definitions
     */
    public static function fromArray(array $data): self
    {
        $schema = $data['schema'] ?? null;
        if (!in_array($schema, self::SCHEMA_VERSIONS, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported definition schema "%s"', (string) $schema));
        }
        $steps = $data['steps'] ?? [];
        if (!is_array($steps)) {
            throw new \InvalidArgumentException('Definition "steps" must be an object');
        }
        foreach ($steps as $key => $step) {
            if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $key)) {
                throw new \InvalidArgumentException(sprintf('Invalid step key "%s"', (string) $key));
            }
            $type = $step['type'] ?? null;
            if (!in_array($type, self::STEP_TYPES, true)) {
                throw new \InvalidArgumentException(sprintf('Step "%s" has invalid type "%s"', $key, (string) $type));
            }
            foreach (['next', 'on_true', 'on_false', 'on_event', 'on_timeout'] as $edge) {
                $target = $step[$edge] ?? null;
                if ($target !== null && !isset($steps[$target])) {
                    throw new \InvalidArgumentException(
                        sprintf('Step "%s" edge "%s" points to unknown step "%s"', $key, $edge, (string) $target)
                    );
                }
            }
            if ($type === self::STEP_ACTION && !is_string($step['action'] ?? null)) {
                throw new \InvalidArgumentException(sprintf('Action step "%s" is missing "action"', $key));
            }
            if ($type === self::STEP_DELAY) {
                self::assertDuration($step['config']['duration'] ?? null, $key, 'config.duration');
                self::assertDelayExtras($step, $key, (int) $schema);
            }
            if ($type === self::STEP_WAIT) {
                if ($schema < 2) {
                    throw new \InvalidArgumentException(
                        sprintf('Step "%s": wait steps require definition schema 2', $key)
                    );
                }
                $event = $step['config']['event'] ?? null;
                if (!is_string($event) || $event === '' || !preg_match('/^[a-z0-9_.\-]{1,128}$/', $event)) {
                    throw new \InvalidArgumentException(
                        sprintf('Wait step "%s" is missing a valid config.event', $key)
                    );
                }
                self::assertDuration($step['config']['timeout'] ?? null, $key, 'config.timeout');
            }
        }
        $entry = $data['entry'] ?? null;
        if ($entry !== null && !isset($steps[$entry])) {
            throw new \InvalidArgumentException(sprintf('Entry step "%s" does not exist', (string) $entry));
        }
        if ($entry === null && $steps !== []) {
            throw new \InvalidArgumentException('Definition with steps must declare "entry"');
        }
        return new self($steps, $entry, (int) $schema);
    }

    /**
     * @throws \InvalidArgumentException when the value is not an ISO-8601 duration string
     */
    private static function assertDuration(mixed $duration, string $stepKey, string $field): void
    {
        if (!is_string($duration)) {
            throw new \InvalidArgumentException(sprintf('Step "%s" is missing %s', $stepKey, $field));
        }
        try {
            new \DateInterval($duration);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(
                sprintf('Step "%s" %s "%s" is not ISO-8601', $stepKey, $field, $duration),
                0,
                $e
            );
        }
    }

    /**
     * @throws \InvalidArgumentException on invalid v2 delay extras or v1 use of them
     */
    private static function assertDelayExtras(array $step, string $key, int $schema): void
    {
        $businessDays = $step['config']['business_days'] ?? null;
        $at = $step['config']['at'] ?? null;
        if ($businessDays === null && $at === null) {
            return;
        }
        if ($schema < 2) {
            throw new \InvalidArgumentException(
                sprintf('Delay step "%s": business_days / at require definition schema 2', $key)
            );
        }
        if ($businessDays !== null && !is_bool($businessDays)) {
            throw new \InvalidArgumentException(
                sprintf('Delay step "%s" config.business_days must be boolean', $key)
            );
        }
        if ($at !== null && (!is_string($at) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $at))) {
            throw new \InvalidArgumentException(
                sprintf('Delay step "%s" config.at must be "HH:MM" 24-hour time', $key)
            );
        }
    }

    public function getSchemaVersion(): int
    {
        return $this->schema;
    }

    public function getEntryKey(): ?string
    {
        return $this->entry;
    }

    public function hasStep(string $key): bool
    {
        return isset($this->steps[$key]);
    }

    public function getStep(string $key): array
    {
        if (!isset($this->steps[$key])) {
            throw new \InvalidArgumentException(sprintf('Unknown step "%s"', $key));
        }
        return $this->steps[$key];
    }

    /**
     * @return array<string, array>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /**
     * Action codes referenced anywhere in the graph (import validation, ACL re-authorization)
     *
     * @return string[]
     */
    public function getActionCodes(): array
    {
        $codes = [];
        foreach ($this->steps as $step) {
            if (($step['type'] ?? null) === self::STEP_ACTION) {
                $codes[] = (string) $step['action'];
            }
        }
        return array_values(array_unique($codes));
    }

    /**
     * Event names referenced by wait steps (subscription binding at save time)
     *
     * @return string[]
     */
    public function getWaitEvents(): array
    {
        $events = [];
        foreach ($this->steps as $step) {
            if (($step['type'] ?? null) === self::STEP_WAIT) {
                $events[] = (string) $step['config']['event'];
            }
        }
        return array_values(array_unique($events));
    }

    public function toArray(): array
    {
        return [
            'schema' => $this->schema,
            'steps' => $this->steps,
            'entry' => $this->entry,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
