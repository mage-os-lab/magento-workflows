<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Action;

use MageOS\Workflows\Api\ActionInterface;
use MageOS\Workflows\Api\ActionMetadataInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;

/**
 * Base class for action implementations. Config values arrive already
 * interpolated ({{ trigger.* }} etc.) — interpolation supplies values, never
 * structure, so config KEYS and attribute/action codes are always static.
 */
abstract class AbstractAction implements ActionInterface, ActionMetadataInterface
{
    /**
     * @inheritDoc
     */
    public function getApplicableEntities(): array
    {
        return [];
    }

    /**
     * ACL group per action-code prefix. Derived from getCode(), never from
     * getGroup(): the group label is translatable and ACL resource ids must
     * be locale-independent.
     */
    private const ACL_GROUP_BY_CODE_PREFIX = [
        'order' => 'sales',
        'customer' => 'customer',
        'product' => 'catalog',
        'marketing' => 'marketing',
        'notify' => 'notify',
        'flow' => 'flow',
    ];

    /**
     * @inheritDoc
     */
    public function getAclResource(): ?string
    {
        $prefix = explode('.', $this->getCode(), 2)[0];
        return 'MageOS_Workflows::action_' . (self::ACL_GROUP_BY_CODE_PREFIX[$prefix] ?? $prefix);
    }

    /**
     * @inheritDoc
     */
    public function getConfigForm(): array
    {
        return [];
    }

    /**
     * Uniform simulate() payload: success with a human description of the would-be effect
     */
    protected function simulated(string $would, array $extra = []): ActionResult
    {
        return ActionResult::success(['simulated' => true, 'would' => $would] + $extra);
    }

    /**
     * Non-retryable failure for a missing/empty required config value
     */
    protected function missingConfig(string $key): ActionResult
    {
        return ActionResult::failure(
            (string)__('Missing required config "%1" for action "%2"', $key, $this->getCode())
        );
    }

    /**
     * Trimmed non-empty string config value or null
     */
    protected function stringConfig(array $config, string $key, ?string $default = null): ?string
    {
        $value = $config[$key] ?? null;
        if ($value === null || is_array($value) || is_object($value)) {
            return $default;
        }
        $value = trim((string)$value);
        return $value === '' ? $default : $value;
    }

    protected function boolConfig(array $config, string $key, bool $default = false): bool
    {
        $value = $config[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }
        return (bool)$value;
    }

    protected function intConfig(array $config, string $key, ?int $default = null): ?int
    {
        $value = $config[$key] ?? null;
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }
        return (int)$value;
    }

    /**
     * The executing step's key (for dedupe keys and log context); falls back to the action code
     */
    protected function stepKey(ExecutionContextInterface $ctx): string
    {
        return $ctx->getExecution()->getCurrentStep() ?? $this->getCode();
    }
}
