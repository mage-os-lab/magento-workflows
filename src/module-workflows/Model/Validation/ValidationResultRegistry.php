<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Validation;

/**
 * Request-scoped holder for the most recent save-validation result, so
 * warnings can travel to the surface that triggered the save (admin form
 * messages, CLI import output) without threading a return value through
 * WorkflowRepositoryInterface::save(). Errors never land here as a save
 * outcome — they throw out of the plugin before the save proceeds.
 */
class ValidationResultRegistry
{
    private ?ValidationResult $lastResult = null;

    public function set(ValidationResult $result): void
    {
        $this->lastResult = $result;
    }

    public function get(): ?ValidationResult
    {
        return $this->lastResult;
    }
}
