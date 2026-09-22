<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminExtension\Model;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Counts, per entity type, how many workflows exist and how many are enabled —
 * the numbers the grid strip renders (docs/discovery/entity-grid-visibility.md
 * §4). One getList() filtered by entity_type; enabled = STATUS_ENABLED rows
 * (Shadow/Suspended/Disabled are not "active"), matching the admin-ui status
 * source. The grid table is tiny (dozens of rows), so counting fetched items is
 * cheaper than a second aggregate query.
 *
 * The result is cached under a per-entity-type key tagged with CACHE_TAG so the
 * repository plugin (InvalidateCountCache) can drop every entry on any
 * save/delete. A failed or throwing lookup degrades to zero counts and is NOT
 * cached (so a transient failure retries next page load) — surfacing workflows
 * on a core admin page must never break that page's render.
 */
class WorkflowCountProvider
{
    /** Cache tag cleaned by the repository invalidation plugin. */
    public const CACHE_TAG = 'mageos_workflows_grid_counts';

    private const CACHE_KEY_PREFIX = 'mageos_workflows_grid_counts_';

    public function __construct(
        private readonly WorkflowRepositoryInterface $workflowRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{enabled:int, total:int}
     */
    public function getCounts(string $entityType): array
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $entityType;

        $cached = $this->cache->load($cacheKey);
        if (is_string($cached) && $cached !== '') {
            $decoded = $this->serializer->unserialize($cached);
            if (is_array($decoded) && isset($decoded['enabled'], $decoded['total'])) {
                return ['enabled' => (int) $decoded['enabled'], 'total' => (int) $decoded['total']];
            }
        }

        try {
            $counts = $this->query($entityType);
        } catch (\Throwable $e) {
            // Discoverability must never take down a native grid page: degrade
            // to zero (the strip then renders nothing unless the admin can
            // manage) and leave the cache empty so the next load retries.
            $this->logger->warning(
                'Workflow grid-count lookup failed for entity type "' . $entityType . '"',
                ['exception' => $e]
            );
            return ['enabled' => 0, 'total' => 0];
        }

        $this->cache->save($this->serializer->serialize($counts), $cacheKey, [self::CACHE_TAG]);

        return $counts;
    }

    /**
     * @return array{enabled:int, total:int}
     */
    private function query(string $entityType): array
    {
        $this->searchCriteriaBuilder->addFilter(WorkflowInterface::ENTITY_TYPE, $entityType);
        $searchCriteria = $this->searchCriteriaBuilder->create();

        $enabled = 0;
        $total = 0;
        foreach ($this->workflowRepository->getList($searchCriteria)->getItems() as $workflow) {
            $total++;
            if ($workflow->getStatus() === WorkflowInterface::STATUS_ENABLED) {
                $enabled++;
            }
        }

        return ['enabled' => $enabled, 'total' => $total];
    }
}
