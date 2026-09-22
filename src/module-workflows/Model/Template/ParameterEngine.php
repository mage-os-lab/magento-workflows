<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Template;

use Magento\Framework\Exception\LocalizedException;
use MageOS\Workflows\Model\Option\EntityOptionSourceRegistry;
use MageOS\Workflows\Model\Option\OptionSourcePool;

/**
 * Install-time %param.<key>% token substitution over a template's workflow body
 * (discovery §2/§4). Deliberately distinct from the runtime {{ … }} syntax so
 * authoring can't be confused with — or smuggle values into — runtime
 * interpolation, and a leftover token is a hard error before any write rather
 * than a silently-empty runtime variable.
 *
 * Contract, all failing before the caller persists anything:
 *  - a provided value whose key is not a declared parameter → error;
 *  - a required parameter with neither a value nor a default → error;
 *  - a `select`/`options` value outside the declared option set → error;
 *  - a `number` value that is non-numeric or outside a declared min/max → error;
 *  - a `duration` value that is not an ISO-8601 interval → error;
 *  - a `url` value that is not a valid http(s) URL → error;
 *  - an `entity:*` value with no matching record in the mapped option source →
 *    error;
 *  - any %param.*% token still present after substitution → error.
 *
 * The typed checks cover authored defaults as well as supplied values, and are
 * skipped wholesale when a caller passes `$validateTypes: false` (admin preview,
 * which renders a body before the operator has finished the form). Entity
 * existence checks additionally no-op when no option-source pool / registry is
 * wired (bare construction in unit tests), when the alias is unregistered, or
 * when the mapped source is absent because its domain pack is not installed —
 * CompatibilityChecker reports a missing pack separately.
 */
class ParameterEngine
{
    /** Matches a single substitution token, capturing the parameter key. */
    private const TOKEN_PATTERN = '/%param\.([a-z0-9_]+)%/';

    /** Prefix marking a parameter type that names an entity alias. */
    private const ENTITY_TYPE_PREFIX = 'entity:';

    /**
     * Both dependencies default to null so the engine stays constructible
     * without a container; null disables entity existence checks only.
     */
    public function __construct(
        private readonly ?OptionSourcePool $optionSourcePool = null,
        private readonly ?EntityOptionSourceRegistry $entityRegistry = null
    ) {
    }

    /**
     * Apply the parameter values to the workflow body, returning the
     * substituted node. Never mutates its inputs.
     *
     * @param array<string, mixed> $workflow the template's `workflow` node
     * @param array<int, array<string, mixed>> $parameterDefs the `parameters` list
     * @param array<string, mixed> $values key => value (form / CLI / patch)
     * @param bool $validateTypes false skips the typed checks (preview only)
     * @return array<string, mixed> the substituted workflow node
     * @throws LocalizedException
     */
    public function apply(array $workflow, array $parameterDefs, array $values, bool $validateTypes = true): array
    {
        $resolved = $this->resolveValues($parameterDefs, $values, $validateTypes);
        $substituted = $this->substitute($workflow, $resolved);
        $this->assertNoLeftoverTokens($substituted);

        return $substituted;
    }

    /**
     * Merge declared defaults with supplied values, enforcing the required/
     * unknown/option constraints. Returns a flat key => string map ready for
     * token replacement.
     *
     * @param array<int, array<string, mixed>> $parameterDefs
     * @param array<string, mixed> $values
     * @return array<string, string>
     * @throws LocalizedException
     */
    private function resolveValues(array $parameterDefs, array $values, bool $validateTypes = true): array
    {
        $defsByKey = [];
        foreach ($parameterDefs as $def) {
            $key = (string) ($def['key'] ?? '');
            if ($key !== '') {
                $defsByKey[$key] = $def;
            }
        }

        $unknown = array_diff(array_keys($values), array_keys($defsByKey));
        if ($unknown !== []) {
            throw new LocalizedException(__(
                'Unknown template parameter(s): %1.',
                implode(', ', $unknown)
            ));
        }

        $resolved = [];
        foreach ($defsByKey as $key => $def) {
            $hasValue = array_key_exists($key, $values) && $values[$key] !== null && $values[$key] !== '';
            $value = $hasValue ? $values[$key] : ($def['default'] ?? null);

            if ($value === null || $value === '') {
                if ($this->isRequired($def)) {
                    throw new LocalizedException(__('The template parameter "%1" is required.', $key));
                }
                $value = '';
            }

            $value = $this->scalarString($key, $value);
            $this->assertOption($key, $def, $value);
            if ($validateTypes && $value !== '') {
                $this->assertTypedValue($key, $def, $value);
            }
            $resolved[$key] = $value;
        }

        return $resolved;
    }

    /**
     * Parameters are optional unless they declare `required: true`.
     *
     * @param array<string, mixed> $def
     */
    private function isRequired(array $def): bool
    {
        return !empty($def['required']);
    }

    /**
     * @param array<string, mixed> $def
     * @throws LocalizedException
     */
    private function assertOption(string $key, array $def, string $value): void
    {
        $options = $def['options'] ?? null;
        if (!is_array($options) || $options === [] || $value === '') {
            return;
        }
        $allowed = array_map(
            static fn ($o): string => is_array($o) ? (string) ($o['value'] ?? '') : '',
            $options
        );
        if (!in_array($value, $allowed, true)) {
            throw new LocalizedException(__(
                'The value "%1" is not a valid option for template parameter "%2".',
                $value,
                $key
            ));
        }
    }

    /**
     * Type-specific validation of a non-empty resolved value (supplied or
     * defaulted). Every value is a string by contract, so each branch parses
     * the string form; unrecognised types validate nothing.
     *
     * @param array<string, mixed> $def
     * @throws LocalizedException
     */
    private function assertTypedValue(string $key, array $def, string $value): void
    {
        $type = (string) ($def['type'] ?? '');

        if ($type === 'number') {
            $this->assertNumber($key, $def, $value);
            return;
        }

        if ($type === 'duration') {
            $this->assertDuration($key, $value);
            return;
        }

        if ($type === 'url') {
            $this->assertUrl($key, $value);
            return;
        }

        if (str_starts_with($type, self::ENTITY_TYPE_PREFIX)) {
            $this->assertEntityValue($key, $def, $value, substr($type, strlen(self::ENTITY_TYPE_PREFIX)));
        }
    }

    /**
     * `min` / `max` are validated server-side; `step` is a client-only hint.
     *
     * @param array<string, mixed> $def
     * @throws LocalizedException
     */
    private function assertNumber(string $key, array $def, string $value): void
    {
        if (!is_numeric($value)) {
            throw new LocalizedException(__('The template parameter "%1" must be a number.', $key));
        }

        $number = (float) $value;
        if (isset($def['min']) && is_numeric($def['min']) && $number < (float) $def['min']) {
            throw new LocalizedException(__(
                'The template parameter "%1" must be at least %2.',
                $key,
                $def['min']
            ));
        }
        if (isset($def['max']) && is_numeric($def['max']) && $number > (float) $def['max']) {
            throw new LocalizedException(__(
                'The template parameter "%1" must be at most %2.',
                $key,
                $def['max']
            ));
        }
    }

    /**
     * @throws LocalizedException
     */
    private function assertDuration(string $key, string $value): void
    {
        try {
            new \DateInterval($value);
        } catch (\Exception) {
            throw new LocalizedException(__(
                'The template parameter "%1" must be an ISO-8601 duration such as "P1D" or "PT4H".',
                $key
            ));
        }
    }

    /**
     * Scheme is restricted to http(s): a template parameter feeds outbound
     * calls and admin links, never a javascript:/data: payload.
     *
     * @throws LocalizedException
     */
    private function assertUrl(string $key, string $value): void
    {
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        if (filter_var($value, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) {
            throw new LocalizedException(__('The template parameter "%1" must be a valid http(s) URL.', $key));
        }
    }

    /**
     * Existence check for an `entity:<alias>` value against the option source
     * the alias maps to. Silently skipped when inline `options` override the
     * mapping (already enforced by assertOption), when no pool/registry is
     * wired, when the alias is unregistered, or when the mapped source is not
     * installed — a missing domain pack is CompatibilityChecker's report.
     *
     * @param array<string, mixed> $def
     * @throws LocalizedException
     */
    private function assertEntityValue(string $key, array $def, string $value, string $alias): void
    {
        $inlineOptions = $def['options'] ?? null;
        if (is_array($inlineOptions) && $inlineOptions !== []) {
            return;
        }
        if ($this->optionSourcePool === null || $this->entityRegistry === null) {
            return;
        }
        if (!$this->entityRegistry->has($alias)) {
            return;
        }

        $code = $this->entityRegistry->sourceCode($alias);
        if (!$this->optionSourcePool->has($code)) {
            return;
        }

        if (!$this->optionSourcePool->get($code)->hasValue($value)) {
            throw new LocalizedException(__(
                'The value "%1" for template parameter "%2" does not match any existing record.',
                $value,
                $key
            ));
        }
    }

    /**
     * @throws LocalizedException
     */
    private function scalarString(string $key, mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        throw new LocalizedException(__(
            'The template parameter "%1" must be a scalar value.',
            $key
        ));
    }

    /**
     * Recursively replace %param.*% tokens in every string leaf. Whole-value
     * tokens and embedded tokens are both handled; unresolved keys are left in
     * place so assertNoLeftoverTokens can report them.
     *
     * @param array<string, string> $resolved
     */
    private function substitute(mixed $node, array $resolved): mixed
    {
        if (is_array($node)) {
            $out = [];
            foreach ($node as $key => $child) {
                $out[$key] = $this->substitute($child, $resolved);
            }
            return $out;
        }
        if (is_string($node)) {
            return preg_replace_callback(
                self::TOKEN_PATTERN,
                static fn (array $m): string => $resolved[$m[1]] ?? $m[0],
                $node
            );
        }
        return $node;
    }

    /**
     * @param array<string, mixed> $workflow
     * @throws LocalizedException
     */
    private function assertNoLeftoverTokens(array $workflow): void
    {
        $encoded = (string) json_encode($workflow);
        if (preg_match_all(self::TOKEN_PATTERN, $encoded, $matches)) {
            $keys = array_values(array_unique($matches[1]));
            throw new LocalizedException(__(
                'Unresolved template token(s) after substitution: %1. Every %%param.*%% token must map to a declared parameter.',
                implode(', ', $keys)
            ));
        }
    }
}
