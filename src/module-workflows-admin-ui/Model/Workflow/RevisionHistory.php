<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Model\Workflow;

use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\PlainLanguageRenderer;

/**
 * Assembles the change-history list (docs/11-admin-ui.md -- "Change history
 * with diffs: definitions are versioned already ... expose it ... rendered as
 * plain-language before/after") from WorkflowRevision::getRevisions() rows:
 * each archived definition/conditions pair becomes a plain-language sentence
 * via the shared core PlainLanguageRenderer.
 *
 * The revision table only archives `definition` and `conditions_serialized`
 * (see mageos_workflow_revision in etc/db_schema.xml) -- trigger wiring,
 * entity type, fan-out and aggregation aren't versioned per-row, so every
 * revision sentence is rendered using the *current* workflow's values for
 * those fields, paired with that revision's archived definition/conditions.
 *
 * Diffs are explicitly out of scope here (this is the list view only); a
 * malformed archived definition must not break the whole page, so rendering
 * one entry is wrapped and degrades to a fixed fallback string.
 *
 * Deliberately DB-free and Template\Context-free: the block owns fetching the
 * raw revision rows (via the resource model) and the current workflow (via
 * the registry); this class only turns data already in hand into display
 * entries, so it can be unit tested without a resource model or a backend
 * block context.
 */
class RevisionHistory
{
    public function __construct(
        private readonly PlainLanguageRenderer $plainLanguageRenderer
    ) {
    }

    /**
     * Plain-language sentence for the workflow's current (unarchived) state.
     */
    public function getCurrentSentence(WorkflowInterface $workflow): string
    {
        return $this->plainLanguageRenderer->render($workflow);
    }

    /**
     * @param array<int, array<string, mixed>> $revisionRows WorkflowRevision::getRevisions() rows
     * @return array<int, array{version: int, created_at: ?string, sentence: string}>
     */
    public function buildEntries(WorkflowInterface $workflow, array $revisionRows): array
    {
        $entries = [];
        foreach ($revisionRows as $row) {
            $entries[] = [
                'version' => (int) ($row['version'] ?? 0),
                'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
                'sentence' => $this->renderRevisionSentence($workflow, $row),
            ];
        }
        return $entries;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function renderRevisionSentence(WorkflowInterface $workflow, array $row): string
    {
        try {
            $conditions = $row['conditions_serialized'] ?? null;
            return $this->plainLanguageRenderer->renderFromFields(
                $workflow->getTriggerType(),
                $workflow->getTriggerRef(),
                $workflow->getEntityType(),
                $conditions !== null ? (string) $conditions : null,
                (string) ($row['definition'] ?? ''),
                $workflow->getFanOut(),
                $workflow->getAggregation()
            );
        } catch (\Throwable $e) {
            return (string) __('(unrenderable revision)');
        }
    }
}
