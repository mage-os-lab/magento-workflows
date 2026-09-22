<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Webapi;

use MageOS\Workflows\Api\Data\OptionItemInterface;

/**
 * Immutable webapi DTO for a GET /V1/workflows/meta/options entry (F6).
 */
class OptionItem implements OptionItemInterface
{
    public function __construct(
        private readonly string $value,
        private readonly string $label
    ) {
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getLabel(): string
    {
        return $this->label;
    }
}
