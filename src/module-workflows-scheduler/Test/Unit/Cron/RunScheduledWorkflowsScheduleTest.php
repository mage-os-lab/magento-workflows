<?php
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Unit\Cron;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\WorkflowIndex;
use MageOS\WorkflowsScheduler\Cron\RunScheduledWorkflows;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\FakeResourceConnection;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\RecordingLogger;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\RecordingQueryRunner;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\ScheduleStateConnection;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\WorkflowStub;
use PHPUnit\Framework\TestCase;

// The standalone runner's shim autoloader only serves Magento\ / Psr\Log\
// classes; dragonmantank/cron-expression's Cron\CronExpression is shimmed
// under dev/tests/shims/Cron/ and loaded here explicitly. Real Composer
// environments (real library installed) never reach the require.
if (!class_exists(\Cron\CronExpression::class)) {
    require_once dirname(__DIR__, 5) . '/dev/tests/shims/Cron/CronExpression.php';
}

/**
 * Schedule-evaluation behaviors of RunScheduledWorkflows, per
 * docs/05-triggers.md#scheduled-triggers-workflows-scheduler and the class
 * docblock (the index-gate short-circuit is covered separately in
 * RunScheduledWorkflowsTest):
 *
 * - cron expressions evaluate in the workflow's STORE timezone, resolved
 *   store-scoped via the first website's default store — "the #1
 *   support-ticket generator in every scheduler ever shipped";
 * - the schedule-state table guards against a second fire within the same
 *   wall-clock minute;
 * - an invalid cron expression is logged and skipped without aborting the
 *   sweep (later workflows still run);
 * - the watermark QueryRunner returns is persisted, and the persisted
 *   watermark is handed back to QueryRunner on the next due tick.
 *
 * The runner can't freeze the wall clock (the cron news up its own "now"),
 * so due/not-due expressions are DERIVED from the current time in the
 * relevant timezone, listing both the current and the next minute/hour so a
 * clock rollover mid-test cannot flip the expectation.
 */
class RunScheduledWorkflowsScheduleTest extends TestCase
{
    // Public: the anonymous store-manager fake below is a distinct class,
    // so private constants would be inaccessible from it (the resulting
    // Error would be swallowed by resolveTimezone()'s fallback).
    public const STORE_ID = 5;
    public const WEBSITE_ID = 7;

    /**
     * A cron expression due "now" (and for the following minute) in $timezone:
     * minute list {m, m+1}, hour list {h, h+1}. The cross product absorbs a
     * minute/hour/day rollover between building the expression and the cron
     * evaluating it, while staying hours away from any other timezone whose
     * offset differs by more than one hour.
     */
    private function expressionDueNowIn(string $timezone): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone($timezone));
        $minute = (int) $now->format('i');
        $hour = (int) $now->format('G');

        return sprintf(
            '%d,%d %d,%d * * *',
            $minute,
            ($minute + 1) % 60,
            $hour,
            ($hour + 1) % 24
        );
    }

    /** @param int[] $ids */
    private function workflowIndex(array $ids): WorkflowIndex
    {
        return new class ($ids) extends WorkflowIndex {
            /** @param int[] $ids */
            public function __construct(private readonly array $ids)
            {
            }

            public function getScheduledWorkflowIds(): array
            {
                return $this->ids;
            }
        };
    }

    /** @param WorkflowInterface[] $workflows */
    private function repository(array $workflows): WorkflowRepositoryInterface
    {
        return new class ($workflows) implements WorkflowRepositoryInterface {
            /** @param WorkflowInterface[] $workflows */
            public function __construct(private readonly array $workflows)
            {
            }

            public function save(WorkflowInterface $workflow): WorkflowInterface
            {
                throw new \LogicException('not used in this test');
            }

            public function getById(int $workflowId): WorkflowInterface
            {
                throw new \LogicException('not used in this test');
            }

            public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
            {
                $workflows = $this->workflows;
                return new class ($workflows) implements SearchResultsInterface {
                    /** @param WorkflowInterface[] $items */
                    public function __construct(private readonly array $items)
                    {
                    }

                    /** @return WorkflowInterface[] */
                    public function getItems(): array
                    {
                        return $this->items;
                    }

                    public function setItems(array $items)
                    {
                        throw new \BadMethodCallException(__METHOD__);
                    }

                    public function getSearchCriteria()
                    {
                        throw new \BadMethodCallException(__METHOD__);
                    }

                    public function setSearchCriteria($searchCriteria)
                    {
                        throw new \BadMethodCallException(__METHOD__);
                    }

                    public function getTotalCount()
                    {
                        throw new \BadMethodCallException(__METHOD__);
                    }

                    public function setTotalCount($totalCount)
                    {
                        throw new \BadMethodCallException(__METHOD__);
                    }
                };
            }

            public function delete(WorkflowInterface $workflow): bool
            {
                throw new \LogicException('not used in this test');
            }

            public function deleteById(int $workflowId): bool
            {
                throw new \LogicException('not used in this test');
            }
        };
    }

    private function searchCriteriaBuilder(): SearchCriteriaBuilder
    {
        return new class extends SearchCriteriaBuilder {
            public function __construct()
            {
            }

            public function create()
            {
                return new class implements SearchCriteriaInterface {
                    public function getFilterGroups()
                    {
                        return [];
                    }

                    public function setFilterGroups(?array $filterGroups = null)
                    {
                        return $this;
                    }

                    public function getSortOrders()
                    {
                        return [];
                    }

                    public function setSortOrders(?array $sortOrders = null)
                    {
                        return $this;
                    }

                    public function getPageSize()
                    {
                        return null;
                    }

                    public function setPageSize($pageSize)
                    {
                        return $this;
                    }

                    public function getCurrentPage()
                    {
                        return null;
                    }

                    public function setCurrentPage($currentPage)
                    {
                        return $this;
                    }
                };
            }

            public function setFilterGroups($groups)
            {
                return $this;
            }

            public function addFilter($field, $value, $conditionType = 'eq')
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

    private function filterBuilder(): FilterBuilder
    {
        return new class extends FilterBuilder {
            public function __construct()
            {
            }

            public function create()
            {
                return new DataObject();
            }

            public function setField($field)
            {
                return $this;
            }

            public function setValue($value)
            {
                return $this;
            }

            public function setConditionType($type)
            {
                return $this;
            }
        };
    }

    private function filterGroupBuilder(): FilterGroupBuilder
    {
        return new class extends FilterGroupBuilder {
            public function __construct()
            {
            }

            public function create()
            {
                return new DataObject();
            }

            public function setFilters($filters)
            {
                return $this;
            }
        };
    }

    /**
     * Store manager resolving WEBSITE_ID to a website whose default store id
     * is STORE_ID; anything else throws.
     */
    private function storeManager(): StoreManagerInterface
    {
        return new class implements StoreManagerInterface {
            public function getStore($storeId = null)
            {
                throw new \LogicException('not used in this test');
            }

            public function getWebsite($websiteId = null)
            {
                if ((int) $websiteId !== RunScheduledWorkflowsScheduleTest::WEBSITE_ID) {
                    throw new \LogicException('unexpected website id ' . var_export($websiteId, true));
                }
                return new class {
                    public function getDefaultStore()
                    {
                        return new class {
                            public function getId(): int
                            {
                                return RunScheduledWorkflowsScheduleTest::STORE_ID;
                            }
                        };
                    }
                };
            }

            public function setIsSingleStoreModeAllowed($value)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function hasSingleStore()
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function isSingleStoreMode()
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function getStores($withDefault = false, $codeKey = false)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function getWebsites($withDefault = false, $codeKey = false)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function reinitStores()
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function getDefaultStoreView()
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function getGroup($groupId = NULL)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function getGroups($withDefault = false)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function setCurrentStore($store)
            {
                throw new \BadMethodCallException(__METHOD__);
            }        };
    }

    /**
     * Timezone fake: $storeTimezone for store-scoped lookups, $defaultTimezone
     * for the no-scope fallback; records every getConfigTimezone() call.
     */
    private function timezone(string $storeTimezone, string $defaultTimezone = 'UTC'): TimezoneInterface
    {
        return new class ($storeTimezone, $defaultTimezone) implements TimezoneInterface {
            /** @var array<int, array{0:?string, 1:mixed}> [scope type, scope code] */
            public array $calls = [];

            public function __construct(
                private readonly string $storeTimezone,
                private readonly string $defaultTimezone
            ) {
            }

            public function getConfigTimezone($scopeType = null, $scopeCode = null): string
            {
                $this->calls[] = [$scopeType, $scopeCode];
                return $scopeCode === null ? $this->defaultTimezone : $this->storeTimezone;
            }

            public function getDefaultTimezonePath()
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function getDefaultTimezone()
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function getDateFormat($type = \IntlDateFormatter::SHORT)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function getDateFormatWithLongYear()
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function getTimeFormat($type = NULL)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function getDateTimeFormat($type)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function date($date = NULL, $locale = NULL, $useTimezone = true, $includeTime = true)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function scopeDate($scope = NULL, $date = NULL, $includeTime = false)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function scopeTimeStamp($scope = NULL)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function formatDate($date = NULL, $format = \IntlDateFormatter::SHORT, $showTime = false)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function isScopeDateInInterval($scope, $dateFrom = NULL, $dateTo = NULL)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function formatDateTime($date, $dateType = \IntlDateFormatter::SHORT, $timeType = \IntlDateFormatter::SHORT, $locale = NULL, $timezone = NULL, $pattern = NULL)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function convertConfigTimeToUtc($date, $format = 'Y-m-d H:i:s')
            {
                throw new \BadMethodCallException(__METHOD__);
            }        };
    }

    private function cron(
        array $workflows,
        RecordingQueryRunner $queryRunner,
        ScheduleStateConnection $state,
        TimezoneInterface $timezone,
        ?RecordingLogger $logger = null
    ): RunScheduledWorkflows {
        $ids = [];
        foreach ($workflows as $workflow) {
            $ids[] = (int) $workflow->getWorkflowId();
        }

        return new RunScheduledWorkflows(
            $this->repository($workflows),
            $this->searchCriteriaBuilder(),
            $this->filterBuilder(),
            $this->filterGroupBuilder(),
            $this->storeManager(),
            $timezone,
            new FakeResourceConnection($state),
            $queryRunner,
            $logger ?? new RecordingLogger(),
            $this->workflowIndex($ids)
        );
    }

    public function testCronExpressionEvaluatesInStoreLocalTimezone(): void
    {
        // A schedule expressed in the store's LOCAL clock (America/New_York,
        // UTC-4/-5) must be due right now even though the same wall-clock
        // reading in UTC is hours away — the docs' "0 9 * * * fires at
        // 13:00/14:00 UTC" contract, built dynamically because the cron reads
        // the real clock.
        $workflow = new WorkflowStub([
            'workflow_id' => 1,
            'trigger_ref' => $this->expressionDueNowIn('America/New_York'),
            'website_ids' => [self::WEBSITE_ID],
        ]);
        $queryRunner = new RecordingQueryRunner('wm-1');
        $timezone = $this->timezone('America/New_York');

        $this->cron([$workflow], $queryRunner, new ScheduleStateConnection(), $timezone)->execute();

        $this->assertCount(
            1,
            $queryRunner->calls,
            'a schedule due now in the store timezone must dispatch'
        );
        // And the timezone was resolved store-scoped, for the workflow
        // website's default store — not the server/default scope.
        $this->assertSame(ScopeInterface::SCOPE_STORE, $timezone->calls[0][0]);
        $this->assertSame(self::STORE_ID, $timezone->calls[0][1]);
    }

    public function testServerClockScheduleDoesNotFireInStoreTimezone(): void
    {
        // Same store (America/New_York) but the expression names the CURRENT
        // UTC hour. If the cron wrongly evaluated in server/UTC time this
        // would fire; in New York local time that hour is 4-5 hours away, so
        // it must not. (Docs: "0 9 * * *" must NOT fire at 09:00 UTC.)
        $workflow = new WorkflowStub([
            'workflow_id' => 1,
            'trigger_ref' => $this->expressionDueNowIn('UTC'),
            'website_ids' => [self::WEBSITE_ID],
        ]);
        $queryRunner = new RecordingQueryRunner('wm-1');

        $this->cron(
            [$workflow],
            $queryRunner,
            new ScheduleStateConnection(),
            $this->timezone('America/New_York')
        )->execute();

        $this->assertCount(
            0,
            $queryRunner->calls,
            'a schedule due now in UTC must NOT dispatch when the store clock is America/New_York'
        );
    }

    public function testSecondTickWithinSameMinuteDoesNotDoubleFire(): void
    {
        // An always-due workflow ticked twice in the same wall-clock minute:
        // the schedule-state row written by the first tick must suppress the
        // second. Retried if the UTC minute happens to roll over mid-test
        // (in a fresh state table each attempt), so the assertion is only
        // ever made about two ticks inside one minute.
        $queryRunner = new RecordingQueryRunner('wm-1');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $workflow = new WorkflowStub([
                'workflow_id' => 1,
                'trigger_ref' => '* * * * *',
                'website_ids' => [],
            ]);
            $queryRunner = new RecordingQueryRunner('wm-1');
            $state = new ScheduleStateConnection();
            $cron = $this->cron([$workflow], $queryRunner, $state, $this->timezone('UTC'));

            $minuteBefore = gmdate('Y-m-d H:i');
            $cron->execute();
            $cron->execute();
            if ($minuteBefore === gmdate('Y-m-d H:i')) {
                break;
            }
        }

        $this->assertCount(
            1,
            $queryRunner->calls,
            'two cron ticks within one minute must dispatch exactly one run (schedule-state guard)'
        );
    }

    public function testInvalidCronExpressionIsLoggedAndSkippedWithoutAbortingSweep(): void
    {
        $broken = new WorkflowStub([
            'workflow_id' => 1,
            'trigger_ref' => 'not-a-cron',
            'website_ids' => [],
        ]);
        $healthy = new WorkflowStub([
            'workflow_id' => 2,
            'trigger_ref' => '* * * * *',
            'website_ids' => [],
        ]);
        $queryRunner = new RecordingQueryRunner('wm-1');
        $logger = new RecordingLogger();

        $this->cron(
            [$broken, $healthy],
            $queryRunner,
            new ScheduleStateConnection(),
            $this->timezone('UTC'),
            $logger
        )->execute();

        // The healthy workflow after the broken one still ran...
        $this->assertCount(1, $queryRunner->calls, 'the sweep must continue past an invalid cron expression');
        $this->assertSame(2, $queryRunner->calls[0][0]);
        // ...and the broken one was reported, naming the workflow and expression.
        $this->assertTrue(
            $logger->hasMessageContaining('error', 'invalid cron expression'),
            'an invalid cron expression must be logged at error level'
        );
        $this->assertTrue(
            $logger->hasMessageContaining('error', 'not-a-cron'),
            'the log line must name the offending expression'
        );
        $this->assertTrue(
            $logger->hasMessageContaining('error', '#1'),
            'the log line must name the offending workflow'
        );
    }

    public function testWatermarkIsPersistedAfterARun(): void
    {
        $workflow = new WorkflowStub([
            'workflow_id' => 1,
            'trigger_ref' => '* * * * *',
            'website_ids' => [],
        ]);
        $queryRunner = new RecordingQueryRunner('2026-07-07 04:00:00');
        $state = new ScheduleStateConnection();

        $this->cron([$workflow], $queryRunner, $state, $this->timezone('UTC'))->execute();

        // First run starts from no watermark...
        $this->assertSame([[1, null]], $queryRunner->calls);
        // ...and what QueryRunner returned is persisted for the next tick.
        $this->assertArrayHasKey(1, $state->rows);
        $this->assertSame('2026-07-07 04:00:00', $state->rows[1]['last_run_watermark']);
        $this->assertNotNull($state->rows[1]['last_run_at'], 'the fire minute must be recorded alongside the watermark');
    }

    public function testNextTickResumesFromThePersistedWatermark(): void
    {
        $workflow = new WorkflowStub([
            'workflow_id' => 1,
            'trigger_ref' => '* * * * *',
            'website_ids' => [],
        ]);
        $queryRunner = new RecordingQueryRunner('wm-new');
        $state = new ScheduleStateConnection();
        // State persisted by an earlier tick (a past minute, so the
        // same-minute guard does not suppress this run).
        $state->rows[1] = [
            'last_run_at' => '2020-01-01 00:00:00',
            'last_run_watermark' => 'wm-77',
        ];

        $this->cron([$workflow], $queryRunner, $state, $this->timezone('UTC'))->execute();

        $this->assertSame(
            [[1, 'wm-77']],
            $queryRunner->calls,
            'the persisted watermark must be handed back to QueryRunner so the sweep resumes, not reprocesses'
        );
        $this->assertSame('wm-new', $state->rows[1]['last_run_watermark'], 'the advanced watermark replaces the old one');
    }
}
