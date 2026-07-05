<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminExtension\Test\Unit\Plugin;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Test\Unit\Stub\WorkflowStub;
use MageOS\WorkflowsAdminExtension\Model\WorkflowCountProvider;
use MageOS\WorkflowsAdminExtension\Plugin\InvalidateCountCache;
use MageOS\WorkflowsAdminExtension\Test\Unit\Stub\FakeCache;
use PHPUnit\Framework\TestCase;

/**
 * Every workflow write funnels through the repository, so an after-plugin there
 * is the one place that keeps the strip's cached counts honest. These tests pin
 * that each of save/delete/deleteById cleans the count cache tag and passes the
 * wrapped result through untouched.
 */
class InvalidateCountCacheTest extends TestCase
{
    private function subject(): WorkflowRepositoryInterface
    {
        return new class implements WorkflowRepositoryInterface {
            public function save(WorkflowInterface $workflow): WorkflowInterface
            {
                return $workflow;
            }

            public function getById(int $workflowId): WorkflowInterface
            {
                throw new \RuntimeException('not used');
            }

            public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
            {
                throw new \RuntimeException('not used');
            }

            public function delete(WorkflowInterface $workflow): bool
            {
                return true;
            }

            public function deleteById(int $workflowId): bool
            {
                return true;
            }
        };
    }

    public function testAfterSaveCleansTheTagAndReturnsTheWorkflow(): void
    {
        $cache = new FakeCache();
        $plugin = new InvalidateCountCache($cache);
        $workflow = new WorkflowStub(7);

        $result = $plugin->afterSave($this->subject(), $workflow);

        $this->assertSame($workflow, $result);
        $this->assertCount(1, $cache->cleanCalls);
        $this->assertSame([WorkflowCountProvider::CACHE_TAG], $cache->cleanCalls[0]);
    }

    public function testAfterDeleteCleansTheTagAndReturnsResult(): void
    {
        $cache = new FakeCache();
        $plugin = new InvalidateCountCache($cache);

        $result = $plugin->afterDelete($this->subject(), true);

        $this->assertTrue($result);
        $this->assertCount(1, $cache->cleanCalls);
        $this->assertSame([WorkflowCountProvider::CACHE_TAG], $cache->cleanCalls[0]);
    }

    public function testAfterDeleteByIdCleansTheTagAndReturnsResult(): void
    {
        $cache = new FakeCache();
        $plugin = new InvalidateCountCache($cache);

        $result = $plugin->afterDeleteById($this->subject(), true);

        $this->assertTrue($result);
        $this->assertCount(1, $cache->cleanCalls);
        $this->assertSame([WorkflowCountProvider::CACHE_TAG], $cache->cleanCalls[0]);
    }

    public function testInvalidationEvictsAPreviouslyCachedCount(): void
    {
        $cache = new FakeCache();
        $cache->save('{"enabled":1,"total":1}', 'mageos_workflows_grid_counts_sales_order', [WorkflowCountProvider::CACHE_TAG]);
        $plugin = new InvalidateCountCache($cache);

        $plugin->afterSave($this->subject(), new WorkflowStub(1));

        $this->assertSame([], $cache->storage, 'Cleaning the tag must drop the stale entry');
    }
}
