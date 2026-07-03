<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Stub;

use MageOS\Workflows\Model\Variable\SecretsProviderInterface;

/**
 * In-memory SecretsProviderInterface stand-in for unit tests: no encryption,
 * no persistence, just an array keyed by secret name.
 */
class SecretsProviderStub implements SecretsProviderInterface
{
    /** @var array<string, string> */
    private array $secrets;

    /**
     * @param array<string, string> $secrets
     */
    public function __construct(array $secrets = [])
    {
        $this->secrets = $secrets;
    }

    public function get(string $key): ?string
    {
        return $this->secrets[$key] ?? null;
    }

    public function set(string $key, string $value): void
    {
        $this->secrets[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->secrets[$key]);
    }

    public function listKeys(): array
    {
        return array_keys($this->secrets);
    }
}
