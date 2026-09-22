<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\DryRun;

/**
 * One unit of work handed from the {@see PathExplorer} to the {@see Walker}: a
 * step key to evaluate, the path id it was reached on, and whether it sits
 * downstream of a failure that production would have stopped on.
 */
class Visit
{
    public function __construct(
        private readonly string $stepKey,
        private readonly string $pathId,
        private readonly bool $pastProductionStop
    ) {
    }

    public function getStepKey(): string
    {
        return $this->stepKey;
    }

    public function getPathId(): string
    {
        return $this->pathId;
    }

    public function isPastProductionStop(): bool
    {
        return $this->pastProductionStop;
    }
}
