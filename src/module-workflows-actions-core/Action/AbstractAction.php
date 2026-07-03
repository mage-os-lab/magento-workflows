<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Action;

use MageOS\Workflows\Api\ActionInterface;
use MageOS\Workflows\Api\ActionMetadataInterface;
use MageOS\Workflows\Model\Action\ActionResult;
use MageOS\Workflows\Model\Execution\ExecutionContext;

/**
 * Base class for the bundled action library. Config values arrive already
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
     * @inheritDoc
     */
    public function getAclResource(): ?string
    {
        return 'MageOS_Workflows::action_' . strtolower($this->getGroup());
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
            sprintf('Missing required config "%s" for action "%s"', $key, $this->getCode())
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
    protected function stepKey(ExecutionContext $ctx): string
    {
        return $ctx->getExecution()->getCurrentStep() ?? $this->getCode();
    }
}
