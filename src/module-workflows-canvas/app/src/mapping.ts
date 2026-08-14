import { getStepEdges, edgeLabel } from './edges';
import { isDegraded, nodeSummary } from './nodeSummary';
import type {
  Definition,
  Graph,
  GraphEdge,
  GraphNode,
  MountConfig,
  StepNode,
  SwitchCase,
  UiBlock,
} from './types';

/**
 * The one "clever" owned piece: definition JSON <-> {nodes, edges}. Rules
 * (docs/discovery/canvas.md §7):
 *   - version gate: schema > known => read-only (never a lossy edit-save);
 *   - `ui` block read on load, written on layout change; nothing else outside
 *     schema is preserved (no unknown-field passthrough on save);
 *   - the edge model mirrors Definition::getStepEdges exactly.
 */

const EDGE_KEYS = [
  'next',
  'on_true',
  'on_false',
  'on_event',
  'on_timeout',
  'on_approved',
  'on_rejected',
  'default',
] as const;

export function isReadOnly(schema: number, knownSchemaVersion: number): boolean {
  return schema > knownSchemaVersion;
}

/**
 * Definition -> graph. Positions come from the `ui` block when present; a step
 * with no ui position gets {0,0} and is expected to be auto-laid-out (elkjs)
 * before render.
 */
export function toGraph(definition: Definition, config: MountConfig): Graph {
  const steps = definition.steps ?? {};
  const uiNodes = definition.ui?.nodes ?? {};

  const nodes: GraphNode[] = Object.entries(steps).map(([stepKey, step]) => {
    const pos = uiNodes[stepKey];
    const degraded = isDegraded(step, config.actions);
    return {
      id: stepKey,
      type: degraded ? 'degraded' : step.type,
      position: { x: Number(pos?.x ?? 0), y: Number(pos?.y ?? 0) },
      data: {
        stepKey,
        step,
        isEntry: definition.entry === stepKey,
        degraded,
        summary: nodeSummary(step, config.actions),
      },
    };
  });

  const edges: GraphEdge[] = [];
  for (const [stepKey, step] of Object.entries(steps)) {
    for (const [edgeName, target] of Object.entries(getStepEdges(step))) {
      if (target !== null && target in steps) {
        edges.push({
          id: `${stepKey}::${edgeName}`,
          source: stepKey,
          target,
          sourceHandle: edgeName,
          label: edgeLabel(edgeName),
        });
      }
    }
  }

  return {
    nodes,
    edges,
    schema: definition.schema,
    readOnly: isReadOnly(definition.schema, config.knownSchemaVersion),
    entry: definition.entry ?? null,
  };
}

/**
 * Graph -> definition. Reconstructs each step from its preserved node data,
 * overwriting only the edge fields from the current graph edges, then writes
 * layout into `ui`. Emits EXACTLY {schema, steps, entry(, ui)} — the same three
 * (+ui) keys the server re-emits; any field the schema does not declare is
 * dropped, never silently carried into a lossy save.
 */
export interface ToDefinitionOptions {
  /** Node positions to persist into `ui` (a manual move / auto-layout result). */
  positions?: Record<string, { x: number; y: number }>;
  /** The source definition's `ui`, so non-node keys (e.g. `canvas`) survive. */
  existingUi?: UiBlock;
}

export function toDefinition(graph: Graph, options: ToDefinitionOptions = {}): Definition {
  const outgoing = new Map<string, GraphEdge[]>();
  for (const e of graph.edges) {
    const list = outgoing.get(e.source) ?? [];
    list.push(e);
    outgoing.set(e.source, list);
  }

  const steps: Record<string, StepNode> = {};
  for (const node of graph.nodes) {
    steps[node.id] = rebuildStep(node.data.step, outgoing.get(node.id) ?? []);
  }

  const definition: Definition = {
    schema: graph.schema,
    entry: graph.entry,
    steps,
  };

  const ui = buildUi(graph, options.positions, options.existingUi);
  if (ui) {
    definition.ui = ui;
  }
  return definition;
}

/**
 * Deep-clone a step and set its edge fields from the graph edges. For switch,
 * case targets are written back into the matching case by key; the `default`
 * edge and the simple edge fields (including approval's on_approved /
 * on_rejected / on_timeout) are set directly. Non-edge fields (config,
 * conditions_serialized, revalidate_entity, action, …) survive untouched.
 */
function rebuildStep(original: StepNode, edges: GraphEdge[]): StepNode {
  const step: StepNode = structuredCloneSafe(original);
  const byHandle = new Map(edges.map((e) => [e.sourceHandle, e.target]));

  // Simple + branch + wait + approval + switch-default edges.
  for (const key of EDGE_KEYS) {
    if (key in step || byHandle.has(key)) {
      const target = byHandle.get(key);
      if (target !== undefined) {
        step[key] = target;
      } else if (originalHadEdge(original, key)) {
        // The edge existed in the source and still has no target -> keep null.
        step[key] = null;
      }
    }
  }

  // Switch case targets. A switch step's conditions live on its CASES; a
  // step-level conditions_serialized there is dead weight the executor never
  // reads (Executor reads cases[] only) and the schema rejects (`switchStep` is
  // additionalProperties:false), so a stray key from an older editor build — or
  // a hand-edited definition — is dropped on the way out rather than posted
  // into a save that would fail validation.
  if (step.type === 'switch') {
    delete step.conditions_serialized;
  }
  if (step.type === 'switch' && Array.isArray(step.cases)) {
    const originalCases = Array.isArray(original.cases) ? original.cases : [];
    step.cases = step.cases.map((c) => {
      const target = byHandle.get(`case:${c.key}`);
      if (target !== undefined) {
        return { ...c, next: target };
      }
      if (originalCaseHadEdge(originalCases, c.key)) {
        // Mirrors the scalar EDGE_KEYS handling above: the case originally
        // carried a `next` (even a stale one the graph no longer has an edge
        // for — deleteNode/disconnect only touch graph.edges, never
        // step.cases[].next) -> null it rather than leave a dangling target
        // the server would reject ("points to unknown step").
        return { ...c, next: null };
      }
      return c;
    });
  }

  return step;
}

function originalHadEdge(step: StepNode, key: string): boolean {
  return Object.prototype.hasOwnProperty.call(step, key);
}

/** Whether the original (pre-edit) case for `key` already declared a `next`. */
function originalCaseHadEdge(cases: SwitchCase[], key: string): boolean {
  const match = cases.find((c) => c && typeof c === 'object' && String(c.key ?? '') === key);
  return match !== undefined && Object.prototype.hasOwnProperty.call(match, 'next');
}

/**
 * Build the `ui` block from node positions, merging over any pre-existing ui
 * (non-node keys like `canvas` are preserved). Returns undefined when there is
 * nothing to persist so a layout-free definition stays ui-free.
 */
export function buildUi(
  graph: Graph,
  positions?: Record<string, { x: number; y: number }>,
  existing?: UiBlock,
): UiBlock | undefined {
  // Prefer explicit positions (a manual move / auto-layout result); else use
  // whatever the nodes already carry.
  const source = positions ?? Object.fromEntries(graph.nodes.map((n) => [n.id, n.position]));

  const nodes: Record<string, { x: number; y: number } & Record<string, unknown>> = {
    ...(existing?.nodes ?? {}),
  };
  let touched = false;
  for (const [id, pos] of Object.entries(source)) {
    if (pos && (pos.x !== 0 || pos.y !== 0 || nodes[id])) {
      nodes[id] = { ...(nodes[id] ?? {}), x: pos.x, y: pos.y };
      touched = true;
    }
  }

  if (!touched && !existing) {
    return undefined;
  }

  const ui: UiBlock = { ...(existing ?? {}) };
  if (Object.keys(nodes).length) {
    ui.nodes = nodes;
  }
  return ui;
}

function structuredCloneSafe<T>(value: T): T {
  if (typeof structuredClone === 'function') {
    return structuredClone(value);
  }
  return JSON.parse(JSON.stringify(value)) as T;
}
