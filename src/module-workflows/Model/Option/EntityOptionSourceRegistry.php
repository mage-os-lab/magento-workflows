<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Option;

/**
 * DI-registered map from an `entity:*` template-parameter type to an already
 * registered option-source code. A domain pack registers its aliases beside the
 * sources it contributes to {@see OptionSourcePool}, so `entity:salesrule`
 * resolves without the engine knowing any domain vocabulary.
 *
 * An entry marked `bounded` is small enough to render as a plain select rather
 * than a type-ahead: `min_chars` is forced to 0 so the client resolves the whole
 * list on an empty query. Unbounded entries default to 2 minimum characters.
 */
class EntityOptionSourceRegistry
{
    /** Minimum characters before a search-typed source is queried. */
    private const DEFAULT_MIN_CHARS = 2;

    /**
     * @param array<string, array{source: string, bounded?: bool, min_chars?: int}> $map
     *        key = entity alias WITHOUT the "entity:" prefix, e.g. "salesrule"
     */
    public function __construct(
        private readonly array $map = []
    ) {
        foreach ($this->map as $alias => $entry) {
            if (!is_array($entry) || !isset($entry['source']) || !is_string($entry['source'])
                || $entry['source'] === ''
            ) {
                throw new \InvalidArgumentException(
                    sprintf('Workflow entity option mapping "%s" must declare a non-empty "source"', $alias)
                );
            }
        }
    }

    /**
     * Whether the alias (no `entity:` prefix) maps to a source code.
     */
    public function has(string $entityType): bool
    {
        return isset($this->map[$entityType]);
    }

    /**
     * Option-source code the alias resolves to.
     */
    public function sourceCode(string $entityType): string
    {
        if (!isset($this->map[$entityType])) {
            throw new \InvalidArgumentException(sprintf('Unknown workflow entity option mapping "%s"', $entityType));
        }
        return (string) $this->map[$entityType]['source'];
    }

    /**
     * Bounded lists render as a plain select instead of a type-ahead.
     */
    public function isBounded(string $entityType): bool
    {
        return !empty($this->map[$entityType]['bounded']);
    }

    /**
     * Minimum characters before the client queries the source; always 0 for a
     * bounded list, so the full catalogue resolves on an empty query.
     */
    public function minChars(string $entityType): int
    {
        if ($this->isBounded($entityType)) {
            return 0;
        }
        return (int) ($this->map[$entityType]['min_chars'] ?? self::DEFAULT_MIN_CHARS);
    }
}
