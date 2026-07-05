<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Definition;

/**
 * Parsed, validated step-graph definition (docs/04-definition-format.md).
 * The single contract shared by the form UI, canvas, import/export, executor.
 *
 * Schema versions: v1 is the original action/delay/branch/stop set. v2 adds
 * the "wait" step (park until an event fires for the same entity, with a
 * timeout edge) and optional delay fields business_days / at. v3 adds the
 * "switch" step (first-match-wins multi-way branch with a default edge). v4
 * adds the "approval" step (a human-decision gate parked on the wait spine,
 * with on_approved / on_rejected / on_timeout edges and a required timeout).
 * A document using features of a later schema than it declares is rejected —
 * bump "schema" to use them.
 *
 * The optional top-level "ui" block (canvas layout persistence) is
 * non-semantic: it is preserved verbatim through fromArray()/toArray(),
 * legal at any schema version, and never read by the engine. It is the only
 * whitelisted non-semantic key — there is no general unknown-key passthrough.
 */
class Definition
{
    public const SCHEMA_VERSION = 4;
    public const SCHEMA_VERSIONS = [1, 2, 3, 4];

    public const STEP_ACTION = 'action';
    public const STEP_DELAY = 'delay';
    public const STEP_BRANCH = 'branch';
    public const STEP_STOP = 'stop';
    public const STEP_WAIT = 'wait';
    public const STEP_SWITCH = 'switch';
    public const STEP_APPROVAL = 'approval';

    public const STEP_TYPES = [
        self::STEP_ACTION,
        self::STEP_DELAY,
        self::STEP_BRANCH,
        self::STEP_STOP,
        self::STEP_WAIT,
        self::STEP_SWITCH,
        self::STEP_APPROVAL,
    ];

    /**
     * @param array<string, array> $steps step_key => step node
     * @param array|null $ui non-semantic canvas layout block, preserved verbatim
     */
    private function __construct(
        private readonly array $steps,
        private readonly ?string $entry,
        private readonly int $schema,
        private readonly ?array $ui = null
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
            foreach (['next', 'on_true', 'on_false', 'on_event', 'on_timeout', 'on_approved', 'on_rejected'] as $edge) {
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
            if ($type === self::STEP_SWITCH) {
                self::assertSwitchStep($step, $steps, $key, (int) $schema);
            }
            if ($type === self::STEP_APPROVAL) {
                self::assertApprovalStep($step, $key, (int) $schema);
            }
        }
        $entry = $data['entry'] ?? null;
        if ($entry !== null && !isset($steps[$entry])) {
            throw new \InvalidArgumentException(sprintf('Entry step "%s" does not exist', (string) $entry));
        }
        if ($entry === null && $steps !== []) {
            throw new \InvalidArgumentException('Definition with steps must declare "entry"');
        }
        $ui = $data['ui'] ?? null;
        if ($ui !== null && !is_array($ui)) {
            throw new \InvalidArgumentException('Definition "ui" must be an object when present');
        }
        return new self($steps, $entry, (int) $schema, $ui);
    }

    /**
     * Switch step (schema 3): first-match-wins cases, each reusing the
     * serialized condition-tree format, plus a nullable "default" edge and
     * one shared step-level revalidate_entity flag.
     *
     * @param array<string, array> $steps all steps, for edge-target validation
     * @throws \InvalidArgumentException on invalid switch shape or schema < 3
     */
    private static function assertSwitchStep(array $step, array $steps, string $key, int $schema): void
    {
        if ($schema < 3) {
            throw new \InvalidArgumentException(
                sprintf('Step "%s": switch steps require definition schema 3', $key)
            );
        }
        $cases = $step['cases'] ?? null;
        if (!is_array($cases) || $cases === [] || array_keys($cases) !== range(0, count($cases) - 1)) {
            throw new \InvalidArgumentException(
                sprintf('Switch step "%s" must declare a non-empty "cases" list', $key)
            );
        }
        $seenKeys = [];
        foreach ($cases as $index => $case) {
            if (!is_array($case)) {
                throw new \InvalidArgumentException(
                    sprintf('Switch step "%s" case #%d must be an object', $key, $index)
                );
            }
            $caseKey = $case['key'] ?? null;
            if (!is_string($caseKey) || !preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $caseKey)) {
                throw new \InvalidArgumentException(
                    sprintf('Switch step "%s" case #%d has an invalid "key"', $key, $index)
                );
            }
            if (isset($seenKeys[$caseKey])) {
                throw new \InvalidArgumentException(
                    sprintf('Switch step "%s" declares duplicate case key "%s"', $key, $caseKey)
                );
            }
            $seenKeys[$caseKey] = true;
            $conditions = $case['conditions_serialized'] ?? null;
            if ($conditions !== null && !is_string($conditions)) {
                throw new \InvalidArgumentException(
                    sprintf('Switch step "%s" case "%s" conditions_serialized must be a string', $key, $caseKey)
                );
            }
            $target = $case['next'] ?? null;
            if ($target !== null && !isset($steps[$target])) {
                throw new \InvalidArgumentException(sprintf(
                    'Switch step "%s" case "%s" points to unknown step "%s"',
                    $key,
                    $caseKey,
                    (string) $target
                ));
            }
        }
        $default = $step['default'] ?? null;
        if ($default !== null && !isset($steps[$default])) {
            throw new \InvalidArgumentException(sprintf(
                'Switch step "%s" default edge points to unknown step "%s"',
                $key,
                (string) $default
            ));
        }
        $revalidate = $step['revalidate_entity'] ?? null;
        if ($revalidate !== null && !is_bool($revalidate)) {
            throw new \InvalidArgumentException(
                sprintf('Switch step "%s" revalidate_entity must be boolean', $key)
            );
        }
    }

    /**
     * Approval step (schema 4): a human-decision gate parked on the wait spine.
     * config.title is the request label (interpolated at park time); timeout is
     * REQUIRED — a gate that never times out would leave an open task forever
     * (docs/discovery/approval-gate.md §4 "No indefinite parks"). Optional
     * payload_fields declare the value form the decider fills in; each entry's
     * key is unique and constrained so it can key context output safely.
     *
     * @throws \InvalidArgumentException on invalid approval shape or schema < 4
     */
    private static function assertApprovalStep(array $step, string $key, int $schema): void
    {
        if ($schema < 4) {
            throw new \InvalidArgumentException(
                sprintf('Step "%s": approval steps require definition schema 4', $key)
            );
        }
        $config = is_array($step['config'] ?? null) ? $step['config'] : [];

        $title = $config['title'] ?? null;
        if (!is_string($title) || $title === '') {
            throw new \InvalidArgumentException(
                sprintf('Approval step "%s" is missing a valid config.title', $key)
            );
        }
        $instructions = $config['instructions'] ?? null;
        if ($instructions !== null && !is_string($instructions)) {
            throw new \InvalidArgumentException(
                sprintf('Approval step "%s" config.instructions must be a string', $key)
            );
        }
        // Required: a gate must be able to resolve itself by the timeout clock.
        self::assertDuration($config['timeout'] ?? null, $key, 'config.timeout');

        $assigneeRole = $config['assignee_role'] ?? null;
        if ($assigneeRole !== null && (!is_string($assigneeRole) || $assigneeRole === '')) {
            throw new \InvalidArgumentException(
                sprintf('Approval step "%s" config.assignee_role must be a non-empty string', $key)
            );
        }
        $allowBulk = $config['allow_bulk'] ?? null;
        if ($allowBulk !== null && !is_bool($allowBulk)) {
            throw new \InvalidArgumentException(
                sprintf('Approval step "%s" config.allow_bulk must be boolean', $key)
            );
        }

        $payloadFields = $config['payload_fields'] ?? null;
        if ($payloadFields === null) {
            return;
        }
        if (!is_array($payloadFields) || $payloadFields === []
            || array_keys($payloadFields) !== range(0, count($payloadFields) - 1)
        ) {
            throw new \InvalidArgumentException(
                sprintf('Approval step "%s" config.payload_fields must be a non-empty list', $key)
            );
        }
        $seenKeys = [];
        foreach ($payloadFields as $index => $field) {
            if (!is_array($field)) {
                throw new \InvalidArgumentException(
                    sprintf('Approval step "%s" payload_fields #%d must be an object', $key, $index)
                );
            }
            $fieldKey = $field['key'] ?? null;
            if (!is_string($fieldKey) || !preg_match('/^[a-zA-Z0-9_]{1,64}$/', $fieldKey)) {
                throw new \InvalidArgumentException(
                    sprintf('Approval step "%s" payload_fields #%d has an invalid "key"', $key, $index)
                );
            }
            if (isset($seenKeys[$fieldKey])) {
                throw new \InvalidArgumentException(
                    sprintf('Approval step "%s" declares duplicate payload_fields key "%s"', $key, $fieldKey)
                );
            }
            $seenKeys[$fieldKey] = true;
            if (!is_string($field['label'] ?? null) || ($field['label'] ?? '') === '') {
                throw new \InvalidArgumentException(
                    sprintf('Approval step "%s" payload_fields "%s" is missing a "label"', $key, $fieldKey)
                );
            }
            if (!in_array($field['type'] ?? null, ['string', 'number', 'boolean'], true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Approval step "%s" payload_fields "%s" type must be one of string|number|boolean',
                    $key,
                    $fieldKey
                ));
            }
            $required = $field['required'] ?? null;
            if ($required !== null && !is_bool($required)) {
                throw new \InvalidArgumentException(sprintf(
                    'Approval step "%s" payload_fields "%s" required must be boolean',
                    $key,
                    $fieldKey
                ));
            }
        }
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
     * The step's full declared edge map, read from step data — the single
     * source of "what edges does this step have" (F1, docs/discovery/
     * implementation/00-foundations.md). Topology only: runtime *selection*
     * (which edge to follow) stays in each consumer.
     *
     *   action/delay => ['next' => …]
     *   branch       => ['on_true' => …, 'on_false' => …]
     *   wait         => ['on_event' => …, 'on_timeout' => …]
     *   switch       => ['case:<key>' => …, …, 'default' => …]
     *   approval     => ['on_approved' => …, 'on_rejected' => …, 'on_timeout' => …]
     *   stop         => []
     *
     * @return array<string, ?string> edge name => target step key or null
     * @throws \InvalidArgumentException on an unknown step key
     */
    public function getStepEdges(string $stepKey): array
    {
        $step = $this->getStep($stepKey);
        $edge = static fn (array $node, string $field): ?string =>
            isset($node[$field]) && $node[$field] !== null ? (string) $node[$field] : null;

        switch ($step['type'] ?? null) {
            case self::STEP_ACTION:
            case self::STEP_DELAY:
                return ['next' => $edge($step, 'next')];
            case self::STEP_BRANCH:
                return ['on_true' => $edge($step, 'on_true'), 'on_false' => $edge($step, 'on_false')];
            case self::STEP_WAIT:
                return ['on_event' => $edge($step, 'on_event'), 'on_timeout' => $edge($step, 'on_timeout')];
            case self::STEP_APPROVAL:
                return [
                    'on_approved' => $edge($step, 'on_approved'),
                    'on_rejected' => $edge($step, 'on_rejected'),
                    'on_timeout' => $edge($step, 'on_timeout'),
                ];
            case self::STEP_SWITCH:
                $edges = [];
                foreach ((array) ($step['cases'] ?? []) as $case) {
                    if (is_array($case) && isset($case['key'])) {
                        $edges['case:' . (string) $case['key']] = $edge($case, 'next');
                    }
                }
                $edges['default'] = $edge($step, 'default');
                return $edges;
            case self::STEP_STOP:
            default:
                return [];
        }
    }

    /**
     * Non-semantic canvas layout block; null when the document has none.
     * Never read by the engine — preserved for round-tripping only.
     */
    public function getUi(): ?array
    {
        return $this->ui;
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
        $data = [
            'schema' => $this->schema,
            'steps' => $this->steps,
            'entry' => $this->entry,
        ];
        if ($this->ui !== null) {
            $data['ui'] = $this->ui;
        }
        return $data;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
