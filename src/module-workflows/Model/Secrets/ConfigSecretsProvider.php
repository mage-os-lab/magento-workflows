<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Secrets;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use MageOS\Workflows\Model\Variable\SecretsProviderInterface;

/**
 * Secrets stored in mageos_workflow_secret, values encrypted at rest via
 * EncryptorInterface. Definitions reference them as {{ secrets.<key> }};
 * values are write-only in the UI and never exported.
 */
class ConfigSecretsProvider implements SecretsProviderInterface
{
    private const TABLE = 'mageos_workflow_secret';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function get(string $key): ?string
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable(), ['value'])
            ->where($connection->quoteIdentifier('key') . ' = ?', $key);

        $value = $connection->fetchOne($select);
        if ($value === false || $value === null) {
            return null;
        }

        return $this->encryptor->decrypt((string)$value);
    }

    public function set(string $key, string $value): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->insertOnDuplicate(
            $this->getTable(),
            [
                'key' => $key,
                'value' => $this->encryptor->encrypt($value),
            ],
            ['value']
        );
    }

    public function delete(string $key): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->delete(
            $this->getTable(),
            [$connection->quoteIdentifier('key') . ' = ?' => $key]
        );
    }

    /**
     * @inheritDoc
     */
    public function listKeys(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable(), ['key'])
            ->order('key ASC');

        return array_map('strval', $connection->fetchCol($select));
    }

    private function getTable(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE);
    }
}
