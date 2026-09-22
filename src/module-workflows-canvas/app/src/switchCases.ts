import { edgeLabel } from './edges';
import { t } from './i18n';
import { inRange, moveItem, removeAt, replaceAt } from './listEdit';
import { nodeSummary } from './nodeSummary';
import type { Graph, GraphEdge, MountConfig, StepNode, SwitchCase } from './types';

/**
 * Switch case-list editing. These are GRAPH ops, not step ops, because a case
 * owns an edge: the switch node exposes one `case:<key>` handle per case
 * (edges.ts getStepEdges), so a case key is part of the edge model. Renaming a
 * case therefore has to re-point its edge (id + handle + label) in the same
 * commit, or mapping.rebuildStep — which re-points case targets BY KEY on the
 * way out — would silently drop the case's target on the next save.
 *
 * Invariants enforced here (the server is still the authority — see
 * `Definition::assertSwitchStep`):
 *   - keys match /^[a-zA-Z0-9_\-]{1,64}$/ and are unique within the step;
 *   - the case list never goes empty (the schema requires minItems 1, so an
 *     emptied switch could never be saved);
 *   - a rename preserves the case's `conditions_serialized` AND its target,
 *     both on the case object and on the graph edge;
 *   - a reorder is condition semantics only (first-match-wins order) and never
 *     touches edges, which are keyed by case key rather than by position.
 *
 * Pure and immutable: every op returns a NEW graph plus a user-facing `error`
 * (null on success); a rejected op returns the input graph untouched.
 */

/** The server's case-key grammar (Definition::assertSwitchStep). */
export const CASE_KEY_PATTERN = /^[a-zA-Z0-9_\-]{1,64}$/;

/** Longest key the server accepts. */
export const CASE_KEY_MAX_LENGTH = 64;

export interface CaseOpResult {
  graph: Graph;
  /** A message to render inline, or null when the op applied. */
  error: string | null;
}

export function isValidCaseKey(key: unknown): boolean {
  return typeof key === 'string' && CASE_KEY_PATTERN.test(key);
}

/**
 * Drop everything the server would reject and cap the length, so a key typed
 * into the panel is always either valid or empty. Sanitizing (rather than
 * rejecting) keeps the rename input honest: what the operator sees is what is
 * committed.
 */
export function sanitizeCaseKey(raw: unknown): string {
  return String(raw ?? '')
    .replace(/[^a-zA-Z0-9_-]/g, '')
    .slice(0, CASE_KEY_MAX_LENGTH);
}

/** The step's case list, tolerant of a hand-edited definition. */
export function casesOf(step: StepNode | null | undefined): SwitchCase[] {
  return step && Array.isArray(step.cases) ? step.cases : [];
}

/** `case_<n>`: the first such key not already used by the step. */
export function nextCaseKey(cases: readonly SwitchCase[]): string {
  const used = new Set(cases.map((c) => String(c?.key ?? '')));
  let n = cases.length + 1;
  while (used.has(`case_${n}`)) {
    n += 1;
  }
  return `case_${n}`;
}

/**
 * A new, empty case. `conditions_serialized: null` is "always matches" and
 * `next: null` an unwired edge — both explicit, both accepted by the schema,
 * so a freshly added case is immediately saveable.
 */
export function blankCase(key: string): SwitchCase {
  return { key, conditions_serialized: null, next: null };
}

/** Append a new case with a generated key. */
export function addCase(
  graph: Graph,
  stepKey: string,
  actions: MountConfig['actions'],
): CaseOpResult {
  const step = stepOf(graph, stepKey);
  if (!step || step.type !== 'switch') {
    return { graph, error: t('Not a switch step.') };
  }
  const cases = casesOf(step);
  return {
    graph: withCases(graph, stepKey, step, [...cases, blankCase(nextCaseKey(cases))], actions),
    error: null,
  };
}

/**
 * Remove a case and the `case:<key>` edge it owned. The last case cannot be
 * removed: `switchStep.cases` declares minItems 1, so an empty switch is a step
 * that can never be saved.
 */
export function removeCase(
  graph: Graph,
  stepKey: string,
  index: number,
  actions: MountConfig['actions'],
): CaseOpResult {
  const step = stepOf(graph, stepKey);
  if (!step || step.type !== 'switch') {
    return { graph, error: t('Not a switch step.') };
  }
  const cases = casesOf(step);
  if (!inRange(cases, index)) {
    return { graph, error: null };
  }
  if (cases.length === 1) {
    return { graph, error: t('A switch needs at least one case.') };
  }
  const removedKey = String(cases[index]?.key ?? '');
  const next = withCases(graph, stepKey, step, removeAt(cases, index), actions);
  return {
    graph: {
      ...next,
      edges: next.edges.filter(
        (e) => !(e.source === stepKey && e.sourceHandle === `case:${removedKey}`),
      ),
    },
    error: null,
  };
}

/**
 * Rename a case, carrying its conditions and its target with it. The stored
 * key is the SANITIZED input, so the control can never commit something the
 * server would bounce; an emptied or duplicate key is refused with a message
 * instead.
 */
export function renameCase(
  graph: Graph,
  stepKey: string,
  index: number,
  rawKey: string,
  actions: MountConfig['actions'],
): CaseOpResult {
  const step = stepOf(graph, stepKey);
  if (!step || step.type !== 'switch') {
    return { graph, error: t('Not a switch step.') };
  }
  const cases = casesOf(step);
  if (!inRange(cases, index)) {
    return { graph, error: null };
  }
  const key = sanitizeCaseKey(rawKey);
  if (key === '') {
    return { graph, error: t('A case key is required.') };
  }
  if (!isValidCaseKey(key)) {
    return { graph, error: t('Use letters, numbers, "_" or "-" (max 64 characters).') };
  }
  const previous = String(cases[index]?.key ?? '');
  if (key === previous) {
    return { graph, error: null };
  }
  if (cases.some((c, i) => i !== index && String(c?.key ?? '') === key)) {
    return { graph, error: `${t('This case key is already used by this switch:')} "${key}"` };
  }

  // Spread the existing case: its conditions_serialized, its `next` target and
  // anything else it carries survive the rename untouched.
  const renamed = replaceAt(cases, index, { ...cases[index], key });
  const next = withCases(graph, stepKey, step, renamed, actions);
  return { graph: { ...next, edges: rehandle(next.edges, stepKey, previous, key) }, error: null };
}

/**
 * Move a case by `delta` positions. Cases are first-match-wins, so order is
 * semantics; edges are keyed by case key and so are unaffected.
 */
export function moveCase(
  graph: Graph,
  stepKey: string,
  index: number,
  delta: number,
  actions: MountConfig['actions'],
): CaseOpResult {
  const step = stepOf(graph, stepKey);
  if (!step || step.type !== 'switch') {
    return { graph, error: t('Not a switch step.') };
  }
  const cases = casesOf(step);
  const moved = moveItem(cases, index, delta);
  if (moved === cases) {
    return { graph, error: null };
  }
  return { graph: withCases(graph, stepKey, step, moved, actions), error: null };
}

// ---- internals ----------------------------------------------------------

function stepOf(graph: Graph, stepKey: string): StepNode | null {
  return graph.nodes.find((n) => n.id === stepKey)?.data.step ?? null;
}

/**
 * Commit a new case list onto the node, refreshing the node summary (it counts
 * cases) so the canvas label never lags the panel.
 */
function withCases(
  graph: Graph,
  stepKey: string,
  step: StepNode,
  cases: SwitchCase[],
  actions: MountConfig['actions'],
): Graph {
  const next: StepNode = { ...step, cases };
  return {
    ...graph,
    nodes: graph.nodes.map((n) =>
      n.id === stepKey
        ? { ...n, data: { ...n.data, step: next, summary: nodeSummary(next, actions) } }
        : n,
    ),
  };
}

/** Re-point the step's `case:<old>` edge (id, handle and label) at `<new>`. */
function rehandle(
  edges: GraphEdge[],
  stepKey: string,
  oldKey: string,
  newKey: string,
): GraphEdge[] {
  const from = `case:${oldKey}`;
  const to = `case:${newKey}`;
  return edges.map((e) =>
    e.source === stepKey && e.sourceHandle === from
      ? { ...e, id: `${stepKey}::${to}`, sourceHandle: to, label: edgeLabel(to) }
      : e,
  );
}
