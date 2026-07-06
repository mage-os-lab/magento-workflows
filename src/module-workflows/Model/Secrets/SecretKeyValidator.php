<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Secrets;

/**
 * Validates secret key names for the `workflow:secret:*` CLI (GitHub issue
 * #3: set()/delete() on ConfigSecretsProvider had no entry point).
 *
 * The allowed shape mirrors what Model\Variable\VariableResolver::PLACEHOLDER
 * actually accepts for a `{{ secrets.<key> }}` reference: one or more
 * dot-separated segments, each made of letters, digits, underscore, or
 * hyphen. Rejecting anything else up front means a key created via this CLI
 * can always be referenced from a definition, and vice versa. See
 * docs/10-security.md ("Secrets": write-only values, redacted logs) — this
 * class never sees or handles secret values, only key names.
 */
class SecretKeyValidator
{
    private const PATTERN = '/^[A-Za-z0-9_\-]+(?:\.[A-Za-z0-9_\-]+)*$/';

    /**
     * Human-readable description of the allowed shape, for error messages.
     */
    public const DESCRIPTION =
        'letters, digits, underscore, and hyphen, with optional dot-separated segments '
        . '(e.g. "fraud_hmac" or "api.stripe-key")';

    public function isValid(string $key): bool
    {
        return $key !== '' && preg_match(self::PATTERN, $key) === 1;
    }
}
