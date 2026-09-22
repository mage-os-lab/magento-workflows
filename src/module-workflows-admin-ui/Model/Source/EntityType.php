<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\Workflows\Api\EntityTypeMetadataProviderInterface;

/**
 * Entity type options for the workflow form's entity_type select. Sourced from
 * the core catalogue (EntityTypeMetadataProviderInterface -- the same
 * DI-registered code => label list behind GET /V1/workflows/meta/entity-types)
 * so the form select, the REST catalogue, and downstream addons can never
 * disagree.
 */
class EntityType implements OptionSourceInterface
{
    public function __construct(
        private readonly EntityTypeMetadataProviderInterface $metadataProvider
    ) {
    }

    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->metadataProvider->getEntityTypes() as $entityType) {
            $options[] = ['value' => $entityType->getCode(), 'label' => __($entityType->getLabel())];
        }
        return $options;
    }
}
