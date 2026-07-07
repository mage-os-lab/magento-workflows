<?php
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Unit\Stub;

use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * In-memory quote + mageos_workflow_abandoned_flag pair for
 * AbandonedCartDetector tests.
 *
 * The candidate query is evaluated against the WHERE fragments the detector
 * actually builds: the two updated_at bounds are read off the recorded
 * select ("q.updated_at < ?" older-than, "q.updated_at >= ?" max-age floor),
 * so the age window under test is the one the production code computed from
 * its configuration. Flag writes mutate the same in-memory flag table the
 * next query reads, so dedupe across runs behaves like the real schema.
 */
class FakeQuoteDb implements AdapterInterface
{
    /**
     * @var array<int, array{is_active: int, items_count: int, customer_email: ?string, store_id: int, updated_at: string}>
     */
    public array $quotes = [];

    /** @var array<int, array<string, mixed>> quote_id => flag row */
    public array $flags = [];

    public function addQuote(
        int $quoteId,
        string $updatedAt,
        int $isActive = 1,
        int $itemsCount = 1,
        ?string $customerEmail = 'shopper@example.com',
        int $storeId = 1
    ): void {
        $this->quotes[$quoteId] = [
            'is_active' => $isActive,
            'items_count' => $itemsCount,
            'customer_email' => $customerEmail,
            'store_id' => $storeId,
            'updated_at' => $updatedAt,
        ];
    }

    public function select()
    {
        return new FakeSelect();
    }

    public function fetchAll($select, $bind = [], $fetchMode = null)
    {
        if (!$select instanceof FakeSelect) {
            throw new \BadMethodCallException('expected a FakeSelect');
        }

        // "q.updated_at >= ?" contains the substring "q.updated_at >", so
        // resolve the >= bound first and match < with the trailing space.
        $newerThan = $select->whereValue('updated_at >= ');
        $olderThan = $select->whereValue('updated_at < ');
        if ($olderThan === null) {
            throw new \BadMethodCallException('candidate query is expected to bind an older-than bound');
        }

        $requireActive = $select->whereValue('is_active');
        $itemsComparison = $select->whereComparison('items_count');
        $requireEmail = $select->hasWhere('customer_email IS NOT NULL');
        $excludeFlagged = $select->hasWhere('f.quote_id IS NULL');

        $rows = [];
        foreach ($this->quotes as $quoteId => $quote) {
            if ($requireActive !== null && (int) $quote['is_active'] !== (int) $requireActive) {
                continue;
            }
            if ($itemsComparison !== null) {
                [$operator, $bound] = $itemsComparison;
                $ok = match ($operator) {
                    '>' => $quote['items_count'] > (int) $bound,
                    '>=' => $quote['items_count'] >= (int) $bound,
                    default => throw new \InvalidArgumentException('unsupported operator ' . $operator),
                };
                if (!$ok) {
                    continue;
                }
            }
            if ($requireEmail && ($quote['customer_email'] === null || $quote['customer_email'] === '')) {
                continue;
            }
            // 'Y-m-d H:i:s' strings compare correctly lexicographically.
            if (strcmp($quote['updated_at'], (string) $olderThan) >= 0) {
                continue; // not old enough
            }
            if ($newerThan !== null && strcmp($quote['updated_at'], (string) $newerThan) < 0) {
                continue; // aged out (max-age window)
            }
            if ($excludeFlagged && isset($this->flags[$quoteId])) {
                continue;
            }
            $rows[] = [
                'entity_id' => $quoteId,
                'customer_email' => $quote['customer_email'],
                'store_id' => $quote['store_id'],
                'updated_at' => $quote['updated_at'],
            ];
            if ($select->limitCount !== null && count($rows) >= $select->limitCount) {
                break;
            }
        }
        return $rows;
    }

    public function fetchRow($sql, $bind = [], $fetchMode = null)
    {
        throw new \BadMethodCallException(__METHOD__ . ' not expected in these tests');
    }

    public function fetchCol($sql, $bind = [])
    {
        throw new \BadMethodCallException(__METHOD__ . ' not expected in these tests');
    }

    public function insertOnDuplicate($table, array $data, array $fields = [])
    {
        $this->flags[(int) $data['quote_id']] = $data;
        return 1;
    }

    public function delete($table, $where = '')
    {
        throw new \BadMethodCallException(__METHOD__ . ' not expected in these tests');
    }
}
