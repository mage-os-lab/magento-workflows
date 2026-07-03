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
 */
class VariableResolver
{
    private const PLACEHOLDER = '/\{\{\s*([a-zA-Z0-9_]+(?:\.[a-zA-Z0-9_\-]+)*)\s*\}\}/';
    private const ALLOWED_ROOTS = ['trigger', 'steps', 'workflow', 'secrets'];

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
                    return '';
                }
                if (is_bool($value)) {
                    return $value ? '1' : '';
                }
                return (string) $value;
            },
            $template
        );
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
