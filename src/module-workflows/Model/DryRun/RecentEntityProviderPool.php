<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\DryRun;

use MageOS\Workflows\Api\RecentEntityProviderInterface;

/**
 * DI-registered pool of per-entity-type recent-entity providers, backing the
 * dry-run entity picker (03). Mirrors the ActionPool/RelationPool extension
 * surface: one class + one di.xml entry per new entity type. A type with no
 * registered provider simply yields an empty list — the picker falls back to
 * manual id entry.
 */
class RecentEntityProviderPool
{
    public const DEFAULT_LIMIT = 20;

    /**
     * @var array<string, RecentEntityProviderInterface> entity type => provider
     */
    private array $byType = [];

    /**
     * @param RecentEntityProviderInterface[] $providers
     */
    public function __construct(array $providers = [])
    {
        foreach ($providers as $provider) {
            if (!$provider instanceof RecentEntityProviderInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'Recent-entity providers must implement %s',
                    RecentEntityProviderInterface::class
                ));
            }
            $this->byType[$provider->getEntityType()] = $provider;
        }
    }

    public function hasProvider(string $entityType): bool
    {
        return isset($this->byType[$entityType]);
    }

    /**
     * @return array<int, array{id: int, label: string}>
     */
    public function getRecent(string $entityType, int $limit = self::DEFAULT_LIMIT): array
    {
        if (!isset($this->byType[$entityType])) {
            return [];
        }
        return $this->byType[$entityType]->getRecent($limit > 0 ? $limit : self::DEFAULT_LIMIT);
    }
}
