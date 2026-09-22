<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminExtension\Test\Unit\Model;

use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Test\Unit\Stub\WorkflowStub;
use MageOS\WorkflowsAdminExtension\Model\WorkflowCountProvider;
use MageOS\WorkflowsAdminExtension\Test\Unit\Stub\FakeCache;
use MageOS\WorkflowsAdminExtension\Test\Unit\Stub\FakeSearchCriteriaBuilder;
use MageOS\WorkflowsAdminExtension\Test\Unit\Stub\FakeWorkflowRepository;
use MageOS\WorkflowsAdminExtension\Test\Unit\Stub\JsonSerializer;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * WorkflowCountProvider is the strip's data source: it counts total and enabled
 * workflows per entity type, caches the pair under a tagged per-type key, and —
 * critically for a feature grafted onto native admin pages — degrades a failed
 * lookup to zero counts instead of letting it break the page.
 */
class WorkflowCountProviderTest extends TestCase
{
    private function provider(
        FakeWorkflowRepository $repository,
        FakeCache $cache
    ): WorkflowCountProvider {
        return new WorkflowCountProvider(
            $repository,
            new FakeSearchCriteriaBuilder(),
            $cache,
            new JsonSerializer(),
            new NullLogger()
        );
    }

    /**
     * @param array<int> $statuses
     * @return WorkflowInterface[]
     */
    private function workflows(array $statuses): array
    {
        $items = [];
        foreach ($statuses as $status) {
            $items[] = (new WorkflowStub())->setStatus($status)->setEntityType('sales_order');
        }
        return $items;
    }

    public function testCacheHitReturnsWithoutQueryingRepository(): void
    {
        $cache = new FakeCache();
        // Pre-seed the exact key/format getCounts() writes.
        $cache->save(
            (new JsonSerializer())->serialize(['enabled' => 2, 'total' => 5]),
            'mageos_workflows_grid_counts_sales_order',
            [WorkflowCountProvider::CACHE_TAG]
        );
        $repository = new FakeWorkflowRepository($this->workflows([WorkflowInterface::STATUS_ENABLED]));

        $counts = $this->provider($repository, $cache)->getCounts('sales_order');

        $this->assertSame(2, $counts['enabled']);
        $this->assertSame(5, $counts['total']);
        $this->assertSame(0, $repository->getListCalls, 'A cache hit must not touch the repository');
    }

    public function testCacheMissQueriesRepositoryAndCachesResult(): void
    {
        $cache = new FakeCache();
        // 3 enabled, plus one shadow and one disabled => total 5, enabled 3.
        $repository = new FakeWorkflowRepository($this->workflows([
            WorkflowInterface::STATUS_ENABLED,
            WorkflowInterface::STATUS_ENABLED,
            WorkflowInterface::STATUS_ENABLED,
            WorkflowInterface::STATUS_SHADOW,
            WorkflowInterface::STATUS_DISABLED,
        ]));

        $counts = $this->provider($repository, $cache)->getCounts('sales_order');

        $this->assertSame(3, $counts['enabled'], 'Only STATUS_ENABLED rows count as active');
        $this->assertSame(5, $counts['total']);
        $this->assertSame(1, $repository->getListCalls);
        $this->assertArrayHasKey('mageos_workflows_grid_counts_sales_order', $cache->storage);
        $this->assertSame(
            [WorkflowCountProvider::CACHE_TAG],
            $cache->tagsByIdentifier['mageos_workflows_grid_counts_sales_order'],
            'The cached entry must carry the invalidation tag'
        );
    }

    public function testRepositoryExceptionDegradesToZeroAndDoesNotCache(): void
    {
        $cache = new FakeCache();
        $repository = new FakeWorkflowRepository([], true);

        $counts = $this->provider($repository, $cache)->getCounts('sales_order');

        $this->assertSame(0, $counts['enabled']);
        $this->assertSame(0, $counts['total']);
        $this->assertSame(
            [],
            $cache->storage,
            'A failed lookup must not be cached, so the next page load retries'
        );
    }
}
