<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Webapi;

use MageOS\Workflows\Api\Data\RelationMetadataInterface;
use MageOS\Workflows\Api\RelationMetadataProviderInterface;
use MageOS\Workflows\Model\Relation\RelationPool;

/**
 * GET /V1/workflows/meta/relations (F6, entity cross-referencing stage 4):
 * projects the DI-registered RelationPool to metadata DTOs. Read-only; no
 * resolution, no source entity — just the enumerable catalogue.
 */
class RelationMetadataProvider implements RelationMetadataProviderInterface
{
    public function __construct(
        private readonly RelationPool $relationPool
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getRelations(): array
    {
        $relations = [];
        foreach ($this->relationPool->getAll() as $relation) {
            $relations[] = new RelationMetadata(
                $relation->getCode(),
                $relation->getLabel(),
                $relation->getSourceEntityType(),
                $relation->getTargetEntityType(),
                $relation->getCardinality()
            );
        }
        return $relations;
    }
}
