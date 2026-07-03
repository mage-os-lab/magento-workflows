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
 */
class VariableResolver
{
    private const PLACEHOLDER =
        '/\{\{\s*([a-zA-Z0-9_]+(?:\.[a-zA-Z0-9_\-]+)*)((?:\s*\|\s*[a-z_]+(?::\'[^\']*\')?)*)\s*\}\}/';
    private const ALLOWED_ROOTS = ['trigger', 'steps', 'workflow', 'secrets'];
    private const FILTERS = ['upper', 'lower', 'trim', 'number', 'date', 'default'];

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
                if ($value === null || is_array($value)) {
                    $rendered = '';
                } elseif (is_bool($value)) {
                    $rendered = $value ? '1' : '';
                } else {
                    $rendered = (string) $value;
                }
                return $this->applyFilters($rendered, $matches[2] ?? '');
            },
            $template
        );
    }

    /**
     * Apply a parsed "|filter:'arg'|filter" chain to a rendered value
     */
    private function applyFilters(string $value, string $filterChain): string
    {
        if ($filterChain === '') {
            return $value;
        }
        preg_match_all('/\|\s*([a-z_]+)(?::\'([^\']*)\')?/', $filterChain, $filters, PREG_SET_ORDER);
        foreach ($filters as $filter) {
            $name = $filter[1];
            $arg = $filter[2] ?? null;
            if (!in_array($name, self::FILTERS, true)) {
                continue; // unknown filter: fail open, keep the value
            }
            $value = $this->applyFilter($value, $name, $arg);
        }
        return $value;
    }

    private function applyFilter(string $value, string $name, ?string $arg): string
    {
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
