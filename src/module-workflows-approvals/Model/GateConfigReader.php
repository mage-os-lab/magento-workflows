<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model;

use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\WorkflowExecutionRepositoryInterface;
use MageOS\Workflows\Model\Definition\Definition;

/**
 * Reads one approval gate's config.* from the execution's pinned definition
 * snapshot (docs/discovery/approval-gate.md §3 — title/instructions/etc. are
 * park-time snapshots, so resumption/admin surfaces never depend on the live,
 * possibly-since-edited definition). The single reader behind every Stage 3
 * consumer that needs gate config: the bulk-decide allow_bulk gate (§6), the
 * decision panel's payload_fields form (§6), the park-notification's
 * notify_emails (§6), and the execution-view panel's "is this step an open
 * approval gate" check (§6 execution-view panel).
 *
 * Never throws: an execution or step that no longer resolves (deleted,
 * retention-pruned, stale snapshot) yields an empty/null config rather than a
 * hard failure in any of these read paths.
 */
class GateConfigReader
{
    public function __construct(
        private readonly WorkflowExecutionRepositoryInterface $executionRepository
    ) {
    }

    /**
     * @return array<string, mixed> the step's config.* array, or [] when unresolvable
     */
    public function getConfig(int $executionId, string $stepKey): array
    {
        $definition = $this->loadDefinition($executionId);
        if ($definition === null || !$definition->hasStep($stepKey)) {
            return [];
        }
        $config = $definition->getStep($stepKey)['config'] ?? null;
        return is_array($config) ? $config : [];
    }

    /**
     * @return array<int, array<string, mixed>>|null null when the gate declares no payload_fields
     */
    public function getPayloadFields(int $executionId, string $stepKey): ?array
    {
        $fields = $this->getConfig($executionId, $stepKey)['payload_fields'] ?? null;
        return is_array($fields) && $fields !== [] ? $fields : null;
    }

    public function isBulkAllowed(int $executionId, string $stepKey): bool
    {
        return ($this->getConfig($executionId, $stepKey)['allow_bulk'] ?? false) === true;
    }

    /**
     * @return string[] validated recipient addresses; malformed/absent config
     *                   degrades to an empty list rather than a failure. Save-time
     *                   validation (Definition::assertApprovalStep) already requires
     *                   a non-empty list of non-empty strings when notify_emails is
     *                   present; this reader additionally tolerates a bare string
     *                   (runtime leniency) and filters to RFC-valid addresses.
     */
    public function getNotifyEmails(int $executionId, string $stepKey): array
    {
        $raw = $this->getConfig($executionId, $stepKey)['notify_emails'] ?? null;
        if (is_string($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw)) {
            return [];
        }
        $emails = [];
        foreach ($raw as $candidate) {
            if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $candidate;
            }
        }
        return $emails;
    }

    /**
     * Whether $stepKey is an approval-type step in this execution's snapshot —
     * the execution-view panel's render gate (§6): only render for a parked
     * approval, never for a plain wait/delay.
     */
    public function isApprovalStep(int $executionId, string $stepKey): bool
    {
        $definition = $this->loadDefinition($executionId);
        if ($definition === null || !$definition->hasStep($stepKey)) {
            return false;
        }
        return ($definition->getStep($stepKey)['type'] ?? null) === Definition::STEP_APPROVAL;
    }

    private function loadDefinition(int $executionId): ?Definition
    {
        try {
            $execution = $this->executionRepository->getById($executionId);
        } catch (\Throwable $e) {
            return null;
        }
        $snapshot = $execution instanceof WorkflowExecutionInterface ? $execution->getDefinitionSnapshot() : '';
        if ($snapshot === '') {
            return null;
        }
        try {
            return Definition::fromJson($snapshot);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
