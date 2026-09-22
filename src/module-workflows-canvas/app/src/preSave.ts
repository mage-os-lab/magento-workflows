import { edgeLabel, getStepEdges } from './edges';
import { t } from './i18n';
import type { Graph, MountConfig } from './types';
import type { EditableMeta } from './workflowMeta';

/**
 * Client-side pre-save review (merchant-review finding: "saving succeeds
 * silently with required fields empty and half-wired flows"). The server
 * pipeline stays the authority — these checks exist so the FIRST save click
 * surfaces the problems a non-technical admin most commonly ships, in plain
 * language, instead of letting "it saved" stand in for "it works". The save
 * button offers "Save anyway" — none of this hard-blocks.
 */
export interface PreSaveFinding {
  /** Step key the finding is about, or null for workflow-level findings. */
  stepKey: string | null;
  message: string;
}

export function preSaveFindings(graph: Graph, config: MountConfig, meta: EditableMeta): PreSaveFinding[] {
  const findings: PreSaveFinding[] = [];

  // Unregistered trigger event: saves fine, then never fires — with no clue
  // why. Free-text entry stays legal (custom modules dispatch custom events),
  // so this warns rather than blocks.
  if (
    meta.triggerType === 'event' &&
    meta.triggerRef !== '' &&
    config.triggers.length > 0 &&
    !config.triggers.some((tr) => tr.event === meta.triggerRef)
  ) {
    findings.push({
      stepKey: null,
      message: `${t('The trigger event')} "${meta.triggerRef}" ${t('does not match any registered event, so this workflow may never run. Pick one from the Trigger event list, or double-check the spelling.')}`,
    });
  }

  for (const node of graph.nodes) {
    const step = node.data.step;

    // Required action-config fields left empty.
    if (step.type === 'action') {
      const actionMeta = config.actionsMeta.find((a) => a.code === step.action);
      for (const field of actionMeta?.configForm ?? []) {
        if (!field.required) {
          continue;
        }
        const value = (step.config ?? {})[field.name];
        const empty =
          value === undefined || value === null || value === '' || (Array.isArray(value) && value.length === 0);
        if (empty) {
          findings.push({
            stepKey: node.id,
            message: `"${node.data.summary}" ${t('has no value for its required field')} "${field.label}".`,
          });
        }
      }
    }

    // Dangling paths: a handle with no edge means that outcome goes nowhere.
    // (An action/delay with no `next` is a legitimate end-of-flow, so single
    // `next` handles are exempt — but every branch/switch/wait/approval
    // outcome is a real routing decision the author has to make.)
    const handles = Object.keys(getStepEdges(step));
    if (handles.length > 1) {
      const wired = new Set(graph.edges.filter((e) => e.source === node.id).map((e) => e.sourceHandle));
      for (const handle of handles) {
        if (!wired.has(handle)) {
          findings.push({
            stepKey: node.id,
            message: `"${node.data.summary}" — ${t('the')} "${edgeLabel(handle) || handle}" ${t('path leads nowhere. Connect it to a step, or to a Stop if it should end there.')}`,
          });
        }
      }
    }
  }

  return findings;
}
