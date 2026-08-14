<?php
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Unit\Model;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use MageOS\WorkflowsScheduler\Model\ConditionToSearchCriteria;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * Relative-date values ('-72 hours', '+2 weeks') in a schedule workflow's
 * root condition must be resolved to concrete UTC day boundaries before they
 * reach SQL — the raw expression is meaningless to MySQL, and before this was
 * fixed the bundled unpaid-order-cleanup-sweep template matched zero rows on
 * every run. The boundaries mirror AbstractWorkflowCondition's day-granular,
 * boundary-day-inclusive evaluation semantics; operators whose day-equality
 * SQL can't express (==, !=, (), !()) stay unmappable so the scheduler falls
 * back to in-process evaluation.
 */
class ConditionToSearchCriteriaRelativeDateTest extends TestCase
{
    /** @var array<int, array{field: mixed, value: mixed, conditionType: mixed}> */
    private array $recordedFilters = [];

    private function converter(): ConditionToSearchCriteria
    {
        $this->recordedFilters = [];
        $recorded = &$this->recordedFilters;

        $filterBuilder = new class ($recorded) extends FilterBuilder {
            private array $pending = [];

            /** @param array<int, array> $recorded */
            public function __construct(private array &$recorded)
            {
            }

            public function setField($field)
            {
                $this->pending['field'] = $field;
                return $this;
            }

            public function setValue($value)
            {
                $this->pending['value'] = $value;
                return $this;
            }

            public function setConditionType($type)
            {
                $this->pending['conditionType'] = $type;
                return $this;
            }

            public function create()
            {
                $this->recorded[] = $this->pending;
                $this->pending = [];
                return new \stdClass();
            }
        };

        $filterGroupBuilder = new class extends FilterGroupBuilder {
            public function __construct()
            {
            }

            public function setFilters($filters)
            {
                return $this;
            }

            public function create()
            {
                return new \stdClass();
            }
        };

        $searchCriteriaBuilder = new class extends SearchCriteriaBuilder {
            public function __construct()
            {
            }

            public function setFilterGroups($groups)
            {
                return $this;
            }

            public function create()
            {
                return new class implements SearchCriteriaInterface {
                };
            }
        };

        return new ConditionToSearchCriteria(
            $searchCriteriaBuilder,
            $filterBuilder,
            $filterGroupBuilder,
            new NullLogger()
        );
    }

    /**
     * Day candidates computed immediately before and after the call under
     * test, so an assertion never flakes across a UTC midnight rollover.
     *
     * @return string[]
     */
    private function dayCandidates(string $expression, callable $act): array
    {
        $before = (new \DateTime($expression, new \DateTimeZone('UTC')))->format('Y-m-d');
        $act();
        $after = (new \DateTime($expression, new \DateTimeZone('UTC')))->format('Y-m-d');
        return array_unique([$before, $after]);
    }

    private function tree(string $operator, string $value): string
    {
        return (string) json_encode([
            'aggregator' => 'all',
            'conditions' => [
                ['attribute' => 'created_at', 'operator' => $operator, 'value' => $value],
            ],
        ]);
    }

    public function testLessOrEqualResolvesToInclusiveEndOfDay(): void
    {
        $converter = $this->converter();
        $result = null;
        $days = $this->dayCandidates('-72 hours', function () use ($converter, &$result) {
            $result = $converter->convert($this->tree('<=', '-72 hours'), 'sales_order');
        });

        $this->assertInstanceOf(SearchCriteriaInterface::class, $result);
        $this->assertCount(1, $this->recordedFilters);
        $filter = $this->recordedFilters[0];
        $this->assertSame('created_at', $filter['field']);
        $this->assertSame('lteq', $filter['conditionType']);
        $this->assertTrue(in_array($filter['value'], array_map(fn ($d) => $d . ' 23:59:59', $days), true), 'unexpected boundary: ' . var_export($filter['value'], true));
    }

    public function testGreaterOrEqualResolvesToStartOfDay(): void
    {
        $converter = $this->converter();
        $result = null;
        $days = $this->dayCandidates('-30 days', function () use ($converter, &$result) {
            $result = $converter->convert($this->tree('>=', '-30 days'), 'sales_order');
        });

        $this->assertInstanceOf(SearchCriteriaInterface::class, $result);
        $filter = $this->recordedFilters[0];
        $this->assertSame('gteq', $filter['conditionType']);
        $this->assertTrue(in_array($filter['value'], array_map(fn ($d) => $d . ' 00:00:00', $days), true), 'unexpected boundary: ' . var_export($filter['value'], true));
    }

    public function testGreaterResolvesToEndOfDaySoTheBoundaryDayNeverMatches(): void
    {
        $converter = $this->converter();
        $result = null;
        $days = $this->dayCandidates('+2 weeks', function () use ($converter, &$result) {
            $result = $converter->convert($this->tree('>', '+2 weeks'), 'sales_order');
        });

        $this->assertInstanceOf(SearchCriteriaInterface::class, $result);
        $filter = $this->recordedFilters[0];
        $this->assertSame('gt', $filter['conditionType']);
        $this->assertTrue(in_array($filter['value'], array_map(fn ($d) => $d . ' 23:59:59', $days), true), 'unexpected boundary: ' . var_export($filter['value'], true));
    }

    public function testSpaceAfterSignIsAccepted(): void
    {
        $converter = $this->converter();
        $result = null;
        $days = $this->dayCandidates('-1 day', function () use ($converter, &$result) {
            $result = $converter->convert($this->tree('<', '- 1 day'), 'sales_order');
        });

        $this->assertInstanceOf(SearchCriteriaInterface::class, $result);
        $filter = $this->recordedFilters[0];
        $this->assertSame('lt', $filter['conditionType']);
        $this->assertTrue(in_array($filter['value'], array_map(fn ($d) => $d . ' 00:00:00', $days), true), 'unexpected boundary: ' . var_export($filter['value'], true));
    }

    public function testEqualityOnRelativeDateIsUnmappable(): void
    {
        // Day-equality against a DATETIME column can't be expressed as a flat
        // eq filter; the scheduler must fall back to in-process evaluation.
        $this->assertNull(
            $this->converter()->convert($this->tree('==', '-3 days'), 'sales_order')
        );
        $this->assertNull(
            $this->converter()->convert($this->tree('!=', '-3 days'), 'sales_order')
        );
    }

    public function testAbsoluteDateValuePassesThroughVerbatim(): void
    {
        $converter = $this->converter();
        $result = $converter->convert($this->tree('<=', '2026-01-01'), 'sales_order');

        $this->assertInstanceOf(SearchCriteriaInterface::class, $result);
        $this->assertSame('2026-01-01', $this->recordedFilters[0]['value']);
    }
}
