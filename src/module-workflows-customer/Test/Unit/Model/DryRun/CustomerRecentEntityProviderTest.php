<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Test\Unit\Model\DryRun;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use MageOS\WorkflowsCustomer\Model\DryRun\CustomerRecentEntityProvider;
use PHPUnit\Framework\TestCase;

/**
 * Dry-run entity picker (03), customer provider: projection shape, newest-first
 * ordering + page size handed to the repository, and the empty-result contract.
 */
class CustomerRecentEntityProviderTest extends TestCase
{
    /** @var array<string, mixed> criteria the builders were asked to build */
    private array $criteria = [];

    public function setUp(): void
    {
        $this->criteria = [];
    }

    /**
     * @param array<int, array{0:int,1:string,2:string,3:string}> $customers id/email/first/last
     */
    private function provider(array $customers): CustomerRecentEntityProvider
    {
        $items = [];
        foreach ($customers as [$id, $email, $first, $last]) {
            $items[] = new FakeRecentCustomer($id, $email, $first, $last);
        }

        return new CustomerRecentEntityProvider(
            $this->repository($items),
            $this->searchCriteriaBuilder(),
            $this->sortOrderBuilder()
        );
    }

    /**
     * @param array<int, CustomerInterface> $items
     */
    private function repository(array $items): CustomerRepositoryInterface
    {
        return new class ($items) implements CustomerRepositoryInterface {
            /** @param array<int, CustomerInterface> $items */
            public function __construct(private readonly array $items)
            {
            }

            public function getList($searchCriteria)
            {
                return new class ($this->items) {
                    /** @param array<int, CustomerInterface> $items */
                    public function __construct(private readonly array $items)
                    {
                    }

                    public function getItems(): array
                    {
                        return $this->items;
                    }
                };
            }

            public function save($customer, $passwordHash = null)
            {
                throw new \LogicException('read-only provider');
            }

            public function get($email, $websiteId = null)
            {
                throw new \LogicException('read-only provider');
            }

            public function getById($customerId)
            {
                throw new \LogicException('read-only provider');
            }

            public function delete($customer)
            {
                throw new \LogicException('read-only provider');
            }

            public function deleteById($customerId)
            {
                throw new \LogicException('read-only provider');
            }
        };
    }

    private function searchCriteriaBuilder(): SearchCriteriaBuilder
    {
        $test = $this;
        return new class ($test) extends SearchCriteriaBuilder {
            public function __construct(private readonly CustomerRecentEntityProviderTest $test)
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
            public function __construct(private readonly CustomerRecentEntityProviderTest $test)
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
        $this->assertSame('customer', $this->provider([])->getEntityType());
    }

    public function testProjectsIdAndLabel(): void
    {
        $rows = $this->provider([
            [7, 'buyer@example.com', 'Ada', 'Lovelace'],
        ])->getRecent(20);

        $this->assertCount(1, $rows);
        $this->assertSame(7, $rows[0]['id']);
        $this->assertTrue(is_int($rows[0]['id']));
        $this->assertSame('buyer@example.com — Ada Lovelace', $rows[0]['label']);
        $this->assertTrue($rows[0]['label'] !== '');
    }

    public function testLabelFallsBackToEmailWhenNameIsBlank(): void
    {
        $rows = $this->provider([[3, 'nameless@example.com', '', '']])->getRecent(20);
        $this->assertSame('nameless@example.com', $rows[0]['label']);
    }

    public function testNewestFirstAndLimitAreHandedToTheRepository(): void
    {
        $rows = $this->provider([
            [9, 'newest@example.com', 'New', 'Est'],
            [8, 'older@example.com', 'Old', 'Er'],
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

/**
 * Full-interface CustomerInterface fake — real accessors for the fields the
 * provider reads, inert stubs for the rest so the class loads against the
 * complete interface on a real Magento install (mirrors FakeDeletedCustomer).
 */
class FakeRecentCustomer implements CustomerInterface
{
    public function __construct(
        private readonly int $id,
        private readonly string $email,
        private readonly string $firstname,
        private readonly string $lastname
    ) {
    }

    public function getId()
    {
        return $this->id;
    }

    public function getEmail()
    {
        return $this->email;
    }

    public function getFirstname()
    {
        return $this->firstname;
    }

    public function getLastname()
    {
        return $this->lastname;
    }

    // @codingStandardsIgnoreStart -- inert interface-completeness stubs.
    public function setId($id)
    {
        return $this;
    }

    public function getGroupId()
    {
        return null;
    }

    public function setGroupId($groupId)
    {
        return $this;
    }

    public function getDefaultBilling()
    {
        return null;
    }

    public function setDefaultBilling($defaultBilling)
    {
        return $this;
    }

    public function getDefaultShipping()
    {
        return null;
    }

    public function setDefaultShipping($defaultShipping)
    {
        return $this;
    }

    public function getConfirmation()
    {
        return null;
    }

    public function setConfirmation($confirmation)
    {
        return $this;
    }

    public function getCreatedAt()
    {
        return null;
    }

    public function setCreatedAt($createdAt)
    {
        return $this;
    }

    public function getUpdatedAt()
    {
        return null;
    }

    public function setUpdatedAt($updatedAt)
    {
        return $this;
    }

    public function getCreatedIn()
    {
        return null;
    }

    public function setCreatedIn($createdIn)
    {
        return $this;
    }

    public function getDob()
    {
        return null;
    }

    public function setDob($dob)
    {
        return $this;
    }

    public function setEmail($email)
    {
        return $this;
    }


    public function setFirstname($firstname)
    {
        return $this;
    }


    public function setLastname($lastname)
    {
        return $this;
    }

    public function getMiddlename()
    {
        return null;
    }

    public function setMiddlename($middlename)
    {
        return $this;
    }

    public function getPrefix()
    {
        return null;
    }

    public function setPrefix($prefix)
    {
        return $this;
    }

    public function getSuffix()
    {
        return null;
    }

    public function setSuffix($suffix)
    {
        return $this;
    }

    public function getGender()
    {
        return null;
    }

    public function setGender($gender)
    {
        return $this;
    }

    public function getStoreId()
    {
        return null;
    }

    public function setStoreId($storeId)
    {
        return $this;
    }

    public function getTaxvat()
    {
        return null;
    }

    public function setTaxvat($taxvat)
    {
        return $this;
    }

    public function getWebsiteId()
    {
        return null;
    }

    public function setWebsiteId($websiteId)
    {
        return $this;
    }

    public function getAddresses()
    {
        return null;
    }

    public function setAddresses(?array $addresses = null)
    {
        return $this;
    }

    public function getDisableAutoGroupChange()
    {
        return null;
    }

    public function setDisableAutoGroupChange($disableAutoGroupChange)
    {
        return $this;
    }

    public function getExtensionAttributes()
    {
        return null;
    }

    public function setExtensionAttributes(
        \Magento\Customer\Api\Data\CustomerExtensionInterface $extensionAttributes
    ) {
        return $this;
    }

    public function getCustomAttribute($attributeCode)
    {
        return null;
    }

    public function setCustomAttribute($attributeCode, $attributeValue)
    {
        return $this;
    }

    public function getCustomAttributes()
    {
        return [];
    }

    public function setCustomAttributes(array $attributes)
    {
        return $this;
    }
    // @codingStandardsIgnoreEnd
}
