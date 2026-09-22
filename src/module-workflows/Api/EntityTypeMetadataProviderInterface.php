<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Api;

/**
 * GET /V1/workflows/meta/entity-types (F6, canvas stage 1): the workflow entity
 * types available in config-panel selects. Sourced from a DI-registered list
 * (etc/di.xml) so the catalogue is authoritative in core, not duplicated in
 * admin-ui. ACL `MageOS_Workflows::view`.
 *
 * @api
 */
interface EntityTypeMetadataProviderInterface
{
    /**
     * @return \MageOS\Workflows\Api\Data\EntityTypeMetadataInterface[]
     */
    public function getEntityTypes(): array;
}
