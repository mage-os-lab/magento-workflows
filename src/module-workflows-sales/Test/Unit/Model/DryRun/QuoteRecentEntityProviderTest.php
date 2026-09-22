<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Test\Unit\Model\DryRun;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use MageOS\WorkflowsSales\Model\DryRun\QuoteRecentEntityProvider;
use PHPUnit\Framework\TestCase;

/**
 * Dry-run entity picker (03), quote provider: projection shape (incl. the
 * guest fallback), newest-first ordering + page size handed to the repository,
 * and the empty-result contract.
 */
class QuoteRecentEntityProviderTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $criteria = [];

    public function setUp(): void
    {
        $this->criteria = [];
    }

    /**
     * @param array<int, array{0:int,1:string,2:string}> $quotes id/email/grand total
     */
    private function provider(array $quotes): QuoteRecentEntityProvider
    {
        $items = [];
        foreach ($quotes as [$id, $email, $total]) {
            $items[] = new FakeRecentQuote($id, $email, $total);
        }

        return new QuoteRecentEntityProvider(
            $this->repository($items),
            $this->searchCriteriaBuilder(),
            $this->sortOrderBuilder()
        );
    }

    /**
     * @param array<int, Quote> $items
     */
    private function repository(array $items): CartRepositoryInterface
    {
        return new class ($items) implements CartRepositoryInterface {
            /** @param array<int, Quote> $items */
            public function __construct(private readonly array $items)
            {
            }

            public function getList($searchCriteria)
            {
                return new class ($this->items) {
                    /** @param array<int, Quote> $items */
                    public function __construct(private readonly array $items)
                    {
                    }

                    public function getItems(): array
                    {
                        return $this->items;
                    }
                };
            }

            public function get($cartId, array $sharedStoreIds = [])
            {
                throw new \LogicException('read-only provider');
            }

            public function getForCustomer($customerId, array $sharedStoreIds = [])
            {
                throw new \LogicException('read-only provider');
            }

            public function getActive($cartId, array $sharedStoreIds = [])
            {
                throw new \LogicException('read-only provider');
            }

            public function getActiveForCustomer($customerId, array $sharedStoreIds = [])
            {
                throw new \LogicException('read-only provider');
            }

            public function save($quote)
            {
                throw new \LogicException('read-only provider');
            }

            public function delete($quote)
            {
                throw new \LogicException('read-only provider');
            }
        };
    }

    private function searchCriteriaBuilder(): SearchCriteriaBuilder
    {
        $test = $this;
        return new class ($test) extends SearchCriteriaBuilder {
            public function __construct(private readonly QuoteRecentEntityProviderTest $test)
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
            public function __construct(private readonly QuoteRecentEntityProviderTest $test)
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
        $this->assertSame('quote', $this->provider([])->getEntityType());
    }

    public function testProjectsIdEmailAndGrandTotal(): void
    {
        $rows = $this->provider([[15, 'shopper@example.com', '99.50']])->getRecent(20);

        $this->assertCount(1, $rows);
        $this->assertSame(15, $rows[0]['id']);
        $this->assertTrue(is_int($rows[0]['id']));
        $this->assertSame('#15 — shopper@example.com — 99.50', $rows[0]['label']);
        $this->assertTrue($rows[0]['label'] !== '');
    }

    public function testGuestQuoteIsLabelledGuest(): void
    {
        $rows = $this->provider([[16, '', '10.00']])->getRecent(20);
        $this->assertSame('#16 — Guest — 10.00', $rows[0]['label']);
    }

    public function testNewestFirstAndLimitAreHandedToTheRepository(): void
    {
        $rows = $this->provider([
            [9, 'newest@example.com', '5.00'],
            [8, 'older@example.com', '6.00'],
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
 * Quote-model stand-in: the provider reads customer email and grand total off
 * the model (neither lives on CartInterface), so the fake subclasses Quote.
 */
class FakeRecentQuote extends Quote
{
    public function __construct(
        private readonly int $id,
        private readonly string $email,
        private readonly string $grandTotal
    ) {
    }

    public function getId()
    {
        return $this->id;
    }

    public function getCustomerEmail()
    {
        return $this->email;
    }

    public function getGrandTotal()
    {
        return $this->grandTotal;
    }
}
