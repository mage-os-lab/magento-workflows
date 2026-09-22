<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule;

use Magento\Framework\DataObject;
use MageOS\Workflows\Model\Rule\Hydrator\EntityHydratorInterface;

/**
 * Hydrator-pool-backed hydration with a per-instance identity map — the
 * hydrator counterpart of ConditionCombinePool: a DI-registered map
 * entity_type => EntityHydratorInterface. The four built-in types
 * (sales_order, customer, catalog_product, quote) are contributed entirely
 * through the `hydrators` di.xml argument — no type is hardcoded, so a domain
 * pack owning an entity's Magento module also owns its hydrator registration
 * and the engine never type-hints a class that may move out. Extensions
 * register additional entity types by appending to the same argument; the
 * per-entity loading + flat-array enrichment lives in the Hydrator\*
 * implementations.
 *
 * Note on $fresh: the identity map is always bypassed. Repositories keep
 * their own registries; order/customer/product registries are per-request
 * and acceptable for delayed (fresh consumer process) re-validation.
 */
class HydrationProvider implements HydrationProviderInterface
{
    /**
     * Identity map: "<type>:<id>" => DataObject|null (null = known missing)
     *
     * @var array<string, DataObject|null>
     */
    private array $entities = [];

    /**
     * @param array<string, EntityHydratorInterface> $hydrators entity_type => hydrator
     */
    public function __construct(
        private readonly array $hydrators = []
    ) {
    }

    public function getEntity(string $entityType, int $entityId, bool $fresh = false): ?DataObject
    {
        if ($entityId <= 0) {
            return null;
        }
        $key = $entityType . ':' . $entityId;
        if (!$fresh && array_key_exists($key, $this->entities)) {
            return $this->entities[$key];
        }

        $hydrator = $this->hydrators[$entityType] ?? null;
        $entity = $hydrator instanceof EntityHydratorInterface
            ? $hydrator->hydrate($entityId)
            : null;

        return $this->entities[$key] = $entity;
    }
}
