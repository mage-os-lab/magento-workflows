<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Webapi;

use MageOS\Workflows\Api\Data\EntityTypeMetadataInterface;

/**
 * Immutable webapi DTO for a GET /V1/workflows/meta/entity-types entry (F6).
 */
class EntityTypeMetadata implements EntityTypeMetadataInterface
{
    public function __construct(
        private readonly string $code,
        private readonly string $label
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }
}
