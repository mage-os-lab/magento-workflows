<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Webapi;

use MageOS\Workflows\Model\Variable\SecretsProviderInterface;
use MageOS\Workflows\Model\Webapi\SecretMetadataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GET /V1/workflows/meta/secrets returns NAMES only, never values.
 */
class SecretMetadataProviderTest extends TestCase
{
    private function secrets(array $keys): SecretsProviderInterface
    {
        return new class ($keys) implements SecretsProviderInterface {
            public function __construct(private readonly array $keys)
            {
            }

            public function get(string $key): ?string
            {
                throw new \LogicException('values never leave the server');
            }

            public function set(string $key, string $value): void
            {
            }

            public function delete(string $key): void
            {
            }

            public function listKeys(): array
            {
                return $this->keys;
            }
        };
    }

    public function testReturnsSecretNames(): void
    {
        $provider = new SecretMetadataProvider($this->secrets(['stripe_key', 'webhook_token']));
        $this->assertSame(['stripe_key', 'webhook_token'], $provider->getSecretNames());
    }

    public function testEmpty(): void
    {
        $this->assertSame([], (new SecretMetadataProvider($this->secrets([])))->getSecretNames());
    }
}
