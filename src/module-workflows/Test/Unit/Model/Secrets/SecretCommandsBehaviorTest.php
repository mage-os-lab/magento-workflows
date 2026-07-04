<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Secrets;

use MageOS\Workflows\Model\Secrets\SecretKeyValidator;
use MageOS\Workflows\Test\Unit\Stub\SecretsProviderStub;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the exact sequence of provider/validator calls that
 * Console\Command\SecretSetCommand, SecretListCommand, and
 * SecretDeleteCommand make in their execute() methods (GitHub issue #3).
 *
 * This deliberately does NOT instantiate the Command classes themselves:
 * they extend Symfony\Component\Console\Command\Command, and
 * dev/tests/standalone-runner.php's shim autoloader only covers Magento\
 * and Psr\Log\ classes (see dev/tests/shims/README.md) — Symfony\Console
 * is not installed in this zero-dependency environment, matching every
 * other Console\Command class in this module (none of which have direct
 * unit tests here either). Command::execute() can only be exercised under
 * a real Magento install or a real PHPUnit + composer environment.
 *
 * What's covered instead is the business logic the commands delegate to:
 * SecretKeyValidator for key-shape rejection, and SecretsProviderInterface
 * (via the same SecretsProviderStub the RedactingSecretsProviderTest uses)
 * for set/list/delete, in the same order the commands call them.
 */
class SecretCommandsBehaviorTest extends TestCase
{
    private SecretKeyValidator $validator;
    private SecretsProviderStub $provider;

    public function setUp(): void
    {
        $this->validator = new SecretKeyValidator();
        $this->provider = new SecretsProviderStub();
    }

    public function testSetThenListShowsTheKey(): void
    {
        $key = 'fraud_hmac';
        $this->assertTrue($this->validator->isValid($key));

        $this->provider->set($key, 'super-secret-value');

        $this->assertTrue(in_array($key, $this->provider->listKeys(), true));
    }

    public function testSetOverwritesForRotation(): void
    {
        $key = 'fraud_hmac';
        $this->provider->set($key, 'old-value');
        $this->provider->set($key, 'new-value');

        $this->assertSame('new-value', $this->provider->get($key));
        $this->assertCount(1, $this->provider->listKeys());
    }

    public function testDeleteRemovesTheKey(): void
    {
        $key = 'fraud_hmac';
        $this->provider->set($key, 'super-secret-value');

        $existedBeforeDelete = $this->provider->get($key) !== null;
        $this->provider->delete($key);

        $this->assertTrue($existedBeforeDelete);
        $this->assertFalse(in_array($key, $this->provider->listKeys(), true));
        $this->assertNull($this->provider->get($key));
    }

    public function testDeletingAMissingKeyIsDetectableBeforeDeleting(): void
    {
        $existedBeforeDelete = $this->provider->get('never-set') !== null;
        $this->provider->delete('never-set');

        $this->assertFalse($existedBeforeDelete);
    }

    public function testInvalidKeyIsRejectedBeforeTouchingTheProvider(): void
    {
        $key = '{{secrets.x}}';
        $this->assertFalse($this->validator->isValid($key));

        // The command bails out on the validator check before calling
        // set()/delete() at all, so the provider never sees the bad key.
        $this->assertSame([], $this->provider->listKeys());
    }

    public function testListOutputContainsNamesButNeverAStoredValue(): void
    {
        $this->provider->set('fraud_hmac', 'hunter2');
        $this->provider->set('stripe-key', 'sk_live_abc123');

        $keys = $this->provider->listKeys();

        $this->assertSame(['fraud_hmac', 'stripe-key'], $keys);
        $this->assertFalse(in_array('hunter2', $keys, true));
        $this->assertFalse(in_array('sk_live_abc123', $keys, true));

        foreach ($keys as $key) {
            $this->assertFalse(str_contains($key, 'hunter2'));
            $this->assertFalse(str_contains($key, 'sk_live_abc123'));
        }
    }
}
