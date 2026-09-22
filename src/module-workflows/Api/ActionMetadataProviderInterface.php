<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Api;

/**
 * GET /V1/workflows/meta/actions (F6, canvas stage 1): lists the ActionPool as
 * palette/config metadata. ACL `MageOS_Workflows::view` (read-only, like every
 * other meta endpoint); the palette display is ACL-filtered for convenience but
 * the save path re-gates authoring via authorizeActionCodes.
 *
 * @api
 */
interface ActionMetadataProviderInterface
{
    /**
     * @param string|null $entityType optional filter: only actions applicable
     *        to this workflow entity type (empty applicable-set = applies to all)
     * @return \MageOS\Workflows\Api\Data\ActionMetadataItemInterface[]
     */
    public function getActions(?string $entityType = null): array;
}
