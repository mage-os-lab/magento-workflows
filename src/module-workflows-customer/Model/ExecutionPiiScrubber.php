<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Model;

use Magento\Framework\App\ResourceConnection;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * GDPR erasure scrubbing of persisted execution state
 * (docs/10-security.md "PII containment" #3).
 *
 * When a customer account is deleted, their PII must not linger in
 * mageos_workflow_execution.context (trigger snapshots hold names, emails,
 * addresses) or mageos_workflow_execution_step.result until TTL pruning
 * catches up. Execution rows themselves are KEPT — status, timestamps,
 * workflow id, step keys remain as the audit trail; only person data goes.
 *
 * Scrub scope, in two passes:
 *
 * 1. CUSTOMER-ROOTED executions — rows whose workflow has
 *    entity_type=customer and whose entity_id is the deleted customer. The
 *    whole trigger snapshot IS that person, so context.trigger and every
 *    step output are replaced wholesale with a {"gdpr_redacted": true}
 *    marker (step KEYS survive; context.workflow metadata carries no PII
 *    and survives). Step-row result JSON is replaced the same way.
 *
 * 2. EMAIL-BEARING executions — rows of ANY entity type whose context JSON
 *    contains the deleted customer's email (order-, quote-,
 *    subscriber-rooted snapshots all carry customer_email / email fields).
 *    Candidates come from a SQL LIKE prefilter; each is then scrubbed in
 *    PHP: every string value containing the email has the email replaced
 *    with the redaction marker, and any associative array in which such a
 *    match occurred additionally has its person-field siblings (name
 *    parts, dob, taxvat, telephone, street, ... — see PII_SIBLING_KEYS)
 *    redacted, so an order snapshot loses the customer's name and address,
 *    not just the email that anchored the match. Step results get the same
 *    treatment; step error text has email occurrences replaced.
 *
 * Known limits (documented in docs/10-security.md): snapshots that carry
 * the customer's PII WITHOUT their email anywhere in the same context
 * cannot be attributed safely and are left to TTL pruning, as are
 * merchant-authored definition snapshots and aggregation batch items.
 */
class ExecutionPiiScrubber
{
    public const REDACTED = '[gdpr-redacted]';
    public const REDACTION_MARKER_KEY = 'gdpr_redacted';

    private const WORKFLOW_TABLE = 'mageos_workflow';
    private const EXECUTION_TABLE = 'mageos_workflow_execution';
    private const STEP_TABLE = 'mageos_workflow_execution_step';

    private const BATCH_SIZE = 500;

    /**
     * Keys redacted alongside an email match in the same associative array.
     * Covers Magento's flat order/quote snapshot fields (customer_*), the
     * customer DTO's own field names, and address-record fields.
     */
    private const PII_SIBLING_KEYS = [
        'email', 'customer_email',
        'firstname', 'customer_firstname',
        'lastname', 'customer_lastname',
        'middlename', 'customer_middlename',
        'prefix', 'customer_prefix',
        'suffix', 'customer_suffix',
        'dob', 'customer_dob',
        'taxvat', 'customer_taxvat',
        'gender', 'customer_gender',
        'customer_note',
        'telephone', 'fax', 'company', 'vat_id',
        'street', 'city', 'postcode', 'region',
        'remote_ip', 'x_forwarded_for',
    ];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Scrub all execution state attributable to the deleted customer.
     * Idempotent — a second run over already-scrubbed rows changes nothing.
     */
    public function scrubForCustomer(int $customerId, string $email): void
    {
        $scrubbed = $this->scrubCustomerRootedExecutions($customerId, $email);
        if ($email !== '') {
            $scrubbed += $this->scrubEmailBearingExecutions($email);
        }

        if ($scrubbed > 0) {
            $this->logger->info(sprintf(
                'GDPR erasure scrubbed %d workflow execution(s) for deleted customer %d',
                $scrubbed,
                $customerId
            ));
        }
    }

    /**
     * Pass 1: full context/step redaction of executions rooted on the
     * deleted customer entity.
     */
    private function scrubCustomerRootedExecutions(int $customerId, string $email): int
    {
        $connection = $this->resourceConnection->getConnection();
        $workflowIds = array_map('intval', $connection->fetchCol(
            $connection->select()
                ->from($this->resourceConnection->getTableName(self::WORKFLOW_TABLE), ['workflow_id'])
                ->where('entity_type = ?', HydrationProviderInterface::TYPE_CUSTOMER)
        ));
        if ($workflowIds === []) {
            return 0;
        }

        $executionTable = $this->resourceConnection->getTableName(self::EXECUTION_TABLE);
        $scrubbed = 0;
        $cursor = 0;
        do {
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from($executionTable, ['execution_id', 'context'])
                    ->where('workflow_id IN (?)', $workflowIds)
                    ->where('entity_id = ?', $customerId)
                    ->where('execution_id > ?', $cursor)
                    ->order('execution_id')
                    ->limit(self::BATCH_SIZE)
            );
            if ($rows === []) {
                break;
            }

            $executionIds = [];
            foreach ($rows as $row) {
                $executionId = (int) $row['execution_id'];
                $executionIds[] = $executionId;
                $cursor = max($cursor, $executionId);

                $redacted = $this->redactContextWholesale($row['context'] ?? null);
                if ($redacted !== null) {
                    $connection->update(
                        $executionTable,
                        ['context' => $redacted],
                        ['execution_id = ?' => $executionId]
                    );
                }
            }

            $this->scrubStepRows($executionIds, $email, true);
            $scrubbed += count($executionIds);
        } while (count($rows) === self::BATCH_SIZE);

        return $scrubbed;
    }

    /**
     * Pass 2: targeted redaction of executions (any entity type) whose
     * context carries the deleted customer's email.
     */
    private function scrubEmailBearingExecutions(string $email): int
    {
        $connection = $this->resourceConnection->getConnection();
        $executionTable = $this->resourceConnection->getTableName(self::EXECUTION_TABLE);
        $likePattern = '%' . addcslashes($email, '%_\\') . '%';

        $scrubbed = 0;
        $cursor = 0;
        do {
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from($executionTable, ['execution_id', 'context'])
                    ->where('context LIKE ?', $likePattern)
                    ->where('execution_id > ?', $cursor)
                    ->order('execution_id')
                    ->limit(self::BATCH_SIZE)
            );
            if ($rows === []) {
                break;
            }

            $executionIds = [];
            foreach ($rows as $row) {
                $executionId = (int) $row['execution_id'];
                $executionIds[] = $executionId;
                $cursor = max($cursor, $executionId);

                $data = $this->decodeJson($row['context'] ?? null);
                if ($data === null) {
                    continue;
                }
                $redacted = $this->scrubArrayForEmail($data, $email);
                if ($redacted !== $data) {
                    $connection->update(
                        $executionTable,
                        ['context' => $this->encodeContext($redacted)],
                        ['execution_id = ?' => $executionId]
                    );
                    $scrubbed++;
                }
            }

            $this->scrubStepRows($executionIds, $email, false);
        } while (count($rows) === self::BATCH_SIZE);

        return $scrubbed;
    }

    /**
     * Redact the step rows of the given executions: full-redact mode
     * replaces the result JSON wholesale, targeted mode email-scrubs it;
     * both replace email occurrences inside the error text.
     *
     * @param int[] $executionIds
     */
    private function scrubStepRows(array $executionIds, string $email, bool $fullRedact): void
    {
        if ($executionIds === []) {
            return;
        }
        $connection = $this->resourceConnection->getConnection();
        $stepTable = $this->resourceConnection->getTableName(self::STEP_TABLE);

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($stepTable, ['step_execution_id', 'result', 'error'])
                ->where('execution_id IN (?)', $executionIds)
        );

        foreach ($rows as $row) {
            $update = [];

            $result = $row['result'] ?? null;
            if ($result !== null && $result !== '') {
                if ($fullRedact) {
                    $update['result'] = $this->encodeContext([self::REDACTION_MARKER_KEY => true]);
                } elseif ($email !== '') {
                    $decoded = $this->decodeJson($result);
                    if ($decoded !== null) {
                        $redacted = $this->scrubArrayForEmail($decoded, $email);
                        if ($redacted !== $decoded) {
                            $update['result'] = $this->encodeContext($redacted);
                        }
                    }
                }
            }

            $error = $row['error'] ?? null;
            if ($email !== '' && is_string($error) && stripos($error, $email) !== false) {
                $update['error'] = str_ireplace($email, self::REDACTED, $error);
            }

            if ($update !== []) {
                $connection->update(
                    $stepTable,
                    $update,
                    ['step_execution_id = ?' => (int) $row['step_execution_id']]
                );
            }
        }
    }

    /**
     * Wholesale redaction for customer-rooted rows: the trigger snapshot and
     * every step output become {"gdpr_redacted": true}; step keys and the
     * workflow metadata block survive. A NULL/empty context stays untouched;
     * corrupt JSON is overwritten entirely (its content is unknowable, so it
     * must be assumed to carry PII).
     *
     * @return string|null new context JSON, or null when nothing needs writing
     */
    private function redactContextWholesale(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $marker = [self::REDACTION_MARKER_KEY => true];

        $data = $this->decodeJson($raw);
        if ($data === null) {
            return $this->encodeContext(['trigger' => $marker, 'steps' => [], 'workflow' => []]);
        }

        $steps = [];
        if (is_array($data['steps'] ?? null)) {
            $steps = array_fill_keys(array_keys($data['steps']), $marker);
        }

        return $this->encodeContext([
            'trigger' => $marker,
            'steps' => $steps,
            'workflow' => is_array($data['workflow'] ?? null) ? $data['workflow'] : [],
        ]);
    }

    /**
     * Depth-first email scrub: every string value containing the email has
     * it replaced; an associative array in which any immediate string member
     * matched additionally has its PII_SIBLING_KEYS members redacted (the
     * email anchors attribution of the surrounding person record).
     */
    private function scrubArrayForEmail(array $data, string $email): array
    {
        $matched = false;
        foreach ($data as $value) {
            if (is_string($value) && stripos($value, $email) !== false) {
                $matched = true;
                break;
            }
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->scrubArrayForEmail($value, $email);
            } elseif (is_string($value) && stripos($value, $email) !== false) {
                $data[$key] = str_ireplace($email, self::REDACTED, $value);
            }
            if ($matched
                && $data[$key] !== null
                && $data[$key] !== ''
                && in_array(strtolower((string) $key), self::PII_SIBLING_KEYS, true)
            ) {
                $data[$key] = self::REDACTED;
            }
        }

        return $data;
    }

    private function decodeJson(?string $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Encode preserving {} (not []) for empty maps, matching the engine's
     * persistContext() so the stored JSON shape stays stable.
     */
    private function encodeContext(array $data): string
    {
        foreach (['trigger', 'steps', 'workflow'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] === []) {
                $data[$key] = new \stdClass();
            }
        }
        return (string) json_encode($data);
    }
}
