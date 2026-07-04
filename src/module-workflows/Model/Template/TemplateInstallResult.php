<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Template;

use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\Import\ImportResult;

/**
 * Outcome of a TemplateInstaller::install() run: the created workflow (disabled
 * or shadow — never enabled), the underlying import result (its non-blocking
 * warnings travel to the calling surface), the provenance row id, the secrets
 * that were created after the save, and any deferred-secret failures to surface
 * as a "create these secrets" follow-up (the workflow still exists; a definition
 * referencing a not-yet-created secret is valid — secrets resolve at run time).
 */
final class TemplateInstallResult
{
    /**
     * @param string[] $createdSecrets
     * @param array<string, string> $secretFailures secret key name => error message
     */
    public function __construct(
        private readonly ImportResult $importResult,
        private readonly int $provenanceId,
        private readonly string $templateCode,
        private readonly string $templateVersion,
        private readonly array $createdSecrets = [],
        private readonly array $secretFailures = []
    ) {
    }

    public function getWorkflow(): WorkflowInterface
    {
        return $this->importResult->getWorkflow();
    }

    public function getImportResult(): ImportResult
    {
        return $this->importResult;
    }

    public function getProvenanceId(): int
    {
        return $this->provenanceId;
    }

    public function getTemplateCode(): string
    {
        return $this->templateCode;
    }

    public function getTemplateVersion(): string
    {
        return $this->templateVersion;
    }

    /**
     * @return string[]
     */
    public function getCreatedSecrets(): array
    {
        return $this->createdSecrets;
    }

    /**
     * @return array<string, string>
     */
    public function getSecretFailures(): array
    {
        return $this->secretFailures;
    }
}
