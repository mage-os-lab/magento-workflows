<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Template;

use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\Validation\ValidationContext;

/**
 * A single template install: the code, the parameter values, the initial status
 * (disabled by default, shadow recommended), the authorization mode, who is
 * installing, and any pick-or-create secrets to write AFTER a successful save.
 *
 * Secrets are deliberately separate from the parameter values: a `secret`-typed
 * parameter's *value* is the secret's key name (safe to snapshot); the secret's
 * *value* — supplied only by the merchant at install time — lives here and is
 * never persisted to provenance.
 */
class TemplateInstallRequest
{
    /**
     * @param array<string, mixed> $parameters key => value (form / CLI / patch)
     * @param array<string, string> $secretsToCreate secret key name => plaintext value (new secrets only)
     */
    public function __construct(
        private readonly string $code,
        private readonly array $parameters = [],
        private readonly int $status = WorkflowInterface::STATUS_DISABLED,
        private readonly string $authMode = ValidationContext::MODE_ADMIN_CONTEXT,
        private readonly string $installedBy = 'system',
        private readonly array $secretsToCreate = [],
        private readonly string $locale = LocalizedText::DEFAULT_LOCALE
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getAuthMode(): string
    {
        return $this->authMode;
    }

    public function getInstalledBy(): string
    {
        return $this->installedBy;
    }

    /**
     * @return array<string, string>
     */
    public function getSecretsToCreate(): array
    {
        return $this->secretsToCreate;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }
}
