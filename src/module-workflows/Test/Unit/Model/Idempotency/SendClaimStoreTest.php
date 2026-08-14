<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Idempotency;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Phrase;
use MageOS\Workflows\Model\Idempotency\SendClaimStore;
use MageOS\Workflows\Model\Idempotency\SendClaimStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * The durable send-once claim (docs/08 crash safety, docs/15 retention): an
 * INSERT against UNIQUE(claim_key) whose duplicate-key error IS the "already
 * claimed" answer — the Dispatcher::passesDebounce idiom, chosen because a
 * SELECT-then-INSERT check would reopen the very race the cache guard this
 * replaces suffered from.
 *
 * These tests pin the three things a caller depends on: exactly one claimant
 * wins, every flavour of duplicate-key error is recognized as "lost the race"
 * (never as an infrastructure failure), and a REAL infrastructure failure
 * propagates instead of being mistaken for a duplicate — because a caller that
 * reads "duplicate" would skip a send that never happened, while one that sees
 * the exception parks the step.
 */
class SendClaimStoreTest extends TestCase
{
    private const SCOPE = 'notify.email';
    private const KEY = 'e1111111-1111-1111-1111-111111111111:notify_customer';
    private const TABLE = 'mageos_workflow_send_log';

    private ClaimFakeConnection $connection;

    public function setUp(): void
    {
        $this->connection = new ClaimFakeConnection();
    }

    private function store(): SendClaimStore
    {
        return new SendClaimStore(new ClaimFakeResourceConnection($this->connection));
    }

    public function testClaimInsertsTheScopedKeyAndReportsItTaken(): void
    {
        $this->assertTrue($this->store()->claim(self::SCOPE, self::KEY));

        $this->assertCount(1, $this->connection->rows);
        $row = array_values($this->connection->rows)[0];
        $this->assertSame(self::TABLE, $this->connection->lastInsertTable);
        $this->assertSame(self::SCOPE . ':' . self::KEY, $row['claim_key']);
        $this->assertSame(self::SCOPE, $row['scope']);
        $this->assertSame(SendClaimStoreInterface::STATUS_CLAIMED, $row['status']);
    }

    public function testASecondClaimOnTheSameKeyLoses(): void
    {
        $store = $this->store();

        $this->assertTrue($store->claim(self::SCOPE, self::KEY));
        $this->assertFalse($store->claim(self::SCOPE, self::KEY), 'only one consumer may hold a claim');
        $this->assertCount(1, $this->connection->rows);
    }

    public function testTheSameDedupeKeyUnderADifferentScopeIsItsOwnClaim(): void
    {
        // Two actions, one step: e.g. an email action and (later) an SMS one
        // must not dedupe each other.
        $store = $this->store();

        $this->assertTrue($store->claim(self::SCOPE, self::KEY));
        $this->assertTrue($store->claim('order.send_email', self::KEY));
        $this->assertCount(2, $this->connection->rows);
    }

    public function testAlreadyExistsExceptionIsReadAsALostRace(): void
    {
        $this->connection->throwOnInsert = new AlreadyExistsException(new Phrase('Unique constraint violation found'));

        $this->assertFalse($this->store()->claim(self::SCOPE, self::KEY));
    }

    public function testPdoIntegrityViolationIsReadAsALostRace(): void
    {
        // PDO reports SQLSTATE 23000 as the exception CODE (a string, which
        // is why the store compares it as one). The code is only writable from
        // inside the exception, hence the subclass.
        $this->connection->throwOnInsert = new class ('SQLSTATE[23000]: Integrity constraint violation')
            extends \PDOException {
            public function __construct(string $message)
            {
                parent::__construct($message);
                $this->code = '23000';
            }
        };

        $this->assertFalse($this->store()->claim(self::SCOPE, self::KEY));
    }

    public function testAWrappedDuplicateEntryMessageIsReadAsALostRace(): void
    {
        $this->connection->throwOnInsert = new \RuntimeException(
            'Could not save',
            0,
            new \RuntimeException("SQLSTATE[23000]: Duplicate entry 'notify.email:x' for key 'claim_key'")
        );

        $this->assertFalse($this->store()->claim(self::SCOPE, self::KEY));
    }

    public function testARealInfrastructureFailurePropagates(): void
    {
        // Must NOT be swallowed as "already claimed": that would skip a send
        // nobody ever made. The caller parks the step on this.
        $this->connection->throwOnInsert = new \RuntimeException('SQLSTATE[HY000]: server has gone away');

        $this->expectException(\RuntimeException::class);
        $this->store()->claim(self::SCOPE, self::KEY);
    }

    public function testConfirmUpgradesTheClaimToSent(): void
    {
        $store = $this->store();
        $store->claim(self::SCOPE, self::KEY);

        $store->confirm(self::SCOPE, self::KEY);

        $claim = $store->find(self::SCOPE, self::KEY);
        $this->assertSame(SendClaimStoreInterface::STATUS_SENT, $claim['status']);
        $this->assertNotNull($claim['sent_at']);
    }

    public function testReleaseDropsTheClaimSoAnotherAttemptCanTakeIt(): void
    {
        $store = $this->store();
        $store->claim(self::SCOPE, self::KEY);

        $store->release(self::SCOPE, self::KEY);

        $this->assertNull($store->find(self::SCOPE, self::KEY));
        $this->assertTrue($store->claim(self::SCOPE, self::KEY), 'a released key is claimable again');
    }

    public function testFindReturnsNullForAnUnclaimedKey(): void
    {
        $this->assertNull($this->store()->find(self::SCOPE, self::KEY));
    }

    public function testFindNormalizesTheRowItReads(): void
    {
        $store = $this->store();
        $store->claim(self::SCOPE, self::KEY);

        $claim = $store->find(self::SCOPE, self::KEY);

        $this->assertSame(SendClaimStoreInterface::STATUS_CLAIMED, $claim['status']);
        $this->assertNotNull($claim['claimed_at']);
        $this->assertNull($claim['sent_at'], 'an unconfirmed claim has no sent_at');
    }
}

/**
 * Chainable select recorder mirroring the store's single read shape.
 */
class ClaimFakeSelect
{
    public string $table = '';

    /** @var array<int, array{0: string, 1: mixed}> */
    public array $wheres = [];

    public function from($table, $columns = '*'): self
    {
        $this->table = (string) $table;
        return $this;
    }

    public function where($condition, $value = null): self
    {
        $this->wheres[] = [(string) $condition, $value];
        return $this;
    }
}

/**
 * In-memory send-log table enforcing the one property that matters: UNIQUE on
 * claim_key, raising a duplicate-key error exactly as MySQL would.
 */
class ClaimFakeConnection
{
    /** @var array<string, array<string, mixed>> claim_key => row */
    public array $rows = [];

    public ?string $lastInsertTable = null;

    public ?\Exception $throwOnInsert = null;

    public function insert($table, array $bind): int
    {
        if ($this->throwOnInsert !== null) {
            throw $this->throwOnInsert;
        }
        $this->lastInsertTable = (string) $table;
        $key = (string) $bind['claim_key'];
        if (isset($this->rows[$key])) {
            throw new \RuntimeException(
                sprintf("SQLSTATE[23000]: Duplicate entry '%s' for key 'MAGEOS_WORKFLOW_SEND_LOG_CLAIM_KEY'", $key)
            );
        }
        $this->rows[$key] = $bind + ['sent_at' => null];
        return 1;
    }

    public function update($table, array $bind, $where = ''): int
    {
        $key = $this->keyFromWhere(is_array($where) ? $where : []);
        if ($key === null || !isset($this->rows[$key])) {
            return 0;
        }
        $this->rows[$key] = array_merge($this->rows[$key], $bind);
        return 1;
    }

    public function delete($table, $where = ''): int
    {
        $key = $this->keyFromWhere(is_array($where) ? $where : []);
        if ($key === null || !isset($this->rows[$key])) {
            return 0;
        }
        unset($this->rows[$key]);
        return 1;
    }

    public function select(): ClaimFakeSelect
    {
        return new ClaimFakeSelect();
    }

    /**
     * @return array<string, mixed>|false
     */
    public function fetchRow($select)
    {
        foreach ($select->wheres as [$condition, $value]) {
            if (str_contains($condition, 'claim_key')) {
                return $this->rows[(string) $value] ?? false;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $where e.g. ['claim_key = ?' => 'scope:key']
     */
    private function keyFromWhere(array $where): ?string
    {
        foreach ($where as $condition => $value) {
            if (str_contains((string) $condition, 'claim_key')) {
                return (string) $value;
            }
        }
        return null;
    }
}

class ClaimFakeResourceConnection extends ResourceConnection
{
    public function __construct(private readonly ClaimFakeConnection $connection)
    {
    }

    public function getConnection($resourceName = self::DEFAULT_CONNECTION)
    {
        return $this->connection;
    }

    public function getTableName($modelEntity, $connectionName = self::DEFAULT_CONNECTION)
    {
        return (string) $modelEntity;
    }
}
