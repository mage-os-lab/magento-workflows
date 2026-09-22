<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Api\Data;

/**
 * Response shape of the dry-run endpoints (03): the validation findings the
 * definition produced under the dry-run check subset, plus — when the graph was
 * sound enough to walk — the flat ordered list of visited steps. Mirrors the
 * validate-endpoint result contract.
 *
 * @api
 */
interface DryRunResultInterface
{
    /**
     * True when no blocking (error-severity) findings exist.
     */
    public function getValid(): bool;

    /**
     * True when the workflow's root conditions did not match the entity, so no
     * step would run.
     */
    public function getSkipped(): bool;

    /**
     * True when the distinct-step-visit cap stopped the walk early.
     */
    public function getTruncated(): bool;

    /**
     * @return \MageOS\Workflows\Api\Data\ValidationMessageInterface[]
     */
    public function getMessages(): array;

    /**
     * @return \MageOS\Workflows\Api\Data\DryRunTraceStepInterface[]
     */
    public function getSteps(): array;
}
