<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Idempotency;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\AlreadyExistsException;

/**
 * Production SendClaimStoreInterface adapter over `mageos_workflow_send_log`.
 *
 * The claim is a bare INSERT against UNIQUE(claim_key) and the duplicate-key
 * error IS the answer — the same insert-and-catch idiom
 * Dispatcher::passesDebounce uses, and for the same reason: a SELECT-then-
 * INSERT check would reopen exactly the race this class exists to close.
 * (insertOnDuplicate is deliberately NOT used here: it would silently touch
 * the existing row and report "2 affected", conflating the winner with the
 * loser on some drivers.)
 *
 * Claim key = "<scope>:<dedupe key>", stored verbatim rather than hashed so
 * the table stays greppable in an incident ("who claimed execution X step Y")
 * — a scope is <= 64 chars and a dedupe key is UUID + step key (<= 101), well
 * inside the 191-char column.
 */
class SendClaimStore implements SendClaimStoreInterface
{
    private const TABLE = 'mageos_workflow_send_log';

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function claim(string $scope, string $dedupeKey): bool
    {
        $connection = $this->resourceConnection->getConnection();

        try {
            $connection->insert($this->resourceConnection->getTableName(self::TABLE), [
                'claim_key' => $this->claimKey($scope, $dedupeKey),
                'scope' => $scope,
                'status' => self::STATUS_CLAIMED,
                'claimed_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (AlreadyExistsException $e) {
            return false;
        } catch (\Magento\Framework\DB\Adapter\DuplicateException $e) {
            return false;
        } catch (\Exception $e) {
            if ($this->isDuplicateKeyException($e)) {
                return false;
            }
            // Anything else (connection lost, table missing) is NOT a claim
            // answer: rethrow so the caller parks the step instead of sending.
            throw $e;
        }
        return true;
    }

    public function confirm(string $scope, string $dedupeKey): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName(self::TABLE),
            ['status' => self::STATUS_SENT, 'sent_at' => gmdate('Y-m-d H:i:s')],
            ['claim_key = ?' => $this->claimKey($scope, $dedupeKey)]
        );
    }

    public function release(string $scope, string $dedupeKey): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->delete(
            $this->resourceConnection->getTableName(self::TABLE),
            ['claim_key = ?' => $this->claimKey($scope, $dedupeKey)]
        );
    }

    public function find(string $scope, string $dedupeKey): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from(
                    $this->resourceConnection->getTableName(self::TABLE),
                    ['status', 'claimed_at', 'sent_at']
                )
                ->where('claim_key = ?', $this->claimKey($scope, $dedupeKey))
        );
        if (!is_array($row) || $row === []) {
            return null;
        }
        return [
            'status' => (string)($row['status'] ?? self::STATUS_CLAIMED),
            'claimed_at' => isset($row['claimed_at']) ? (string)$row['claimed_at'] : null,
            'sent_at' => isset($row['sent_at']) ? (string)$row['sent_at'] : null,
        ];
    }

    private function claimKey(string $scope, string $dedupeKey): string
    {
        return $scope . ':' . $dedupeKey;
    }

    /**
     * Duplicate-key detection across adapters/wrappers, mirroring
     * Dispatcher::isDuplicateKeyException: SQLSTATE 23000 or the driver's
     * "Duplicate entry" text, unwrapped through the exception chain.
     */
    private function isDuplicateKeyException(\Exception $e): bool
    {
        do {
            if ($e instanceof \PDOException && (string)$e->getCode() === '23000') {
                return true;
            }
            if (stripos($e->getMessage(), 'Duplicate entry') !== false) {
                return true;
            }
            $e = $e->getPrevious();
        } while ($e instanceof \Exception);
        return false;
    }
}
