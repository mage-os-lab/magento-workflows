<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Validation;

/**
 * Carries the authoring circumstances a check may need (F2): the
 * authorization mode, the workflow kind, and whether this is a dry-run
 * (validate-endpoint) pass rather than an authoring save.
 */
class ValidationContext
{
    /**
     * An admin (session or token) is present; per-action ACL re-authorization runs.
     */
    public const MODE_ADMIN_CONTEXT = 'admin_context';

    /**
     * System privileges (CLI, cron, data patches); per-action ACL is skipped
     * with the documented loud warning — operators own that risk.
     */
    public const MODE_SYSTEM = 'system';

    /**
     * Per-entity workflow. The 'aggregated' kind arrives with batch
     * aggregation (docs/discovery/implementation/05-batch-aggregation.md).
     */
    public const KIND_STANDARD = 'standard';

    /**
     * Aggregated (batch) workflow — its mageos_workflow.aggregation column is
     * non-null. The batch ProfileCheck enforces the restricted definition
     * profile (no wait steps, batch-capable actions only, in-snapshot root
     * conditions) for this kind.
     */
    public const KIND_AGGREGATED = 'aggregated';

    public function __construct(
        private readonly string $authMode = self::MODE_ADMIN_CONTEXT,
        private readonly string $workflowKind = self::KIND_STANDARD,
        private readonly bool $dryRun = false,
        private readonly ?string $entityType = null
    ) {
    }

    public function getAuthMode(): string
    {
        return $this->authMode;
    }

    public function getWorkflowKind(): string
    {
        return $this->workflowKind;
    }

    /**
     * The workflow's entity type (e.g. sales_order), when known. Lets the
     * batch ProfileCheck resolve the trigger snapshot shape for the
     * in-snapshot root-condition constraint; null skips per-attribute
     * verification.
     */
    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    /**
     * True for validate-only passes (e.g. POST /V1/workflows/validate on a
     * definition being drafted) — not an authoring path.
     */
    public function isDryRun(): bool
    {
        return $this->dryRun;
    }
}
