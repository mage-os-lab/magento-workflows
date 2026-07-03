<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Variable;

/**
 * Named, encrypted secret values referenced from definitions as
 * {{ secrets.<key> }}. Values are EncryptorInterface-encrypted at rest,
 * write-only in the UI, and never exported with definitions.
 */
interface SecretsProviderInterface
{
    public function get(string $key): ?string;

    public function set(string $key, string $value): void;

    public function delete(string $key): void;

    /**
     * Key names only, never values
     *
     * @return string[]
     */
    public function listKeys(): array;
}
