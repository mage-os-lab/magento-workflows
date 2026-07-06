<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api;

/**
 * Supplies the "recent entities" list the dry-run entity picker offers per
 * entity type (03). v1 is deliberately simple: most-recent by created/updated,
 * a small limit, and NO condition filtering (the picker is a convenience, not a
 * query builder). New entity types register their own provider into the pool.
 *
 * @api
 */
interface RecentEntityProviderInterface
{
    /**
     * The entity type this provider serves (sales_order, customer, …).
     */
    public function getEntityType(): string;

    /**
     * Most-recent entities, newest first.
     *
     * @param int $limit maximum rows to return
     * @return array<int, array{id: int, label: string}>
     */
    public function getRecent(int $limit): array;
}
