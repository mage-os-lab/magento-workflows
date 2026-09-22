<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\Secrets;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Console\Command\SecretDeleteCommand;
use MageOS\Workflows\Console\Command\SecretListCommand;
use MageOS\Workflows\Console\Command\SecretSetCommand;
use MageOS\Workflows\Model\Secrets\ConfigSecretsProvider;
use MageOS\Workflows\Model\Secrets\SecretKeyValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Plan #17 (docs/20-integration-test-plan.md §5) — secret storage against the
 * real DB (docs/10 §Secrets): values are EncryptorInterface-encrypted at rest,
 * the key validator guards the storage boundary, and the workflow:secret:*
 * console commands round-trip through the object-manager-built commands with
 * list never printing a value.
 *
 * @magentoDbIsolation enabled
 */
class SecretStorageTest extends TestCase
{
    private const TABLE = 'mageos_workflow_secret';

    private ConfigSecretsProvider $provider;
    private ResourceConnection $resource;
    private EncryptorInterface $encryptor;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->provider = $objectManager->get(ConfigSecretsProvider::class);
        $this->resource = $objectManager->get(ResourceConnection::class);
        $this->encryptor = $objectManager->get(EncryptorInterface::class);
    }

    public function testValueIsEncryptedAtRestAndRoundTrips(): void
    {
        $plaintext = 'sk_live_0123456789_secret';
        $this->provider->set('stripe_key', $plaintext);

        $raw = $this->rawStoredValue('stripe_key');
        $this->assertNotFalse($raw, 'Secret row must exist');
        $this->assertNotSame($plaintext, $raw, 'Stored column must not be the plaintext');
        $this->assertStringNotContainsString($plaintext, (string)$raw, 'Plaintext must not appear anywhere in the ciphertext');

        // Decrypts back through the real encryptor, and the provider read agrees.
        $this->assertSame($plaintext, $this->encryptor->decrypt((string)$raw));
        $this->assertSame($plaintext, $this->provider->get('stripe_key'));
    }

    public function testSetOverwritesInPlaceAndDeleteRemoves(): void
    {
        $this->provider->set('rotating', 'first');
        $this->assertSame('first', $this->provider->get('rotating'));

        // set() is create-or-rotate (insertOnDuplicate) — one row, new value.
        $this->provider->set('rotating', 'second');
        $this->assertSame('second', $this->provider->get('rotating'));
        $this->assertSame(1, $this->rowCount('rotating'));

        $this->provider->delete('rotating');
        $this->assertNull($this->provider->get('rotating'));
        $this->assertSame(0, $this->rowCount('rotating'));
    }

    public function testKeyValidatorBoundary(): void
    {
        $validator = Bootstrap::getObjectManager()->get(SecretKeyValidator::class);

        foreach (['fraud_hmac', 'api.stripe-key', 'a', 'A1_b-c.d2'] as $valid) {
            $this->assertTrue($validator->isValid($valid), sprintf('"%s" should be valid', $valid));
        }
        foreach (['', ' ', 'has space', 'slash/key', 'semi;colon', 'dot.', '.leading', 'bra{ce}'] as $invalid) {
            $this->assertFalse($validator->isValid($invalid), sprintf('"%s" should be rejected', $invalid));
        }
    }

    public function testSetListDeleteThroughCommandTester(): void
    {
        $setTester = new CommandTester(Bootstrap::getObjectManager()->get(SecretSetCommand::class));
        $exit = $setTester->execute(['key' => 'cli_secret', '--value' => 'TOP-SECRET-CLI']);
        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('saved', $setTester->getDisplay());
        $this->assertSame('TOP-SECRET-CLI', $this->provider->get('cli_secret'));

        // list prints the key name, NEVER the value.
        $listTester = new CommandTester(Bootstrap::getObjectManager()->get(SecretListCommand::class));
        $this->assertSame(Command::SUCCESS, $listTester->execute([]));
        $listOutput = $listTester->getDisplay();
        $this->assertStringContainsString('cli_secret', $listOutput);
        $this->assertStringNotContainsString('TOP-SECRET-CLI', $listOutput, 'list must never print a secret value');

        // delete removes it and reports the deletion.
        $deleteTester = new CommandTester(Bootstrap::getObjectManager()->get(SecretDeleteCommand::class));
        $this->assertSame(Command::SUCCESS, $deleteTester->execute(['key' => 'cli_secret']));
        $this->assertStringContainsString('deleted', $deleteTester->getDisplay());
        $this->assertNull($this->provider->get('cli_secret'));
    }

    public function testSetCommandRejectsAnInvalidKey(): void
    {
        $setTester = new CommandTester(Bootstrap::getObjectManager()->get(SecretSetCommand::class));
        $exit = $setTester->execute(['key' => 'bad key', '--value' => 'x']);
        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('Invalid secret key', $setTester->getDisplay());
    }

    private function rawStoredValue(string $key): string|false
    {
        $connection = $this->resource->getConnection();
        return $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['value'])
                ->where($connection->quoteIdentifier('key') . ' = ?', $key)
        );
    }

    private function rowCount(string $key): int
    {
        $connection = $this->resource->getConnection();
        return (int)$connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), 'COUNT(*)')
                ->where($connection->quoteIdentifier('key') . ' = ?', $key)
        );
    }
}
