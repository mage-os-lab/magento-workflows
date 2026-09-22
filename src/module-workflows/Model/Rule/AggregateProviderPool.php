<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule;

/**
 * DI-registered pool of aggregate providers keyed by target entity type (E2 /
 * domain-packs). Mirrors RelationPool / ConditionCombinePool — the extension
 * surface is one class implementing AggregateProviderInterface plus one di.xml
 * entry under the entity type it contributes to.
 *
 * A condition root reads its aggregate attribute metadata from here instead of
 * a hardcoded const; the hydration path merges the computed aggregates from
 * every provider registered for the entity type. This lets sales contribute
 * order-history aggregates to the customer root, and later packs add
 * reviews_count / wishlist_items_count, without touching the customer root
 * (core-coverage CUS-C3).
 */
class AggregateProviderPool
{
    /**
     * @param array<string, AggregateProviderInterface[]> $providers entity_type => providers
     */
    public function __construct(
        private readonly array $providers = []
    ) {
        foreach ($this->providers as $entityType => $entityProviders) {
            foreach ($entityProviders as $provider) {
                if (!$provider instanceof AggregateProviderInterface) {
                    throw new \InvalidArgumentException(
                        sprintf(
                            'Aggregate provider for entity type "%s" must implement %s',
                            $entityType,
                            AggregateProviderInterface::class
                        )
                    );
                }
            }
        }
    }

    /**
     * @return AggregateProviderInterface[]
     */
    public function getProviders(string $entityType): array
    {
        return $this->providers[$entityType] ?? [];
    }

    /**
     * Merged aggregate-attribute metadata for a target entity type, in
     * provider-registration order.
     *
     * @return array<string, array{label: string, input_type: string}> code => metadata
     */
    public function getAttributeMetadata(string $entityType): array
    {
        $metadata = [];
        foreach ($this->getProviders($entityType) as $provider) {
            foreach ($provider->getAttributeMetadata() as $code => $meta) {
                $metadata[$code] = $meta;
            }
        }
        return $metadata;
    }

    /**
     * Computed aggregate values for one entity, merged across every provider
     * registered for the entity type (absent keys fail toward false).
     *
     * @return array<string, mixed> aggregate code => value
     */
    public function getAggregates(string $entityType, int $entityId): array
    {
        $aggregates = [];
        foreach ($this->getProviders($entityType) as $provider) {
            $aggregates = array_merge($aggregates, $provider->getAggregates($entityId));
        }
        return $aggregates;
    }
}
