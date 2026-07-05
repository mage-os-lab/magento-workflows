<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Relation\Resolver;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DataObject;
use Magento\Sales\Api\OrderRepositoryInterface;
use MageOS\Workflows\Model\Relation\Resolver\CustomerOpenOrders;
use MageOS\Workflows\Model\Relation\Resolver\OrderCustomer;
use MageOS\Workflows\Model\Relation\Resolver\OrderCustomerByEmail;
use MageOS\Workflows\Model\Relation\Resolver\OrderOrdersByEmail;
use MageOS\Workflows\Model\Relation\Resolver\QuoteCustomerByEmail;
use PHPUnit\Framework\TestCase;

/**
 * Seed relation resolvers (docs/discovery/entity-cross-referencing.md §4–5).
 * Resolvers are pure repository wrappers, so these run against recording
 * SearchCriteriaBuilder + repository doubles — asserting both the ids they
 * return and the filters they build (website scoping, self-exclusion, states).
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
    private function customerRepo(array $items): CustomerRepositoryInterface
    {
        return new class ($items) implements CustomerRepositoryInterface {
            /** @param object[] $items */
            public function __construct(private readonly array $items)
            {
            }

            public function getById($customerId)
            {
                throw new \RuntimeException('unused');
            }

            public function save($customer, $passwordHash = null)
            {
                throw new \RuntimeException('unused');
            }

            public function get($email, $websiteId = null)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function delete($customer)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function deleteById($customerId)
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

    // ---- order.customer (FK, no query) -------------------------------------

    public function testOrderCustomerReadsForeignKey(): void
    {
        $resolver = new OrderCustomer();
        $this->assertSame([15], $resolver->resolveIds(new DataObject(['customer_id' => 15]), null));
        $this->assertSame([], $resolver->resolveIds(new DataObject(['customer_id' => 0]), null));
        $this->assertSame('one', $resolver->getCardinality());
    }

    // ---- customer-by-email (website scoping) -------------------------------

    public function testOrderCustomerByEmailGlobalShareIsUnscoped(): void
    {
        $resolver = new OrderCustomerByEmail($this->customerRepo([$this->entity(9)]), $this->criteriaBuilder());

        // websiteId null == global account sharing (RelationContext decided it).
        $ids = $resolver->resolveIds(new DataObject(['customer_email' => 'Guest@Example.com']), null);

        $this->assertSame([9], $ids);
        $this->assertSame('guest@example.com', $this->filterFor('email')['value'], 'email lower-cased');
        $this->assertNull($this->filterFor('website_id'), 'no website filter under global sharing');
    }

    public function testOrderCustomerByEmailPerWebsiteScopesTheLookup(): void
    {
        $resolver = new OrderCustomerByEmail($this->customerRepo([$this->entity(9)]), $this->criteriaBuilder());

        $resolver->resolveIds(new DataObject(['customer_email' => 'a@b.com']), 4);

        $this->assertSame(4, $this->filterFor('website_id')['value']);
    }

    public function testCustomerByEmailBlankEmailResolvesToNone(): void
    {
        $resolver = new QuoteCustomerByEmail($this->customerRepo([$this->entity(9)]), $this->criteriaBuilder());

        $this->assertSame([], $resolver->resolveIds(new DataObject(['customer_email' => '  ']), null));
        $this->assertSame([], $this->filters, 'no query for a guest with no email');
    }

    public function testQuoteCustomerByEmailSourceType(): void
    {
        $resolver = new QuoteCustomerByEmail($this->customerRepo([]), $this->criteriaBuilder());
        $this->assertSame('quote', $resolver->getSourceEntityType());
    }

    // ---- order.orders_by_email (self + canceled excluded, newest-first) ----

    public function testOrdersByEmailExcludesSelfAndCanceledNewestFirst(): void
    {
        $items = [
            $this->entity(11, '2026-01-01 00:00:00'),
            $this->entity(33, '2026-03-01 00:00:00'),
            $this->entity(22, '2026-02-01 00:00:00'),
        ];
        $resolver = new OrderOrdersByEmail($this->orderRepo($items), $this->criteriaBuilder());

        $ids = $resolver->resolveIds(new DataObject(['customer_email' => 'x@y.com', 'entity_id' => 99]), null);

        $this->assertSame([33, 22, 11], $ids, 'newest-first by created_at');
        $this->assertSame(['canceled'], $this->filterFor('state')['value']);
        $this->assertSame('nin', $this->filterFor('state')['type']);
        $this->assertSame(99, $this->filterFor('entity_id')['value']);
        $this->assertSame('neq', $this->filterFor('entity_id')['type']);
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
