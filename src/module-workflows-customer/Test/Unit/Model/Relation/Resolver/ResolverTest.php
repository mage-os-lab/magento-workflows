<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Test\Unit\Model\Relation\Resolver;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DataObject;
use Magento\Sales\Api\OrderRepositoryInterface;
use MageOS\WorkflowsCustomer\Model\Relation\Resolver\CustomerOpenOrders;
use PHPUnit\Framework\TestCase;

/**
 * customer.open_orders seed relation resolver coverage
 * (docs/discovery/entity-cross-referencing.md §4–5). The order and quote
 * resolvers moved to mage-os/workflows-sales (domain-packs S1) with their
 * tests; customer.open_orders and this coverage moved to
 * mage-os/workflows-customer (domain-packs S2).
 *
 * Resolvers are pure repository wrappers, so these run against recording
 * SearchCriteriaBuilder + repository doubles — asserting both the ids they
 * return and the filters they build (customer scoping, open-state whitelist).
 */
class ResolverTest extends TestCase
{
    /** @var array<int, array{field: mixed, value: mixed, type: mixed}> */
    private array $filters = [];

    public function setUp(): void
    {
        $this->filters = [];
    }

    private function criteriaBuilder(): SearchCriteriaBuilder
    {
        $test = $this;
        return new class ($test) extends SearchCriteriaBuilder {
            public function __construct(private readonly ResolverTest $test)
            {
            }

            public function addFilter($field, $value, $conditionType = 'eq')
            {
                $this->test->recordFilter($field, $value, $conditionType);
                return $this;
            }

            public function create()
            {
                return new DataObject();
            }

            public function setFilterGroups($groups)
            {
                return $this;
            }

            public function addFilters($filters)
            {
                return $this;
            }

            public function setPageSize($size)
            {
                return $this;
            }

            public function setCurrentPage($page)
            {
                return $this;
            }

            public function addSortOrder($field, $direction = 'ASC')
            {
                return $this;
            }
        };
    }

    public function recordFilter(mixed $field, mixed $value, mixed $type): void
    {
        $this->filters[] = ['field' => $field, 'value' => $value, 'type' => $type];
    }

    private function filterFor(string $field): ?array
    {
        foreach ($this->filters as $filter) {
            if ($filter['field'] === $field) {
                return $filter;
            }
        }
        return null;
    }

    /**
     * @param object[] $items
     */
    private function orderRepo(array $items): OrderRepositoryInterface
    {
        return new class ($items) implements OrderRepositoryInterface {
            /** @param object[] $items */
            public function __construct(private readonly array $items)
            {
            }

            public function get($orderId)
            {
                throw new \RuntimeException('unused');
            }

            public function delete($entity)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function save($entity)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function deleteById($id)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function getList($searchCriteria)
            {
                $items = $this->items;
                return new class ($items) {
                    /** @param object[] $items */
                    public function __construct(private readonly array $items)
                    {
                    }

                    public function getItems(): array
                    {
                        return $this->items;
                    }
                };
            }
        };
    }

    private function entity(int $id, string $createdAt = ''): object
    {
        return new class ($id, $createdAt) {
            public function __construct(private readonly int $id, private readonly string $createdAt)
            {
            }

            public function getId()
            {
                return $this->id;
            }

            public function getEntityId()
            {
                return $this->id;
            }

            public function getCreatedAt()
            {
                return $this->createdAt;
            }
        };
    }

    // ---- customer.open_orders (state whitelist) ----------------------------

    public function testOpenOrdersFiltersByCustomerIdAndOpenStates(): void
    {
        $resolver = new CustomerOpenOrders(
            $this->orderRepo([$this->entity(5, '2026-01-01 00:00:00')]),
            $this->criteriaBuilder()
        );

        $ids = $resolver->resolveIds(new DataObject(['entity_id' => 77]), null);

        $this->assertSame([5], $ids);
        $this->assertSame(77, $this->filterFor('customer_id')['value']);
        $this->assertSame(['new', 'processing', 'holded'], $this->filterFor('state')['value']);
        $this->assertSame('in', $this->filterFor('state')['type']);
    }

    public function testOpenOrdersRequiresCustomerId(): void
    {
        $resolver = new CustomerOpenOrders($this->orderRepo([]), $this->criteriaBuilder());
        $this->assertSame([], $resolver->resolveIds(new DataObject(['entity_id' => 0]), null));
        $this->assertSame([], $this->filters);
    }
}
