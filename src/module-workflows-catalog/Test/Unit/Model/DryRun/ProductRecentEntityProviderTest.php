<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCatalog\Test\Unit\Model\DryRun;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use MageOS\WorkflowsCatalog\Model\DryRun\ProductRecentEntityProvider;
use PHPUnit\Framework\TestCase;

/**
 * Dry-run entity picker (03), catalog_product provider: projection shape,
 * newest-first ordering + page size handed to the repository, and the
 * empty-result contract.
 */
class ProductRecentEntityProviderTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $criteria = [];

    public function setUp(): void
    {
        $this->criteria = [];
    }

    /**
     * @param array<int, array{0:int,1:string,2:string}> $products id/sku/name
     */
    private function provider(array $products): ProductRecentEntityProvider
    {
        $items = [];
        foreach ($products as [$id, $sku, $name]) {
            $items[] = new class ($id, $sku, $name) {
                public function __construct(
                    private readonly int $id,
                    private readonly string $sku,
                    private readonly string $name
                ) {
                }

                public function getId()
                {
                    return $this->id;
                }

                public function getSku()
                {
                    return $this->sku;
                }

                public function getName()
                {
                    return $this->name;
                }
            };
        }

        return new ProductRecentEntityProvider(
            $this->repository($items),
            $this->searchCriteriaBuilder(),
            $this->sortOrderBuilder()
        );
    }

    /**
     * @param array<int, object> $items
     */
    private function repository(array $items): ProductRepositoryInterface
    {
        return new class ($items) implements ProductRepositoryInterface {
            /** @param array<int, object> $items */
            public function __construct(private readonly array $items)
            {
            }

            public function getList($searchCriteria)
            {
                return new class ($this->items) {
                    /** @param array<int, object> $items */
                    public function __construct(private readonly array $items)
                    {
                    }

                    public function getItems(): array
                    {
                        return $this->items;
                    }
                };
            }

            public function save($product, $saveOptions = false)
            {
                throw new \LogicException('read-only provider');
            }

            public function get($sku, $editMode = false, $storeId = null, $forceReload = false)
            {
                throw new \LogicException('read-only provider');
            }

            public function getById($productId, $editMode = false, $storeId = null, $forceReload = false)
            {
                throw new \LogicException('read-only provider');
            }

            public function delete($product)
            {
                throw new \LogicException('read-only provider');
            }

            public function deleteById($sku)
            {
                throw new \LogicException('read-only provider');
            }
        };
    }

    private function searchCriteriaBuilder(): SearchCriteriaBuilder
    {
        $test = $this;
        return new class ($test) extends SearchCriteriaBuilder {
            public function __construct(private readonly ProductRecentEntityProviderTest $test)
            {
            }

            public function setPageSize($size)
            {
                $this->test->record('page_size', $size);
                return $this;
            }

            public function setCurrentPage($page)
            {
                $this->test->record('current_page', $page);
                return $this;
            }

            public function addSortOrder($field, $direction = 'ASC')
            {
                $this->test->record('sort_order_applied', true);
                return $this;
            }

            public function create()
            {
                return null;
            }
        };
    }

    private function sortOrderBuilder(): SortOrderBuilder
    {
        $test = $this;
        return new class ($test) extends SortOrderBuilder {
            public function __construct(private readonly ProductRecentEntityProviderTest $test)
            {
            }

            public function setField($field)
            {
                $this->test->record('sort_field', $field);
                return $this;
            }

            public function setDirection($direction)
            {
                $this->test->record('sort_direction', $direction);
                return $this;
            }

            public function create()
            {
                return null;
            }
        };
    }

    /**
     * @param mixed $value
     */
    public function record(string $key, $value): void
    {
        $this->criteria[$key] = $value;
    }

    public function testEntityType(): void
    {
        $this->assertSame('catalog_product', $this->provider([])->getEntityType());
    }

    public function testProjectsIdAndSkuNameLabel(): void
    {
        $rows = $this->provider([[42, 'SKU-1', 'Widget']])->getRecent(20);

        $this->assertCount(1, $rows);
        $this->assertSame(42, $rows[0]['id']);
        $this->assertTrue(is_int($rows[0]['id']));
        $this->assertSame('SKU-1 — Widget', $rows[0]['label']);
        $this->assertTrue($rows[0]['label'] !== '');
    }

    public function testNewestFirstAndLimitAreHandedToTheRepository(): void
    {
        $rows = $this->provider([
            [9, 'SKU-9', 'Newest'],
            [8, 'SKU-8', 'Older'],
        ])->getRecent(5);

        $this->assertSame([9, 8], array_column($rows, 'id'));
        $this->assertSame('created_at', $this->criteria['sort_field']);
        $this->assertSame('DESC', $this->criteria['sort_direction']);
        $this->assertTrue($this->criteria['sort_order_applied']);
        $this->assertSame(5, $this->criteria['page_size']);
        $this->assertSame(1, $this->criteria['current_page']);
    }

    public function testEmptyResultYieldsEmptyList(): void
    {
        $this->assertSame([], $this->provider([])->getRecent(20));
    }
}
