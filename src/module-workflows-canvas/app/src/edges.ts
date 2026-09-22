import { t } from './i18n';
import type { EdgeMap, StepNode } from './types';

/**
 * The step's full declared edge map, read from step data — a faithful port of
 * PHP Definition::getStepEdges (F1). This MUST stay byte-identical in naming to
 * the server helper: consumers (mapping, overlay, plain language) route on
 * these exact edge names.
 *
 *   action/delay => { next }
 *   branch       => { on_true, on_false }
 *   wait         => { on_event, on_timeout }
 *   switch       => { 'case:<key>': …, …, default }
 *   approval     => { on_approved, on_rejected, on_timeout }
 *   stop         => {}
 */
export function getStepEdges(step: StepNode): EdgeMap {
  const edge = (v: unknown): string | null =>
    v !== undefined && v !== null ? String(v) : null;

  switch (step.type) {
    case 'action':
    case 'delay':
      return { next: edge(step.next) };
    case 'branch':
      return { on_true: edge(step.on_true), on_false: edge(step.on_false) };
    case 'wait':
      return { on_event: edge(step.on_event), on_timeout: edge(step.on_timeout) };
    case 'approval':
      return {
        on_approved: edge(step.on_approved),
        on_rejected: edge(step.on_rejected),
        on_timeout: edge(step.on_timeout),
      };
    case 'switch': {
      const edges: EdgeMap = {};
      for (const c of step.cases ?? []) {
        if (c && typeof c === 'object' && typeof c.key === 'string') {
          edges[`case:${c.key}`] = edge(c.next);
        }
      }
      edges.default = edge(step.default);
      return edges;
    }
    case 'stop':
    default:
      return {};
  }
}

/**
 * The set of handle names a step type exposes, ignoring current targets — the
 * "connect rules" table the editor (Phase B) gates on. For switch the handles
 * are data-dependent (one per case + default), so the node is required.
 */
export function edgeHandles(step: StepNode): string[] {
  return Object.keys(getStepEdges(step));
}

/**
 * Human label for an edge name, for edge rendering. Resolved through t() at
 * call time (the phrase map is installed before anything renders); the EDGE
 * NAMES themselves are machine values and never translated.
 */
export function edgeLabel(edgeName: string): string {
  switch (edgeName) {
    case 'next':
      return '';
    case 'on_true':
      return t('yes');
    case 'on_false':
      return t('no');
    case 'on_event':
      return t('on event');
    case 'on_timeout':
      return t('on timeout');
    case 'on_approved':
      return t('approved');
    case 'on_rejected':
      return t('rejected');
    case 'default':
      return t('default');
    default:
      return edgeName.startsWith('case:') ? edgeName.slice('case:'.length) : edgeName;
  }
}
