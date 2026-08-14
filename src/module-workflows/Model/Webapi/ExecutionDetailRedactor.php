<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Webapi;

use MageOS\Workflows\Model\Variable\SecretsProviderInterface;

/**
 * The ONE place that pairs the redaction rules (StepDetailRedactor) with the
 * server-side secret map they need, for every surface that shows stored
 * execution detail to a human.
 *
 * Why this exists: step `result`/`error` and the execution `context` blob are
 * written by the PRODUCTION executor from interpolated action config, and a
 * webhook URL carrying a token or a raw Guzzle exception message lands in them
 * verbatim. The steps REST route was redacting; the admin execution-view page
 * and the single-execution / list REST routes were not, and all three are
 * reachable at the weakest grant (::view). One collaborator, injected into all
 * of them, is what keeps the rules from forking: nobody re-implements "mask a
 * secret", they call redact().
 *
 * The secret map is resolved lazily and memoized for the life of the instance —
 * decrypting every secret once per request instead of once per step row. It is
 * never returned to a caller that could serialize it; secretValues() stays
 * protected so only the step provider (which must hand the map to its static
 * summarizer) can reach it, and only from a subclass/same-package context.
 */
class ExecutionDetailRedactor
{
    /**
     * Resolved name => plaintext map, or null until first use.
     *
     * @var array<string, string>|null
     */
    private ?array $secretValues = null;

    public function __construct(
        private readonly SecretsProviderInterface $secretsProvider,
        private readonly StepDetailRedactor $redactor
    ) {
    }

    /**
     * Mask known secret values and generic credential shapes out of one stored
     * detail string. Null/empty passes through untouched (callers rely on that
     * to keep "no error" distinguishable from "empty error").
     */
    public function redact(?string $value): ?string
    {
        return $this->redactor->redact($value, $this->secretValues());
    }

    /**
     * The shared rule set, for the one caller that needs to pass it to a static
     * helper rather than call redact() directly. (Test seam.)
     */
    public function getRedactor(): StepDetailRedactor
    {
        return $this->redactor;
    }

    /**
     * Resolved name => plaintext secret map, for exact redaction. Read
     * server-side only; never returned to a client. (Test seam.)
     *
     * @return array<string, string>
     */
    public function secretValues(): array
    {
        if ($this->secretValues !== null) {
            return $this->secretValues;
        }

        $map = [];
        foreach ($this->secretsProvider->listKeys() as $name) {
            $value = $this->secretsProvider->get($name);
            if ($value !== null && $value !== '') {
                $map[(string) $name] = $value;
            }
        }

        return $this->secretValues = $map;
    }
}
