<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Template;

use Magento\Framework\Exception\LocalizedException;

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
 *  - any %param.*% token still present after substitution → error.
 */
class ParameterEngine
{
    /** Matches a single substitution token, capturing the parameter key. */
    private const TOKEN_PATTERN = '/%param\.([a-z0-9_]+)%/';

    /**
     * Apply the parameter values to the workflow body, returning the
     * substituted node. Never mutates its inputs.
     *
     * @param array<string, mixed> $workflow the template's `workflow` node
     * @param array<int, array<string, mixed>> $parameterDefs the `parameters` list
     * @param array<string, mixed> $values key => value (form / CLI / patch)
     * @return array<string, mixed> the substituted workflow node
     * @throws LocalizedException
     */
    public function apply(array $workflow, array $parameterDefs, array $values): array
    {
        $resolved = $this->resolveValues($parameterDefs, $values);
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
    private function resolveValues(array $parameterDefs, array $values): array
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
            $resolved[$key] = $value;
        }

        return $resolved;
    }

    /**
     * A parameter is required unless it declares itself optional. Both flags
     * exist in the schema; `required: true` forces it, `optional: true` (or the
     * absence of `required`) makes it optional.
     *
     * @param array<string, mixed> $def
     */
    private function isRequired(array $def): bool
    {
        if (!empty($def['required'])) {
            return true;
        }
        if (!empty($def['optional'])) {
            return false;
        }
        return false;
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
