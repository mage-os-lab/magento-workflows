<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Webapi;

use MageOS\Workflows\Api\Data\RelationMetadataInterface;

/**
 * Immutable webapi DTO for a GET /V1/workflows/meta/relations entry (F6).
 */
class RelationMetadata implements RelationMetadataInterface
{
    public function __construct(
        private readonly string $code,
        private readonly string $label,
        private readonly string $sourceEntityType,
        private readonly string $targetEntityType,
        private readonly string $cardinality
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

    public function getSourceEntityType(): string
    {
        return $this->sourceEntityType;
    }

    public function getTargetEntityType(): string
    {
        return $this->targetEntityType;
    }

    public function getCardinality(): string
    {
        return $this->cardinality;
    }
}
