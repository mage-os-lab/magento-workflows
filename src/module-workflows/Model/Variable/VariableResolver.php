<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Variable;

use MageOS\Workflows\Model\Execution\ExecutionContext;

/**
 * Restricted mustache-style resolver over the context bag.
 *
 * Deliberately NOT Magento\Framework\Filter\Template: no directive execution,
 * no method calls, dot-path array access only. Allowed roots: trigger, steps,
 * workflow, secrets. Unknown paths resolve to empty string. Secrets resolve
 * via SecretsProviderInterface and are never echoed back into logs by callers
 * (redaction happens at the logging layer by value registration).
 *
 * Formatters (whitelisted, pure — still no code execution):
 *   {{ trigger.customer_email|lower }}
 *   {{ trigger.grand_total|number:2 }}
 *   {{ trigger.created_at|date:'M j, Y' }}
 *   {{ trigger.coupon_code|default:'none' }}
 * Chainable left to right. Unknown filters are ignored; a filter that cannot
 * apply (e.g. number on a non-numeric) leaves the value unchanged.
 *
 * Collection formatters (batch aggregation, 05) operate over a list value
 * (e.g. {{ trigger.items }}):
 *   {{ trigger.items|count }}                 -> "143"
 *   {{ trigger.items|pluck:'sku'|join:', ' }} -> "A, B, C"
 *   {{ trigger.items|table:'sku,qty' }}       -> HTML table (cells escaped)
 *   {{ trigger.items|json }}                  -> the JSON array
 * The pipeline runs on the RAW value: an array is kept intact when the first
 * filter is collection-typed, then stringified after. Every other value keeps
 * the legacy behaviour (arrays with no collection filter render to '').
 */
class VariableResolver
{
    private const PLACEHOLDER =
        '/\{\{\s*([a-zA-Z0-9_]+(?:\.[a-zA-Z0-9_\-]+)*)((?:\s*\|\s*[a-z_]+(?::\'[^\']*\')?)*)\s*\}\}/';
    private const ALLOWED_ROOTS = ['trigger', 'steps', 'workflow', 'secrets'];
    private const FILTERS = ['upper', 'lower', 'trim', 'number', 'date', 'default'];

    /**
     * Collection formatters: these accept a list value, so the pipeline must
     * NOT pre-stringify an array before them.
     */
    private const COLLECTION_FILTERS = ['count', 'pluck', 'join', 'table', 'json'];

    public function __construct(
        private readonly SecretsProviderInterface $secretsProvider
    ) {
    }

    /**
     * Interpolate all placeholders in a string
     */
    public function resolve(string $template, ExecutionContext $ctx): string
    {
        return (string) preg_replace_callback(
            self::PLACEHOLDER,
            function (array $matches) use ($ctx): string {
                $value = $this->resolvePath($matches[1], $ctx);
                $chain = $matches[2] ?? '';

                // Raw-value stage: keep an array intact only when the first
                // filter is collection-typed; otherwise scalarize as before.
                if (is_array($value) && $this->firstFilterIsCollection($chain)) {
                    $piped = $this->applyFilters($value, $chain);
                } else {
                    $piped = $this->applyFilters($this->scalarize($value), $chain);
                }
                return $this->stringify($piped);
            },
            $template
        );
    }

    /**
     * Legacy scalarization: null/array -> '', bool -> '1'/'' , else (string).
     */
    private function scalarize(mixed $value): string
    {
        if ($value === null || is_array($value)) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        return (string) $value;
    }

    /**
     * Final stringification of the piped value: a scalar renders as itself, a
     * surviving array renders as JSON (a bare |pluck with no terminal), null
     * as ''.
     */
    private function stringify(mixed $value): string
    {
        if (is_array($value)) {
            return (string) json_encode(array_values($value), JSON_UNESCAPED_SLASHES);
        }
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        return (string) $value;
    }

    private function firstFilterIsCollection(string $filterChain): bool
    {
        if ($filterChain === ''
            || !preg_match('/\|\s*([a-z_]+)/', $filterChain, $m)
        ) {
            return false;
        }
        return in_array($m[1], self::COLLECTION_FILTERS, true);
    }

    /**
     * Apply a parsed "|filter:'arg'|filter" chain to a value (mixed in, mixed out)
     */
    private function applyFilters(mixed $value, string $filterChain): mixed
    {
        if ($filterChain === '') {
            return $value;
        }
        preg_match_all('/\|\s*([a-z_]+)(?::\'([^\']*)\')?/', $filterChain, $filters, PREG_SET_ORDER);
        foreach ($filters as $filter) {
            $name = $filter[1];
            $arg = $filter[2] ?? null;
            if (!in_array($name, self::FILTERS, true) && !in_array($name, self::COLLECTION_FILTERS, true)) {
                continue; // unknown filter: fail open, keep the value
            }
            $value = $this->applyFilter($value, $name, $arg);
        }
        return $value;
    }

    private function applyFilter(mixed $value, string $name, ?string $arg): mixed
    {
        if (in_array($name, self::COLLECTION_FILTERS, true)) {
            return $this->applyCollectionFilter($value, $name, $arg);
        }
        // Scalar string filters only apply to string-shaped values; a value
        // still in list form (e.g. after a |pluck) fails open.
        if (is_array($value)) {
            return $value;
        }
        $value = $this->scalarize($value);
        switch ($name) {
            case 'upper':
                return mb_strtoupper($value);
            case 'lower':
                return mb_strtolower($value);
            case 'trim':
                return trim($value);
            case 'number':
                if (!is_numeric($value)) {
                    return $value;
                }
                $decimals = $arg !== null && ctype_digit($arg) ? (int) $arg : 2;
                return number_format((float) $value, $decimals, '.', ',');
            case 'date':
                $timestamp = $value !== '' ? strtotime($value) : false;
                if ($timestamp === false) {
                    return $value;
                }
                return gmdate($arg !== null && $arg !== '' ? $arg : 'Y-m-d H:i:s', $timestamp);
            case 'default':
                return $value === '' ? (string) $arg : $value;
            default:
                return $value;
        }
    }

    /**
     * Collection formatters. Non-list inputs fail open (returned unchanged),
     * matching the scalar filters' "cannot apply => pass through" discipline.
     */
    private function applyCollectionFilter(mixed $value, string $name, ?string $arg): mixed
    {
        if ($name === 'json') {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
        }
        if (!is_array($value)) {
            return $value; // fail open on non-list input
        }
        $list = array_values($value);
        switch ($name) {
            case 'count':
                return (string) count($list);
            case 'pluck':
                $field = (string) $arg;
                $plucked = [];
                foreach ($list as $row) {
                    if (is_array($row) && array_key_exists($field, $row)) {
                        $plucked[] = $row[$field];
                    }
                }
                return $plucked;
            case 'join':
                $separator = $arg ?? '';
                return implode($separator, array_map([$this, 'cell'], $list));
            case 'table':
                return $this->renderTable($list, $arg);
            default:
                return $value;
        }
    }

    /**
     * Render a plain HTML table for an email/text body. Header from the column
     * list; one row per list item; every cell HTML-escaped (matching the
     * ad-hoc email escaping rule).
     *
     * @param array<int, mixed> $list
     */
    private function renderTable(array $list, ?string $columns): string
    {
        $fields = array_values(array_filter(
            array_map('trim', explode(',', (string) $columns)),
            static fn (string $f): bool => $f !== ''
        ));
        if ($fields === []) {
            return '';
        }
        $header = '<tr>';
        foreach ($fields as $field) {
            $header .= '<th>' . $this->escape($field) . '</th>';
        }
        $header .= '</tr>';

        $body = '';
        foreach ($list as $row) {
            $body .= '<tr>';
            foreach ($fields as $field) {
                $cell = is_array($row) && array_key_exists($field, $row) ? $row[$field] : '';
                $body .= '<td>' . $this->escape($this->cell($cell)) . '</td>';
            }
            $body .= '</tr>';
        }
        return '<table>' . $header . $body . '</table>';
    }

    /**
     * Stringify a single collection element (scalar), skipping nested arrays.
     */
    private function cell(mixed $value): string
    {
        if (is_array($value) || $value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        return (string) $value;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Recursively interpolate all string values of a config array.
     * Keys are never interpolated: values, not structure.
     */
    public function resolveConfig(array $config, ExecutionContext $ctx): array
    {
        foreach ($config as $key => $value) {
            if (is_string($value)) {
                $config[$key] = $this->resolve($value, $ctx);
            } elseif (is_array($value)) {
                $config[$key] = $this->resolveConfig($value, $ctx);
            }
        }
        return $config;
    }

    /**
     * Resolve a single dot-path to its raw value (used by condition comparators)
     */
    public function resolvePath(string $path, ExecutionContext $ctx): mixed
    {
        $root = explode('.', $path, 2)[0];
        if (!in_array($root, self::ALLOWED_ROOTS, true)) {
            return null;
        }
        if ($root === 'secrets') {
            $key = substr($path, strlen('secrets.'));
            return $key === '' ? null : $this->secretsProvider->get($key);
        }
        return $ctx->resolvePath($path);
    }
}
