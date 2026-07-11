<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Test\Unit\Model;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use MageOS\Workflows\Test\Unit\Stub\StubScopeConfig;
use MageOS\WorkflowsCustomer\Model\BirthdayCalculator;
use MageOS\WorkflowsCustomer\Model\BirthdayDetector;
use MageOS\WorkflowsCustomer\Test\Unit\Stub\FakeCustomerDb;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\FakeResourceConnection;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\RecordingEventPublisher;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * BirthdayDetector (CUS-T3): publishes customer.birthday_upcoming for every
 * customer whose anniversary is exactly N days out (N =
 * mageos_workflows/scheduler/birthday_days_ahead), dedupes once per birthday
 * year, and is disabled by N = 0.
 *
 * The detector reads the real clock in the configured timezone (stubbed to
 * UTC). Candidate dobs are anchored to "today + N" days so the expectation
 * cannot drift with the run date; the awkward calendar math (Feb-29, the year
 * boundary) is proven deterministically in BirthdayCalculatorTest against the
 * same shared helper this detector uses.
 */
class BirthdayDetectorTest extends TestCase
{
    private const EVENT = 'customer.birthday_upcoming';
    private const DOB_YEAR = 2000; // leap year - every "today + k" month/day is a valid dob

    private function timezone(string $zone = 'UTC'): TimezoneInterface
    {
        return new class ($zone) implements TimezoneInterface {
            public function __construct(private readonly string $zone)
            {
            }

            public function getConfigTimezone($scopeType = null, $scopeCode = null): string
            {
                return $this->zone;
            }

            public function getDefaultTimezonePath() { throw new \BadMethodCallException(__METHOD__); }
            public function getDefaultTimezone() { throw new \BadMethodCallException(__METHOD__); }
            public function getDateFormat($type = \IntlDateFormatter::SHORT) { throw new \BadMethodCallException(__METHOD__); }
            public function getDateFormatWithLongYear() { throw new \BadMethodCallException(__METHOD__); }
            public function getTimeFormat($type = null) { throw new \BadMethodCallException(__METHOD__); }
            public function getDateTimeFormat($type) { throw new \BadMethodCallException(__METHOD__); }
            public function date($date = null, $locale = null, $useTimezone = true, $includeTime = true) { throw new \BadMethodCallException(__METHOD__); }
            public function scopeDate($scope = null, $date = null, $includeTime = false) { throw new \BadMethodCallException(__METHOD__); }
            public function scopeTimeStamp($scope = null) { throw new \BadMethodCallException(__METHOD__); }
            public function formatDate($date = null, $format = \IntlDateFormatter::SHORT, $showTime = false) { throw new \BadMethodCallException(__METHOD__); }
            public function isScopeDateInInterval($scope, $dateFrom = null, $dateTo = null) { throw new \BadMethodCallException(__METHOD__); }
            public function formatDateTime($date, $dateType = \IntlDateFormatter::SHORT, $timeType = \IntlDateFormatter::SHORT, $locale = null, $timezone = null, $pattern = null) { throw new \BadMethodCallException(__METHOD__); }
            public function convertConfigTimeToUtc($date, $format = 'Y-m-d H:i:s') { throw new \BadMethodCallException(__METHOD__); }
        };
    }

    private function detector(
        FakeResourceConnection $resource,
        ?string $daysAhead,
        RecordingEventPublisher $publisher,
        ?RecordingLogger $logger = null
    ): BirthdayDetector {
        return new BirthdayDetector(
            $resource,
            new StubScopeConfig(
                $daysAhead === null ? [] : ['mageos_workflows/scheduler/birthday_days_ahead' => $daysAhead]
            ),
            $this->timezone(),
            $publisher,
            new BirthdayCalculator(),
            $logger ?? new RecordingLogger()
        );
    }

    /** 'Y-m-d' of a dob whose anniversary is $daysFromToday days out (UTC). */
    private function dob(int $daysFromToday): string
    {
        $target = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(sprintf('+%d days', $daysFromToday));
        return sprintf('%d-%s', self::DOB_YEAR, $target->format('m-d'));
    }

    private function targetYear(int $daysFromToday): int
    {
        return (int) (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(sprintf('+%d days', $daysFromToday))
            ->format('Y');
    }

    public function testPublishesOnlyCustomersExactlyNDaysAhead(): void
    {
        $db = new FakeCustomerDb();
        // The match: birthday exactly 7 days out.
        $db->addCustomer(10, $this->dob(7), 5, 2);
        // Off by a day in each direction, and today's birthday - none is "7 days out".
        $db->addCustomer(11, $this->dob(6));
        $db->addCustomer(12, $this->dob(8));
        $db->addCustomer(13, $this->dob(0));

        $publisher = new RecordingEventPublisher();
        $this->detector(new FakeResourceConnection($db), '7', $publisher)->execute();

        $this->assertCount(1, $publisher->published, 'exactly one customer is 7 days from a birthday');
        $record = $publisher->published[0];
        $this->assertSame(self::EVENT, $record['event']);
        $this->assertSame(10, $record['data']['customerId']);
        $this->assertSame(10, $record['data']['entity_id']);
        $this->assertSame(7, $record['data']['days_until_birthday']);
        $this->assertSame($this->dob(7), $record['data']['dob']);
        $this->assertSame(5, $record['data']['store_id']);
        $this->assertSame(2, $record['data']['website_id']);

        $this->assertArrayHasKey(
            '10:' . $this->targetYear(7),
            $db->birthdayFlags,
            'the notified customer is flagged for this birthday year'
        );
        $this->assertCount(1, $db->birthdayFlags, 'non-matches must not be flagged');
    }

    public function testUnsetConfigUsesTheSevenDayDefault(): void
    {
        $db = new FakeCustomerDb();
        $db->addCustomer(20, $this->dob(7));
        $db->addCustomer(21, $this->dob(5));

        $publisher = new RecordingEventPublisher();
        $this->detector(new FakeResourceConnection($db), null, $publisher)->execute();

        $this->assertCount(1, $publisher->published);
        $this->assertSame(20, $publisher->published[0]['data']['customerId']);
        $this->assertSame(7, $publisher->published[0]['data']['days_until_birthday']);
    }

    public function testAlreadyFlaggedCustomerIsNotRepublishedSameYear(): void
    {
        $db = new FakeCustomerDb();
        $db->addCustomer(10, $this->dob(7));
        $publisher = new RecordingEventPublisher();
        $resource = new FakeResourceConnection($db);

        $this->detector($resource, '7', $publisher)->execute();
        // Same run tomorrow-ish: still 7 days out for the sweep, but already flagged.
        $this->detector($resource, '7', $publisher)->execute();

        $this->assertCount(1, $publisher->published, 'a customer already flagged this year is not re-notified');
    }

    public function testZeroDaysAheadDisablesTheDetector(): void
    {
        // getConnection() throws if touched - proves the detector never queries.
        $resource = new FakeResourceConnection(null);
        $publisher = new RecordingEventPublisher();

        $this->detector($resource, '0', $publisher)->execute();

        $this->assertCount(0, $publisher->published);
        $this->assertSame(0, $resource->getConnectionCalls, 'a disabled detector must not open a connection');
    }

    public function testCustomersWithoutDobAreSkipped(): void
    {
        $db = new FakeCustomerDb();
        $db->addCustomer(30, null);
        $db->addCustomer(31, $this->dob(7));

        $publisher = new RecordingEventPublisher();
        $this->detector(new FakeResourceConnection($db), '7', $publisher)->execute();

        $this->assertCount(1, $publisher->published);
        $this->assertSame(31, $publisher->published[0]['data']['customerId']);
    }

    public function testPublishFailureLeavesCustomerUnflaggedSoNextRunRetries(): void
    {
        $db = new FakeCustomerDb();
        $db->addCustomer(10, $this->dob(7));
        $resource = new FakeResourceConnection($db);
        $logger = new RecordingLogger();

        $failing = new RecordingEventPublisher(new \RuntimeException('amqp connection refused'));
        $this->detector($resource, '7', $failing, $logger)->execute();

        $this->assertCount(0, $db->birthdayFlags, 'a customer whose publish failed must NOT be flagged');
        $this->assertStringContainsString('error:', $logger->allMessages());
        $this->assertStringContainsString(self::EVENT, $logger->allMessages());

        // Broker back: the next sweep picks the same customer up again and flags it.
        $recovered = new RecordingEventPublisher();
        $this->detector($resource, '7', $recovered)->execute();

        $this->assertCount(1, $recovered->published);
        $this->assertSame(10, $recovered->published[0]['data']['customerId']);
        $this->assertArrayHasKey('10:' . $this->targetYear(7), $db->birthdayFlags);
    }
}
