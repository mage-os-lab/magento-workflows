<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Relation;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * The ONLY entry point feature code may call for relation resolution (F5).
 * Both consumers — the RelatedEntity combine (02) and the FanOutExpander
 * (04) — bind to this context, never to RelationInterface::resolveIds()
 * directly. Centralized guarantees:
 *
 *  - memoization on (relation, source entity_id); skipped when id <= 0
 *  - website scoping derived from the source entity (resolveWebsiteId)
 *  - the resolution cap (mageos_workflows/guards/relation_cap, default 100)
 *  - fail-toward-false: unknown relations and resolver exceptions yield []
 *    with a logged error, never a thrown one — a broken relation must not
 *    fail the surrounding evaluation harder than "not related"
 *
 * isFresh() threads the surrounding condition's revalidate_entity flag:
 * a fresh pass bypasses (and repopulates) the memo, mirroring the
 * HydrationProvider identity-map convention.
 */
class RelationContext
{
    public const CONFIG_RELATION_CAP = 'mageos_workflows/guards/relation_cap';
    public const DEFAULT_RELATION_CAP = 100;

    /**
     * Memo: "<relation>:<entity_id>" => int[]
     *
     * @var array<string, int[]>
     */
    private array $resolved = [];

    private bool $fresh = false;

    public function __construct(
        private readonly RelationPool $relationPool,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return int[] related target-entity ids; [] on unknown relation or resolver failure
     */
    public function resolve(string $relationCode, DataObject $source): array
    {
        if (!$this->relationPool->has($relationCode)) {
            $this->logger->error(sprintf(
                'Unknown workflow relation "%s"; resolving toward "not related"',
                $relationCode
            ));
            return [];
        }

        $entityId = (int) ($source->getData('entity_id') ?? 0);
        $memoKey = $relationCode . ':' . $entityId;
        if ($entityId > 0 && !$this->fresh && array_key_exists($memoKey, $this->resolved)) {
            return $this->resolved[$memoKey];
        }

        try {
            $ids = $this->relationPool->get($relationCode)->resolveIds(
                $source,
                $this->resolveWebsiteId($source)
            );
        } catch (\Throwable $e) {
            // Fail-toward-false: a broken resolver reads as "not related"
            $this->logger->error(sprintf(
                'Workflow relation "%s" failed to resolve for entity %d: %s',
                $relationCode,
                $entityId,
                $e->getMessage()
            ), ['exception' => $e]);
            return [];
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        $cap = $this->getCap();
        if (count($ids) > $cap) {
            $this->logger->warning(sprintf(
                'Workflow relation "%s" resolved %d ids for entity %d; capped to %d '
                . '(%s)',
                $relationCode,
                count($ids),
                $entityId,
                $cap,
                self::CONFIG_RELATION_CAP
            ));
            $ids = array_slice($ids, 0, $cap);
        }

        if ($entityId > 0) {
            $this->resolved[$memoKey] = $ids;
        }
        return $ids;
    }

    /**
     * Website scope derived from the source entity; null = indeterminable
     * (resolvers then look up unscoped). Customers under global account
     * sharing are deliberately unscoped — the same person is one account
     * across websites (customer/account_share/scope = global).
     */
    public function resolveWebsiteId(DataObject $source): ?int
    {
        if ((string) $source->getData(HydrationProviderInterface::KEY_ENTITY_TYPE)
                === HydrationProviderInterface::TYPE_CUSTOMER
            && (int) $this->scopeConfig->getValue('customer/account_share/scope') === 0
        ) {
            return null;
        }
        $websiteId = $source->getData('website_id');
        if (is_numeric($websiteId)) {
            return (int) $websiteId;
        }
        $storeId = $source->getData('store_id');
        if (is_numeric($storeId)) {
            try {
                return (int) $this->storeManager->getStore((int) $storeId)->getWebsiteId();
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * Threads the surrounding condition's revalidate_entity flag: while
     * fresh, resolve() bypasses (and repopulates) the memo.
     */
    public function setFresh(bool $fresh): void
    {
        $this->fresh = $fresh;
    }

    public function isFresh(): bool
    {
        return $this->fresh;
    }

    /**
     * Clear per-evaluation state (memo + fresh flag) between executions.
     */
    public function reset(): void
    {
        $this->resolved = [];
        $this->fresh = false;
    }

    private function getCap(): int
    {
        $cap = (int) $this->scopeConfig->getValue(self::CONFIG_RELATION_CAP);
        return $cap > 0 ? $cap : self::DEFAULT_RELATION_CAP;
    }
}
