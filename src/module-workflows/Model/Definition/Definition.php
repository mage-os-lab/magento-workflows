<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Definition;

/**
 * Parsed, validated step-graph definition (docs/04-definition-format.md).
 * The single contract shared by the form UI, canvas, import/export, executor.
 */
class Definition
{
    public const SCHEMA_VERSION = 1;

    public const STEP_ACTION = 'action';
    public const STEP_DELAY = 'delay';
    public const STEP_BRANCH = 'branch';
    public const STEP_STOP = 'stop';

    public const STEP_TYPES = [self::STEP_ACTION, self::STEP_DELAY, self::STEP_BRANCH, self::STEP_STOP];

    /**
     * @param array<string, array> $steps step_key => step node
     */
    private function __construct(
        private readonly array $steps,
        private readonly ?string $entry
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
        if ($schema !== self::SCHEMA_VERSION) {
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
            foreach (['next', 'on_true', 'on_false'] as $edge) {
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
                $duration = $step['config']['duration'] ?? null;
                if (!is_string($duration)) {
                    throw new \InvalidArgumentException(sprintf('Delay step "%s" is missing config.duration', $key));
                }
                try {
                    new \DateInterval($duration);
                } catch (\Exception $e) {
                    throw new \InvalidArgumentException(
                        sprintf('Delay step "%s" duration "%s" is not ISO-8601', $key, $duration),
                        0,
                        $e
                    );
                }
            }
        }
        $entry = $data['entry'] ?? null;
        if ($entry !== null && !isset($steps[$entry])) {
            throw new \InvalidArgumentException(sprintf('Entry step "%s" does not exist', (string) $entry));
        }
        if ($entry === null && $steps !== []) {
            throw new \InvalidArgumentException('Definition with steps must declare "entry"');
        }
        return new self($steps, $entry);
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

    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA_VERSION,
            'steps' => $this->steps,
            'entry' => $this->entry,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
