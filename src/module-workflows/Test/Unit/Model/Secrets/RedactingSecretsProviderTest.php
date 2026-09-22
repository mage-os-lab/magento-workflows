<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Secrets;

use MageOS\Workflows\Model\Secrets\RedactingSecretsProvider;
use MageOS\Workflows\Test\Unit\Stub\SecretsProviderStub;
use PHPUnit\Framework\TestCase;

class RedactingSecretsProviderTest extends TestCase
{
    public function testExistingSecretResolvesToMaskedName(): void
    {
        $provider = new RedactingSecretsProvider(
            new SecretsProviderStub(['fraud_hmac' => 'super-secret-value'])
        );

        $this->assertSame('***fraud_hmac***', $provider->get('fraud_hmac'));
    }

    public function testMissingSecretStaysNullForBehaviorParity(): void
    {
        $provider = new RedactingSecretsProvider(new SecretsProviderStub([]));

        $this->assertNull($provider->get('nope'));
    }

    public function testMaskNeverContainsTheRealValue(): void
    {
        $provider = new RedactingSecretsProvider(
            new SecretsProviderStub(['api_key' => 'hunter2'])
        );

        $this->assertFalse(str_contains((string) $provider->get('api_key'), 'hunter2'));
    }

    public function testListKeysPassesThrough(): void
    {
        $provider = new RedactingSecretsProvider(
            new SecretsProviderStub(['a' => '1', 'b' => '2'])
        );

        $this->assertSame(['a', 'b'], $provider->listKeys());
    }
}
