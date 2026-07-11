<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use MageOS\WorkflowsCustomer\Model\ExecutionPiiScrubber;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * GDPR erasure scrub (docs/10-security.md "PII containment" #3): after a
 * customer deletion, none of that customer's identifiers survive in the
 * scrubbed execution contexts or step results, while the audit trail
 * (execution rows, step keys, workflow metadata, non-personal order fields)
 * and every non-matching execution stay untouched. Customer-rooted
 * executions are redacted wholesale; email-bearing executions of other
 * entity types are redacted in a targeted way (email occurrences plus
 * person-field siblings of the match).
 */
class ExecutionPiiScrubberTest extends TestCase
{
    private const CUSTOMER_ID = 42;
    private const EMAIL = 'john.doe@example.com';

    private ScrubFakeConnection $connection;

    public function setUp(): void
    {
        $this->connection = new ScrubFakeConnection();

        $this->connection->workflows = [
            10 => ['workflow_id' => 10, 'entity_type' => 'customer'],
            20 => ['workflow_id' => 20, 'entity_type' => 'sales_order'],
        ];

        // Customer-rooted execution of the deleted customer.
        $this->seedExecution(1, 10, self::CUSTOMER_ID, [
            'trigger' => [
                'entity_id' => self::CUSTOMER_ID,
                'email' => self::EMAIL,
                'firstname' => 'John',
                'lastname' => 'Doe',
            ],
            'steps' => ['notify' => ['to' => self::EMAIL, 'sent' => true]],
            'workflow' => ['id' => 10, 'name' => 'Welcome Series', 'version' => 3],
        ]);
        // Order-rooted execution whose snapshot carries the customer's email
        // (note the differing letter case) and person fields.
        $this->seedExecution(2, 20, 9001, [
            'trigger' => [
                'entity_id' => 9001,
                'increment_id' => '100000001',
                'grand_total' => 99.5,
                'customer_id' => self::CUSTOMER_ID,
                'customer_email' => 'John.Doe@Example.com',
                'customer_firstname' => 'John',
                'customer_lastname' => 'Doe',
                'billing_address' => [
                    'firstname' => 'John',
                    'lastname' => 'Doe',
                    'street' => '1 Main St',
                    'city' => 'Springfield',
                    'telephone' => '555-1234',
                    'email' => self::EMAIL,
                ],
            ],
            'steps' => ['send' => ['to' => self::EMAIL, 'status' => 'ok']],
            'workflow' => ['id' => 20, 'name' => 'Order Follow-Up', 'version' => 1],
        ]);
        // Same order workflow, a DIFFERENT person: must stay untouched.
        $this->seedExecution(3, 20, 9002, [
            'trigger' => ['entity_id' => 9002, 'customer_email' => 'jane@example.com',
                'customer_firstname' => 'Jane'],
            'steps' => [],
            'workflow' => ['id' => 20],
        ]);
        // Customer workflow, a DIFFERENT customer: must stay untouched.
        $this->seedExecution(4, 10, 43, [
            'trigger' => ['entity_id' => 43, 'email' => 'jane@example.com'],
            'steps' => [],
            'workflow' => ['id' => 10],
        ]);

        $this->seedStep(101, 1, ['to' => self::EMAIL, 'sent' => true], null);
        $this->seedStep(102, 1, null, 'SMTP refused recipient john.doe@example.com');
        $this->seedStep(201, 2, ['to' => self::EMAIL, 'status' => 'ok'], null);
        $this->seedStep(301, 3, ['to' => 'jane@example.com'], null);
    }

    private function scrubber(): ExecutionPiiScrubber
    {
        return new ExecutionPiiScrubber(
            new ScrubFakeResourceConnection($this->connection),
            new NullLogger()
        );
    }

    private function seedExecution(int $id, int $workflowId, int $entityId, array $context): void
    {
        $this->connection->executions[$id] = [
            'execution_id' => $id,
            'workflow_id' => $workflowId,
            'entity_id' => $entityId,
            'context' => json_encode($context),
        ];
    }

    private function seedStep(int $id, int $executionId, ?array $result, ?string $error): void
    {
        $this->connection->steps[$id] = [
            'step_execution_id' => $id,
            'execution_id' => $executionId,
            'result' => $result === null ? null : json_encode($result),
            'error' => $error,
        ];
    }

    private function context(int $executionId): array
    {
        return json_decode((string) $this->connection->executions[$executionId]['context'], true);
    }

    public function testCustomerRootedExecutionIsRedactedWholesaleKeepingTheAuditTrail(): void
    {
        $this->scrubber()->scrubForCustomer(self::CUSTOMER_ID, self::EMAIL);

        $raw = (string) $this->connection->executions[1]['context'];
        $this->assertStringNotContainsString(self::EMAIL, $raw);
        $this->assertStringNotContainsString('John', $raw);
        $this->assertStringNotContainsString('Doe', $raw);

        $context = $this->context(1);
        $this->assertSame(['gdpr_redacted' => true], $context['trigger']);
        // Step KEYS survive (which steps ran is audit data), outputs do not.
        $this->assertSame(['notify' => ['gdpr_redacted' => true]], $context['steps']);
        // Workflow metadata carries no PII and survives verbatim.
        $this->assertSame(['id' => 10, 'name' => 'Welcome Series', 'version' => 3], $context['workflow']);
    }

    public function testCustomerRootedStepResultsAndErrorsAreScrubbed(): void
    {
        $this->scrubber()->scrubForCustomer(self::CUSTOMER_ID, self::EMAIL);

        $this->assertSame(
            ['gdpr_redacted' => true],
            json_decode((string) $this->connection->steps[101]['result'], true)
        );
        $error = (string) $this->connection->steps[102]['error'];
        $this->assertStringNotContainsString(self::EMAIL, $error);
        $this->assertStringContainsString('SMTP refused recipient', $error);
        $this->assertStringContainsString(ExecutionPiiScrubber::REDACTED, $error);
    }

    public function testEmailBearingOrderExecutionLosesTheCustomersIdentifiersButKeepsOrderData(): void
    {
        $this->scrubber()->scrubForCustomer(self::CUSTOMER_ID, self::EMAIL);

        // The deleted customer's email must not survive anywhere, in any case.
        $this->assertFalse(stripos((string) $this->connection->executions[2]['context'], self::EMAIL));

        $trigger = $this->context(2)['trigger'];
        $redacted = ExecutionPiiScrubber::REDACTED;
        $this->assertSame($redacted, $trigger['customer_email']);
        // Person-field siblings of the email match are redacted too.
        $this->assertSame($redacted, $trigger['customer_firstname']);
        $this->assertSame($redacted, $trigger['customer_lastname']);
        $this->assertSame($redacted, $trigger['billing_address']['firstname']);
        $this->assertSame($redacted, $trigger['billing_address']['street']);
        $this->assertSame($redacted, $trigger['billing_address']['telephone']);
        // Non-personal order data is the audit trail and survives.
        $this->assertSame('100000001', $trigger['increment_id']);
        $this->assertSame(99.5, $trigger['grand_total']);
        $this->assertSame(9001, $trigger['entity_id']);
    }

    public function testEmailBearingStepResultsAreScrubbedTargetedly(): void
    {
        $this->scrubber()->scrubForCustomer(self::CUSTOMER_ID, self::EMAIL);

        $result = json_decode((string) $this->connection->steps[201]['result'], true);
        $this->assertStringNotContainsString(self::EMAIL, (string) $this->connection->steps[201]['result']);
        // Targeted scrub: non-personal output keys survive.
        $this->assertSame('ok', $result['status']);
    }

    public function testNonMatchingExecutionsAndStepsAreUntouched(): void
    {
        $before3 = $this->connection->executions[3]['context'];
        $before4 = $this->connection->executions[4]['context'];
        $beforeStep = $this->connection->steps[301]['result'];

        $this->scrubber()->scrubForCustomer(self::CUSTOMER_ID, self::EMAIL);

        $this->assertSame($before3, $this->connection->executions[3]['context']);
        $this->assertSame($before4, $this->connection->executions[4]['context']);
        $this->assertSame($beforeStep, $this->connection->steps[301]['result']);
    }

    public function testScrubIsIdempotent(): void
    {
        $this->scrubber()->scrubForCustomer(self::CUSTOMER_ID, self::EMAIL);
        $afterFirst = [
            array_map(static fn (array $r) => $r['context'], $this->connection->executions),
            $this->connection->steps,
        ];

        $this->scrubber()->scrubForCustomer(self::CUSTOMER_ID, self::EMAIL);

        $this->assertSame($afterFirst, [
            array_map(static fn (array $r) => $r['context'], $this->connection->executions),
            $this->connection->steps,
        ]);
    }

    public function testEmptyEmailStillScrubsCustomerRootedExecutions(): void
    {
        // deleteById on an already-vanished registry entry can leave the hook
        // without an email; the id-rooted pass must still run, and the
        // email pass must not turn into a match-everything LIKE.
        $before2 = $this->connection->executions[2]['context'];

        $this->scrubber()->scrubForCustomer(self::CUSTOMER_ID, '');

        $this->assertSame(['gdpr_redacted' => true], $this->context(1)['trigger']);
        $this->assertSame($before2, $this->connection->executions[2]['context']);
    }

    public function testCorruptContextOnACustomerRootedRowIsOverwrittenEntirely(): void
    {
        $this->connection->executions[5] = [
            'execution_id' => 5,
            'workflow_id' => 10,
            'entity_id' => self::CUSTOMER_ID,
            'context' => '{not json' . self::EMAIL,
        ];

        $this->scrubber()->scrubForCustomer(self::CUSTOMER_ID, self::EMAIL);

        // Unknowable content must be assumed to carry PII.
        $this->assertSame(['gdpr_redacted' => true], $this->context(5)['trigger']);
        $this->assertStringNotContainsString(self::EMAIL, (string) $this->connection->executions[5]['context']);
    }
}

/**
 * Chainable select recorder for the scrub queries.
 */
class ScrubFakeSelect
{
    public string $table = '';

    /** @var string[] */
    public array $columns = [];

    /** @var array<int, array{0: string, 1: mixed}> */
    public array $wheres = [];

    public ?int $limit = null;

    public function from($table, $columns = '*'): self
    {
        $this->table = (string) $table;
        $this->columns = is_array($columns) ? array_values($columns) : [(string) $columns];
        return $this;
    }

    public function where($condition, $value = null): self
    {
        $this->wheres[] = [(string) $condition, $value];
        return $this;
    }

    public function order($spec): self
    {
        return $this;
    }

    public function limit($count, $offset = 0): self
    {
        $this->limit = (int) $count;
        return $this;
    }
}

/**
 * In-memory workflow/execution/step tables evaluating the scrubber's WHERE
 * shapes ("entity_type = ?", "workflow_id IN (?)", "entity_id = ?",
 * "execution_id > ?", "context LIKE ?", "execution_id IN (?)") plus the
 * per-row updates it issues.
 */
class ScrubFakeConnection
{
    private const WORKFLOW_TABLE = 'mageos_workflow';
    private const EXECUTION_TABLE = 'mageos_workflow_execution';
    private const STEP_TABLE = 'mageos_workflow_execution_step';

    /** @var array<int, array<string, mixed>> */
    public array $workflows = [];

    /** @var array<int, array<string, mixed>> */
    public array $executions = [];

    /** @var array<int, array<string, mixed>> */
    public array $steps = [];

    public function select(): ScrubFakeSelect
    {
        return new ScrubFakeSelect();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll($select, $bind = [], $fetchMode = null): array
    {
        $matched = $this->matchedRows($select);
        return array_map(
            static function (array $row) use ($select): array {
                $projected = [];
                foreach ($select->columns as $column) {
                    $projected[$column] = $row[$column] ?? null;
                }
                return $projected;
            },
            $matched
        );
    }

    /**
     * @return array<int, mixed>
     */
    public function fetchCol($select, $bind = []): array
    {
        $column = $select->columns[0] ?? null;
        return array_map(
            static fn (array $r) => $column !== null ? ($r[$column] ?? null) : reset($r),
            $this->matchedRows($select)
        );
    }

    public function update($table, array $data, $where = ''): int
    {
        $rows = &$this->tableRows((string) $table);
        $updated = 0;
        foreach ($rows as $id => $row) {
            if ($this->matchesWheres($row, $this->normalizedWheres(is_array($where) ? $where : []))) {
                $rows[$id] = array_merge($row, $data);
                $updated++;
            }
        }
        return $updated;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function matchedRows(ScrubFakeSelect $select): array
    {
        $rows = $this->tableRows($select->table);
        $matched = array_values(array_filter(
            $rows,
            fn (array $row) => $this->matchesWheres($row, $select->wheres)
        ));
        usort($matched, static fn (array $a, array $b) => (reset($a) <=> reset($b)));
        if ($select->limit !== null) {
            $matched = array_slice($matched, 0, $select->limit);
        }
        return $matched;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function &tableRows(string $table): array
    {
        switch ($table) {
            case self::WORKFLOW_TABLE:
                return $this->workflows;
            case self::EXECUTION_TABLE:
                return $this->executions;
            case self::STEP_TABLE:
                return $this->steps;
        }
        throw new \RuntimeException('Unknown table ' . $table);
    }

    /**
     * @param array<string, mixed> $where update()-style map
     * @return array<int, array{0: string, 1: mixed}>
     */
    private function normalizedWheres(array $where): array
    {
        $normalized = [];
        foreach ($where as $condition => $value) {
            $normalized[] = [(string) $condition, $value];
        }
        return $normalized;
    }

    /**
     * @param array<int, array{0: string, 1: mixed}> $wheres
     */
    private function matchesWheres(array $row, array $wheres): bool
    {
        foreach ($wheres as [$condition, $value]) {
            if (!$this->matchesCondition($row, $condition, $value)) {
                return false;
            }
        }
        return true;
    }

    private function matchesCondition(array $row, string $condition, mixed $value): bool
    {
        $parts = preg_split('/\s+/', trim($condition));
        $column = $parts[0] ?? '';
        $operator = strtoupper($parts[1] ?? '=');
        $actual = $row[$column] ?? null;
        return match ($operator) {
            '=' => (string) $actual === (string) $value,
            '>' => $actual !== null && (int) $actual > (int) $value,
            'IN' => in_array(
                (string) $actual,
                array_map('strval', is_array($value) ? $value : [$value]),
                true
            ),
            // MySQL LIKE on the utf8 _ci collations is case-insensitive.
            'LIKE' => is_string($actual)
                && stripos($actual, stripcslashes(trim((string) $value, '%'))) !== false,
            default => false,
        };
    }
}

class ScrubFakeResourceConnection extends ResourceConnection
{
    public function __construct(private readonly ScrubFakeConnection $connection)
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
