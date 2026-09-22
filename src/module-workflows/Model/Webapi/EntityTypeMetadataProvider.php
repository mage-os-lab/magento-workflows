<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Webapi;

use MageOS\Workflows\Api\EntityTypeMetadataProviderInterface;

/**
 * GET /V1/workflows/meta/entity-types (F6, canvas stage 1): projects the
 * DI-registered code => label map to DTOs. The map is the extension surface —
 * a module adding a new entity type appends to it in di.xml.
 */
class EntityTypeMetadataProvider implements EntityTypeMetadataProviderInterface
{
    /**
     * @param array<string, string> $entityTypes code => label
     */
    public function __construct(
        private readonly array $entityTypes = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getEntityTypes(): array
    {
        $items = [];
        foreach ($this->entityTypes as $code => $label) {
            $items[] = new EntityTypeMetadata((string) $code, (string) $label);
        }
        return $items;
    }
}
