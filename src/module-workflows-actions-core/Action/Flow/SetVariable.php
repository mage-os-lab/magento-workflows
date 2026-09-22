<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Action\Flow;

use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\BatchCapableActionInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\AbstractAction;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * flow.set_variable — stores a value in the step output so later steps can
 * reference {{ steps.<key>.<name> }}. Pure context write, no side effects.
 * (delay and stop are step TYPES handled by the executor, not actions.)
 */
class SetVariable extends AbstractAction implements SimulateableActionInterface, BatchCapableActionInterface
{
    private const NAME_PATTERN = '/^[a-zA-Z0-9_]+$/';

    public function getCode(): string
    {
        return 'flow.set_variable';
    }

    public function supportsBatch(): bool
    {
        return true;
    }

    public function getLabel(): string
    {
        return (string)__('Set Context Variable');
    }

    public function getGroup(): string
    {
        return (string)__('Flow');
    }

    public function getConfigForm(): array
    {
        return [
            ['name' => 'name', 'label' => 'Variable Name', 'type' => 'text', 'required' => true,
                'notice' => 'Letters, digits, and underscores only.'],
            ['name' => 'value', 'label' => 'Value', 'type' => 'text', 'required' => true],
        ];
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $name = $this->stringConfig($config, 'name');
        if ($name === null) {
            return $this->missingConfig('name');
        }
        if (!preg_match(self::NAME_PATTERN, $name)) {
            return ActionResult::failure((string)__(
                'Invalid variable name "%1" (letters, digits, underscores only)',
                $name
            ));
        }

        return ActionResult::success([$name => $config['value'] ?? null]);
    }

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $name = $this->stringConfig($config, 'name');
        if ($name === null) {
            return $this->missingConfig('name');
        }
        if (!preg_match(self::NAME_PATTERN, $name)) {
            return ActionResult::failure((string)__('Invalid variable name "%1"', $name));
        }
        // No side effects to begin with: simulate returns the real output too,
        // so downstream shadow steps can interpolate it.
        return $this->simulated(
            sprintf('Set context variable "%s"', $name),
            [$name => $config['value'] ?? null]
        );
    }
}
