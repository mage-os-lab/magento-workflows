<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Test\Unit\Stub;

use MageOS\WorkflowsScheduler\Test\Unit\Stub\FakeQuoteDb;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\FakeSelect;

/**
 * In-memory customer_entity + mageos_workflow_birthday_flag pair for
 * BirthdayDetector (candidate sweep) and CustomerBirthdayAggregateProvider
 * (dob lookup).
 *
 * Extends FakeQuoteDb ONLY to inherit its signature-faithful AdapterInterface
 * method surface (the ~90 throwing stubs); it overrides the four methods these
 * two classes actually touch. The candidate query is evaluated against the
 * WHERE/JOIN fragments the detector really builds — the accepted month/day set
 * ("DATE_FORMAT(...) IN (?)"), the dedupe year (parsed off the flag join), the
 * "c.dob IS NOT NULL" filter and the "f.customer_id IS NULL" not-yet-flagged
 * guard — so the semantics under test are the production code's, not the fake's.
 * Flag writes mutate the same in-memory table the next sweep reads.
 */
class FakeCustomerDb extends FakeQuoteDb
{
    /** @var array<int, array{dob: ?string, store_id: int, website_id: int}> */
    public array $customers = [];

    /** @var array<string, array<string, mixed>> "customerId:birthYear" => flag row */
    public array $birthdayFlags = [];

    public function addCustomer(
        int $customerId,
        ?string $dob,
        int $storeId = 1,
        int $websiteId = 1
    ): void {
        $this->customers[$customerId] = [
            'dob' => $dob,
            'store_id' => $storeId,
            'website_id' => $websiteId,
        ];
    }

    public function fetchAll($select, $bind = [], $fetchMode = null)
    {
        if (!$select instanceof FakeSelect) {
            throw new \BadMethodCallException('expected a FakeSelect');
        }

        $accepted = $select->whereValue('DATE_FORMAT');
        $requireDob = $select->hasWhere('c.dob IS NOT NULL');
        $excludeFlagged = $select->hasWhere('f.customer_id IS NULL');
        $year = $this->joinYear($select);

        $rows = [];
        foreach ($this->customers as $customerId => $customer) {
            $dob = $customer['dob'];
            if ($requireDob && ($dob === null || $dob === '')) {
                continue;
            }
            if (is_array($accepted) && !in_array($this->monthDay($dob), $accepted, true)) {
                continue;
            }
            if ($excludeFlagged && isset($this->birthdayFlags[$customerId . ':' . $year])) {
                continue;
            }
            $rows[] = [
                'entity_id' => $customerId,
                'dob' => $dob,
                'store_id' => $customer['store_id'],
                'website_id' => $customer['website_id'],
            ];
            if ($select->limitCount !== null && count($rows) >= $select->limitCount) {
                break;
            }
        }
        return $rows;
    }

    public function fetchOne($sql, $bind = [])
    {
        if (!$sql instanceof FakeSelect) {
            throw new \BadMethodCallException('expected a FakeSelect');
        }
        $customerId = (int) $sql->whereValue('entity_id');
        $dob = $this->customers[$customerId]['dob'] ?? null;
        // Zend fetchOne() returns false when there is no row/value.
        return $dob ?? false;
    }

    public function insertOnDuplicate($table, array $data, array $fields = [])
    {
        $key = ((int) $data['customer_id']) . ':' . ((int) $data['birth_year']);
        $this->birthdayFlags[$key] = $data;
        return 1;
    }

    private function monthDay(?string $dob): string
    {
        if ($dob === null || $dob === '') {
            return '';
        }
        return (new \DateTimeImmutable($dob, new \DateTimeZone('UTC')))->format('m-d');
    }

    private function joinYear(FakeSelect $select): int
    {
        foreach ($select->joins as [$name, $cond]) {
            if (preg_match('/birth_year\s*=\s*(\d+)/', (string) $cond, $m)) {
                return (int) $m[1];
            }
        }
        return 0;
    }
}
