<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use MageOS\WorkflowsTriggersCore\Service\EventPublisher;
use Psr\Log\LoggerInterface;

/**
 * Daily scheduler detector for CUS-T3: publishes 'customer.birthday_upcoming'
 * for every customer whose birthday anniversary falls exactly N days from
 * "today", where N is mageos_workflows/scheduler/birthday_days_ahead
 * (default 7; 0 disables the detector).
 *
 * Like AbandonedCartDetector this is the scheduler's "query trigger" shape: a
 * birthday isn't an async event, it's a query — "dob anniversary is N days
 * out" — whose hits ride the single async dispatch path as
 * 'customer.birthday_upcoming' events (hydrated by
 * CustomerRepositoryInterface::getById, same as customer.group_changed).
 *
 * Timezone semantics (honest, single-timezone — mirrors AbandonedCartDetector
 * and RunScheduledWorkflows): "today" is the calendar date in Magento's
 * configured DEFAULT-scope timezone (general/locale/timezone). Per-website
 * timezones are NOT honored — a store whose local date differs from the
 * default-scope date at cron time can see a birthday detected a day early or
 * late near midnight. The daily job is scheduled early-morning (06:00 in the
 * cron/server clock) to keep that window small. Inventing per-store-timezone
 * machinery here was deliberately declined.
 *
 * Feb-29 births are matched on Feb 28 in common years and on Feb 29 in leap
 * years (BirthdayCalculator::anniversaryMonthDaysFor, shared with the
 * days_until_birthday aggregate so the two never disagree).
 *
 * Yearly dedupe: mageos_workflow_birthday_flag is unique on
 * (customer_id, birth_year); birth_year is the calendar year the upcoming
 * birthday falls in (so a January birthday detected in late December flags the
 * NEXT year). A publish failure leaves the customer unflagged so the next run
 * retries. Old-year rows are harmless and left in place, matching the
 * abandoned-flag table (no cleanup cron).
 *
 * triggers-core is a hard dependency of this pack, so EventPublisher is
 * injected directly (no soft ObjectManager probe).
 */
class BirthdayDetector
{
    private const XML_PATH_DAYS_AHEAD = 'mageos_workflows/scheduler/birthday_days_ahead';
    private const DEFAULT_DAYS_AHEAD = 7;
    private const BATCH_SIZE = 500;

    private const CUSTOMER_TABLE = 'customer_entity';
    private const FLAG_TABLE = 'mageos_workflow_birthday_flag';
    private const EVENT_NAME = 'customer.birthday_upcoming';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly TimezoneInterface $timezone,
        private readonly EventPublisher $eventPublisher,
        private readonly BirthdayCalculator $birthdayCalculator,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $daysAhead = $this->resolveDaysAhead();
        if ($daysAhead <= 0) {
            // 0 (or negative) disables the detector.
            return;
        }

        $today = new \DateTimeImmutable('now', $this->configuredTimezone());
        $target = $today->modify(sprintf('+%d days', $daysAhead));
        $targetYear = (int) $target->format('Y');
        $monthDays = $this->birthdayCalculator->anniversaryMonthDaysFor($target);

        $connection = $this->resourceConnection->getConnection();
        $customers = $this->fetchCandidates($connection, $monthDays, $targetYear);
        if ($customers === []) {
            return;
        }

        $flagTable = $this->resourceConnection->getTableName(self::FLAG_TABLE);
        $flaggedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        foreach ($customers as $customer) {
            $customerId = (int) $customer['entity_id'];
            try {
                $this->publish($customerId, $customer, $daysAhead);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'BirthdayDetector: failed publishing "%s" for customer #%d: %s',
                    self::EVENT_NAME,
                    $customerId,
                    $e->getMessage()
                ), ['exception' => $e]);
                // Do not flag - retry on the next run.
                continue;
            }
            $this->flag($connection, $flagTable, $customerId, $targetYear, $flaggedAt);
        }
    }

    private function resolveDaysAhead(): int
    {
        $raw = $this->scopeConfig->getValue(self::XML_PATH_DAYS_AHEAD);
        if ($raw === null || $raw === '') {
            // Unset falls back to the default; only an explicit 0 disables.
            return self::DEFAULT_DAYS_AHEAD;
        }
        return (int) $raw;
    }

    private function configuredTimezone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone($this->timezone->getConfigTimezone());
        } catch (\Throwable $e) {
            $this->logger->debug(
                'BirthdayDetector: falling back to UTC (' . $e->getMessage() . ')'
            );
            return new \DateTimeZone('UTC');
        }
    }

    /**
     * @param string[] $monthDays
     * @return array<int, array<string, mixed>>
     */
    private function fetchCandidates(AdapterInterface $connection, array $monthDays, int $targetYear): array
    {
        $customerTable = $this->resourceConnection->getTableName(self::CUSTOMER_TABLE);
        $flagTable = $this->resourceConnection->getTableName(self::FLAG_TABLE);

        $select = $connection->select()
            ->from(['c' => $customerTable], ['entity_id', 'dob', 'store_id', 'website_id'])
            ->joinLeft(
                ['f' => $flagTable],
                // $targetYear is a computed int - safe to interpolate.
                sprintf('f.customer_id = c.entity_id AND f.birth_year = %d', $targetYear),
                []
            )
            ->where('c.dob IS NOT NULL')
            ->where("DATE_FORMAT(c.dob, '%m-%d') IN (?)", $monthDays)
            ->where('f.customer_id IS NULL')
            ->limit(self::BATCH_SIZE);

        return $connection->fetchAll($select);
    }

    /**
     * @param array<string, mixed> $customer
     */
    private function publish(int $customerId, array $customer, int $daysAhead): void
    {
        $this->eventPublisher->publish(self::EVENT_NAME, [
            // 'customerId' hydrates via CustomerRepositoryInterface::getById($customerId).
            'customerId' => $customerId,
            'entity_id' => $customerId,
            'dob' => $customer['dob'] ?? null,
            'days_until_birthday' => $daysAhead,
            'store_id' => (int) ($customer['store_id'] ?? 0),
            'website_id' => (int) ($customer['website_id'] ?? 0),
        ]);
    }

    private function flag(
        AdapterInterface $connection,
        string $flagTable,
        int $customerId,
        int $birthYear,
        string $flaggedAt
    ): void {
        $connection->insertOnDuplicate(
            $flagTable,
            [
                'customer_id' => $customerId,
                'birth_year' => $birthYear,
                'flagged_at' => $flaggedAt,
            ],
            ['flagged_at']
        );
    }
}
