<?php
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

    public function __construct(
        private readonly string $authMode = self::MODE_ADMIN_CONTEXT,
        private readonly string $workflowKind = self::KIND_STANDARD,
        private readonly bool $dryRun = false
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
     * True for validate-only passes (e.g. POST /V1/workflows/validate on a
     * definition being drafted) — not an authoring path.
     */
    public function isDryRun(): bool
    {
        return $this->dryRun;
    }
}
