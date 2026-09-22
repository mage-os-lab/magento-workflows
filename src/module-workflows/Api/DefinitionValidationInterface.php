<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Api;

/**
 * Validate-only entry point (F6): runs the same save-time validation
 * pipeline a real save would run (structural, graph, profile, action codes,
 * conditions shape — per-action ACL is skipped, this is not an authoring
 * path) against a posted definition, without persisting anything. Primary
 * customers: the edit-form "Refresh preview" action, CI linting, the canvas.
 *
 * @api
 */
interface DefinitionValidationInterface
{
    /**
     * @param string $definition raw definition JSON
     * @param string|null $conditionsSerialized root condition tree JSON
     * @param string|null $triggerType event|schedule|manual (plain-language rendering only)
     * @param string|null $triggerRef trigger event/schedule code (plain-language rendering only)
     * @param string|null $entityType workflow entity type (plain-language rendering only)
     * @return \MageOS\Workflows\Api\Data\DefinitionValidationResultInterface
     */
    public function validate(
        string $definition,
        ?string $conditionsSerialized = null,
        ?string $triggerType = null,
        ?string $triggerRef = null,
        ?string $entityType = null
    ): \MageOS\Workflows\Api\Data\DefinitionValidationResultInterface;
}
