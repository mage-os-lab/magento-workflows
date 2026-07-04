<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api;

/**
 * GET /V1/workflows/meta/relations (F6): lists the RelationPool as metadata.
 * ACL `MageOS_Workflows::view` (read-only, like the other meta endpoints).
 * The registry — not reflection — is the enumerable source the canvas, gallery
 * and CI consume (docs/discovery/entity-cross-referencing.md §7).
 *
 * @api
 */
interface RelationMetadataProviderInterface
{
    /**
     * @return \MageOS\Workflows\Api\Data\RelationMetadataInterface[]
     */
    public function getRelations(): array;
}
