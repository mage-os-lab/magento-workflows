<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule;

use Magento\Framework\DataObject;
use MageOS\Workflows\Model\Rule\Hydrator\CustomerHydrator;
use MageOS\Workflows\Model\Rule\Hydrator\EntityHydratorInterface;
use MageOS\Workflows\Model\Rule\Hydrator\OrderHydrator;
use MageOS\Workflows\Model\Rule\Hydrator\ProductHydrator;
use MageOS\Workflows\Model\Rule\Hydrator\QuoteHydrator;

/**
 * Hydrator-pool-backed hydration with a per-instance identity map — the
 * hydrator counterpart of ConditionCombinePool: a DI-registered map
 * entity_type => EntityHydratorInterface with the four built-in types
 * (sales_order, customer, catalog_product, quote) as hardcoded defaults. Extensions
 * register additional entity types by appending to the `hydrators` argument
 * in di.xml; the per-entity loading + flat-array enrichment lives in the
 * Hydrator\* implementations.
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
     * @var array<string, EntityHydratorInterface> entity_type => hydrator
     */
    private readonly array $hydrators;

    /**
     * @param array<string, EntityHydratorInterface> $hydrators entity_type => hydrator (merged over defaults)
     */
    public function __construct(
        OrderHydrator $orderHydrator,
        CustomerHydrator $customerHydrator,
        ProductHydrator $productHydrator,
        QuoteHydrator $quoteHydrator,
        array $hydrators = []
    ) {
        $this->hydrators = array_merge(
            [
                self::TYPE_ORDER => $orderHydrator,
                self::TYPE_CUSTOMER => $customerHydrator,
                self::TYPE_PRODUCT => $productHydrator,
                self::TYPE_QUOTE => $quoteHydrator,
            ],
            $hydrators
        );
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
