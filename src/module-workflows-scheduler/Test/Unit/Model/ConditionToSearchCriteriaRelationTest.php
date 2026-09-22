<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Unit\Model;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use MageOS\WorkflowsScheduler\Model\ConditionToSearchCriteria;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * A relation-bearing condition tree is never expressible as a SearchCriteria
 * (docs/discovery/entity-cross-referencing.md §5): the RelatedEntity node is a
 * nested combine (or an attribute-less node), so ConditionToSearchCriteria
 * already returns null and the scheduler falls back to load-and-filter. No
 * scheduler change is needed — this asserts that existing null path, and that
 * it is reached WITHOUT touching the query builders.
 */
class ConditionToSearchCriteriaRelationTest extends TestCase
{
    private function converter(): ConditionToSearchCriteria
    {
        // Builders that explode if used: the relation path must return null
        // before any of them is touched.
        $searchCriteriaBuilder = new class extends SearchCriteriaBuilder {
            public function __construct()
            {
            }

            public function create()
            {
                throw new \RuntimeException('builder must not be used on the relation fallback path');
            }

            public function setFilterGroups($groups)
            {
                throw new \RuntimeException('unused');
            }

            public function addFilter($field, $value, $conditionType = 'eq')
            {
                throw new \RuntimeException('unused');
            }

            public function addFilters($filters)
            {
                throw new \RuntimeException('unused');
            }

            public function setPageSize($size)
            {
                throw new \RuntimeException('unused');
            }

            public function setCurrentPage($page)
            {
                throw new \RuntimeException('unused');
            }

            public function addSortOrder($field, $direction = 'ASC')
            {
                throw new \RuntimeException('unused');
            }
        };
        $filterBuilder = new class extends FilterBuilder {
            public function __construct()
            {
            }

            public function create()
            {
                throw new \RuntimeException('unused');
            }

            public function setField($field)
            {
                throw new \RuntimeException('unused');
            }

            public function setValue($value)
            {
                throw new \RuntimeException('unused');
            }

            public function setConditionType($type)
            {
                throw new \RuntimeException('unused');
            }
        };
        $filterGroupBuilder = new class extends FilterGroupBuilder {
            public function __construct()
            {
            }

            public function create()
            {
                throw new \RuntimeException('unused');
            }

            public function setFilters($filters)
            {
                throw new \RuntimeException('unused');
            }
        };

        return new ConditionToSearchCriteria(
            $searchCriteriaBuilder,
            $filterBuilder,
            $filterGroupBuilder,
            new NullLogger()
        );
    }

    public function testRelatedEntityWithChildrenIsUnmappable(): void
    {
        $tree = json_encode([
            'aggregator' => 'all',
            'conditions' => [
                [
                    'type' => 'MageOS\\Workflows\\Model\\Rule\\Condition\\RelatedEntity\\Combine',
                    'relation' => 'order.customer_by_email',
                    'value' => '1',
                    'conditions' => [
                        ['attribute' => 'orders_count', 'operator' => '>=', 'value' => '3'],
                    ],
                ],
            ],
        ]);

        $this->assertNull($this->converter()->convert((string) $tree, 'sales_order'));
    }

    public function testChildlessNotExistsRelationIsUnmappable(): void
    {
        // A bare NOT EXISTS node carries no attribute — the flat mapper cannot
        // express it and must fall back.
        $tree = json_encode([
            'aggregator' => 'all',
            'conditions' => [
                [
                    'type' => 'MageOS\\Workflows\\Model\\Rule\\Condition\\RelatedEntity\\Combine',
                    'relation' => 'order.customer_by_email',
                    'value' => '0',
                ],
            ],
        ]);

        $this->assertNull($this->converter()->convert((string) $tree, 'sales_order'));
    }
}
