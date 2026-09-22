<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Template;

use Magento\Framework\Exception\LocalizedException;
use MageOS\Workflows\Model\Import\WorkflowImporter;
use MageOS\Workflows\Model\Variable\SecretsProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates a template install (06 §4, discovery §4). The pipeline is
 * ordered so that nothing is written until the shared import path has committed
 * the workflow, and secrets are the very last step:
 *
 *   1. schema-validate the envelope (format tag, template + workflow nodes)
 *   2. compatibility check (requires + default-locale)  — abort before any write
 *   3. parameter substitution (leftover token = hard error) — abort before any write
 *   4. LIFT template.workflow into a synthetic mageos-workflow-export/1 envelope
 *      (re-inject the format tag the template deliberately omits)
 *   5. hand it to WorkflowImporter (F3): F2 pipeline + per-action ACL re-auth in
 *      ADMIN_CONTEXT, create disabled (default) or shadow — never enabled
 *   6. record provenance (after the save)
 *   7. DEFERRED secret creation (after save + provenance): a failed save reaches
 *      neither, so it leaves no orphaned secrets; a secret-creation failure
 *      leaves the workflow in place (a definition referencing a not-yet-created
 *      secret is valid — secrets resolve at run time) and is surfaced as a
 *      "create these secrets" follow-up.
 *
 * The install is exactly the untrusted-import trust boundary
 * (docs/10-security.md): substitution never bypasses schema/action/ACL checks.
 */
class TemplateInstaller
{
    /** Template envelope format tag — a thin layer over the export envelope. */
    public const FORMAT = 'mageos-workflow-template/1';

    public function __construct(
        private readonly TemplateSourceInterface $templateSource,
        private readonly CompatibilityChecker $compatibilityChecker,
        private readonly ParameterEngine $parameterEngine,
        private readonly WorkflowImporter $workflowImporter,
        private readonly InstallProvenance $provenance,
        private readonly SecretsProviderInterface $secretsProvider,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @throws LocalizedException on an unknown/incompatible template, a
     *         parameter/substitution failure, or a failed save
     */
    public function install(TemplateInstallRequest $request): TemplateInstallResult
    {
        $envelope = $this->decode($this->templateSource->get($request->getCode()));
        [$template, $workflow] = $this->splitEnvelope($envelope);
        $summary = TemplateSummary::fromTemplateNode($template);

        $compat = $this->compatibilityChecker->check($summary, $request->getLocale());
        if (!$compat->isCompatible()) {
            throw new LocalizedException(__(
                'This template cannot be installed on this store: %1',
                implode(' ', $compat->getMessages())
            ));
        }

        $substituted = $this->parameterEngine->apply(
            $workflow,
            $summary->getParameters(),
            $request->getParameters()
        );

        // Lift into the synthetic export envelope for the shared import path.
        $exportEnvelope = ['format' => WorkflowImporter::FORMAT] + $substituted;

        // The save. If it throws, nothing below runs — no provenance, no secrets.
        $importResult = $this->workflowImporter->import(
            $exportEnvelope,
            $request->getAuthMode(),
            $request->getStatus()
        );
        $workflowId = (int) $importResult->getWorkflow()->getWorkflowId();

        $provenanceId = $this->provenance->record(
            $workflowId,
            $summary->getCode(),
            $summary->getVersion(),
            $request->getParameters(),
            $request->getInstalledBy()
        );

        [$created, $failures] = $this->createSecrets($request->getSecretsToCreate());

        return new TemplateInstallResult(
            $importResult,
            $provenanceId,
            $summary->getCode(),
            $summary->getVersion(),
            $created,
            $failures
        );
    }

    /**
     * @return array{0: string[], 1: array<string, string>} [created keys, key => error]
     */
    private function createSecrets(array $secretsToCreate): array
    {
        $created = [];
        $failures = [];
        foreach ($secretsToCreate as $key => $value) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }
            try {
                $this->secretsProvider->set($key, (string) $value);
                $created[] = $key;
            } catch (\Throwable $e) {
                $failures[$key] = $e->getMessage();
                $this->logger->error(sprintf(
                    'Template install: deferred secret "%s" could not be created: %s',
                    $key,
                    $e->getMessage()
                ));
            }
        }
        return [$created, $failures];
    }

    /**
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    private function decode(string $rawJson): array
    {
        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded)) {
            throw new LocalizedException(__('The workflow template is not a JSON object.'));
        }
        return $decoded;
    }

    /**
     * Validate the envelope shape and split it into [template, workflow] nodes.
     *
     * @param array<string, mixed> $envelope
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     * @throws LocalizedException
     */
    private function splitEnvelope(array $envelope): array
    {
        $format = $envelope['format'] ?? null;
        if ($format !== self::FORMAT) {
            throw new LocalizedException(__(
                'Unsupported template format "%1"; expected "%2".',
                (string) $format,
                self::FORMAT
            ));
        }
        $template = $envelope['template'] ?? null;
        if (!is_array($template) || ($template['code'] ?? '') === '') {
            throw new LocalizedException(__('The template envelope is missing its "template" object.'));
        }
        $workflow = $envelope['workflow'] ?? null;
        if (!is_array($workflow)) {
            throw new LocalizedException(__('The template envelope is missing its "workflow" object.'));
        }
        return [$template, $workflow];
    }
}
