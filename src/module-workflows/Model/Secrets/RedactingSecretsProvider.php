<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Secrets;

use MageOS\Workflows\Model\Variable\SecretsProviderInterface;

/**
 * Simulation substrate (F7): a decorator over the real secrets provider
 * that resolves values to ***<name>*** so simulated runs (dry-run traces,
 * optionally shadow mode) never materialize real secret values into
 * rendered configs, traces, or logs.
 *
 * Wiring: bound via the VariableResolverForDryRun virtualType in di.xml —
 * never as a preference swap of the shared production provider. A secret
 * that does not exist still resolves to null so simulation and production
 * agree on missing-secret behavior (placeholder renders empty).
 *
 * Reads are the only redacted surface: set/delete/listKeys pass through to
 * the decorated provider (they serve the admin secrets UI, not resolution).
 */
class RedactingSecretsProvider implements SecretsProviderInterface
{
    public function __construct(
        private readonly SecretsProviderInterface $provider
    ) {
    }

    public function get(string $key): ?string
    {
        return $this->provider->get($key) !== null ? '***' . $key . '***' : null;
    }

    public function set(string $key, string $value): void
    {
        $this->provider->set($key, $value);
    }

    public function delete(string $key): void
    {
        $this->provider->delete($key);
    }

    /**
     * @inheritDoc
     */
    public function listKeys(): array
    {
        return $this->provider->listKeys();
    }
}
