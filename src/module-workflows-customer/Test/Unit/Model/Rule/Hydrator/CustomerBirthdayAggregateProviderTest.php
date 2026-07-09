<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Test\Unit\Model\Rule\Hydrator;

use MageOS\WorkflowsCustomer\Model\BirthdayCalculator;
use MageOS\WorkflowsCustomer\Model\Rule\Hydrator\CustomerBirthdayAggregateProvider;
use MageOS\WorkflowsCustomer\Test\Unit\Stub\FakeCustomerDb;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\FakeResourceConnection;
use PHPUnit\Framework\TestCase;

/**
 * CustomerBirthdayAggregateProvider (CUS-C4): contributes days_until_birthday
 * and birthday_month to the customer root, ABSENT (not null) when the customer
 * has no dob so they fail toward false. The day arithmetic is the shared
 * BirthdayCalculator (exhaustively covered in BirthdayCalculatorTest); here we
 * pin the provider's wiring, the absent-when-no-dob contract, and the metadata.
 */
class CustomerBirthdayAggregateProviderTest extends TestCase
{
    private function provider(FakeCustomerDb $db): CustomerBirthdayAggregateProvider
    {
        return new CustomerBirthdayAggregateProvider(
            new FakeResourceConnection($db),
            new BirthdayCalculator()
        );
    }

    public function testMetadataAdvertisesBothAggregates(): void
    {
        $metadata = $this->provider(new FakeCustomerDb())->getAttributeMetadata();

        $this->assertArrayHasKey('days_until_birthday', $metadata);
        $this->assertArrayHasKey('birthday_month', $metadata);
        $this->assertSame('numeric', $metadata['days_until_birthday']['input_type']);
        $this->assertSame('select', $metadata['birthday_month']['input_type']);
    }

    public function testBirthdayTodayIsZeroDays(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        // dob = today's month/day (in a leap year, so Feb 29 is valid too).
        $db = new FakeCustomerDb();
        $db->addCustomer(7, '2000-' . $now->format('m-d'));

        $aggregates = $this->provider($db)->getAggregates(7);

        $this->assertSame(0, $aggregates['days_until_birthday']);
        $this->assertSame((int) $now->format('n'), $aggregates['birthday_month']);
    }

    public function testMatchesTheSharedCalculator(): void
    {
        $db = new FakeCustomerDb();
        $db->addCustomer(8, '1988-11-20');

        $aggregates = $this->provider($db)->getAggregates(8);

        $expected = (new BirthdayCalculator())->daysUntilNextBirthday(
            new \DateTimeImmutable('1988-11-20', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );
        $this->assertSame($expected, $aggregates['days_until_birthday']);
        $this->assertSame(11, $aggregates['birthday_month']);
    }

    public function testFeb29BirthdayMonthIsFebruary(): void
    {
        $db = new FakeCustomerDb();
        $db->addCustomer(9, '2000-02-29');

        $aggregates = $this->provider($db)->getAggregates(9);

        $this->assertSame(2, $aggregates['birthday_month']);
        $this->assertTrue(is_int($aggregates['days_until_birthday']));
        $this->assertTrue($aggregates['days_until_birthday'] >= 0);
    }

    public function testNoDobYieldsNoAggregates(): void
    {
        $db = new FakeCustomerDb();
        $db->addCustomer(11, null);

        $this->assertSame([], $this->provider($db)->getAggregates(11), 'absent dob => both attributes absent');
    }

    public function testEmptyDobYieldsNoAggregates(): void
    {
        $db = new FakeCustomerDb();
        $db->addCustomer(12, '');

        $this->assertSame([], $this->provider($db)->getAggregates(12));
    }
}
