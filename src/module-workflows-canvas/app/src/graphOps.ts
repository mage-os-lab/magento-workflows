import { canConnect, type ConnectParams } from './connectRules';
import { edgeLabel } from './edges';
import { nodeSummary } from './nodeSummary';
import { blankCase } from './switchCases';
import type { Graph, GraphEdge, GraphNode, MountConfig, StepNode, StepType } from './types';

/**
 * Pure, immutable graph editing operations (Phase B). Every op returns a NEW
 * Graph (never mutates its input) so the editor's undo/redo history is a plain
 * stack of snapshots and React re-renders cleanly. No DOM, no React — unit
 * tested exhaustively.
 *
 * These ops enforce only the structural, obviously-safe invariants (single
 * target per handle, valid connect per type, no dangling edges after a delete).
 * The server remains the authority for everything semantic.
 */

let nodeSeq = 0;

/** A collision-resistant new step key: s<n> not already used in the graph. */
export function nextStepKey(graph: Graph): string {
  const used = new Set(graph.nodes.map((n) => n.id));
  do {
    nodeSeq += 1;
  } while (used.has(`s${nodeSeq}`));
  return `s${nodeSeq}`;
}

/** Add a new node of the given step type at a position. First node becomes entry. */
export function addNode(
  graph: Graph,
  step: StepNode,
  position: { x: number; y: number },
  config: MountConfig,
  key?: string,
): Graph {
  const id = key ?? nextStepKey(graph);
  const isFirst = graph.nodes.length === 0;
  const node: GraphNode = {
    id,
    type: step.type,
    position,
    data: {
      stepKey: id,
      step,
      isEntry: isFirst,
      degraded: false,
      summary: nodeSummary(step, config.actions),
    },
  };
  return {
    ...graph,
    nodes: [...graph.nodes, node],
    entry: isFirst ? id : graph.entry,
  };
}

/**
 * A free spot for a click-added node: below the lowest existing node, aligned
 * to its column. The old fixed {80,80} drop point stacked every added step on
 * the same spot — three clicks produced what looked like ONE card, with the
 * hidden ones silently catching edge drops meant for the visible one.
 */
export function freePosition(graph: Graph): { x: number; y: number } {
  if (graph.nodes.length === 0) {
    return { x: 80, y: 220 };
  }
  let lowest = graph.nodes[0];
  for (const node of graph.nodes) {
    if (node.position.y > lowest.position.y) {
      lowest = node;
    }
  }
  return { x: lowest.position.x, y: lowest.position.y + 150 };
}

/** Remove a node and every edge touching it (no dangling edges survive). */
export function deleteNode(graph: Graph, id: string): Graph {
  const nodes = graph.nodes.filter((n) => n.id !== id);
  const edges = graph.edges.filter((e) => e.source !== id && e.target !== id);
  const entry = graph.entry === id ? (nodes[0]?.id ?? null) : graph.entry;
  return {
    ...graph,
    nodes: entry === graph.entry ? nodes : reflagEntry(nodes, entry),
    edges,
    entry,
  };
}

/**
 * Connect (or re-point) a handle. Rejected drags return the graph unchanged
 * (the caller surfaces the reason). A handle carries at most one edge, so an
 * existing edge on the same source+handle is replaced.
 */
export function connect(graph: Graph, params: ConnectParams): { graph: Graph; ok: boolean; reason?: string } {
  const verdict = canConnect(graph, params);
  if (!verdict.ok) {
    return { graph, ok: false, reason: verdict.reason };
  }
  const handle = params.sourceHandle as string;
  const edges = graph.edges.filter((e) => !(e.source === params.source && e.sourceHandle === handle));
  const edge: GraphEdge = {
    id: `${params.source}::${handle}`,
    source: params.source,
    target: params.target,
    sourceHandle: handle,
    label: edgeLabel(handle),
  };
  return { graph: { ...graph, edges: [...edges, edge] }, ok: true };
}

/** Remove a specific edge by id. */
export function disconnect(graph: Graph, edgeId: string): Graph {
  return { ...graph, edges: graph.edges.filter((e) => e.id !== edgeId) };
}

/** Move a node — updates its position in the returned graph. Layout-dirty is
 * tracked by the editor (a manual move arms the persist-on-save flag). */
export function moveNode(graph: Graph, id: string, position: { x: number; y: number }): Graph {
  return {
    ...graph,
    nodes: graph.nodes.map((n) => (n.id === id ? { ...n, position } : n)),
  };
}

/** Change which step is the entry (its incoming edges are removed). */
export function setEntry(graph: Graph, id: string): Graph {
  const nodes = reflagEntry(graph.nodes, id);
  const edges = graph.edges.filter((e) => e.target !== id);
  return { ...graph, nodes, edges, entry: id };
}

function reflagEntry(nodes: GraphNode[], entry: string | null): GraphNode[] {
  return nodes.map((n) => ({ ...n, data: { ...n.data, isEntry: n.id === entry } }));
}

/** Positions of every node (for persisting into `ui` on save). */
export function positionsOf(graph: Graph): Record<string, { x: number; y: number }> {
  const positions: Record<string, { x: number; y: number }> = {};
  for (const n of graph.nodes) {
    positions[n.id] = { ...n.position };
  }
  return positions;
}

/** The canonical blank step for a newly-dropped palette item of a given type. */
export function blankStep(type: StepType, action?: string): StepNode {
  switch (type) {
    case 'action':
      return { type: 'action', action: action ?? '', config: {}, next: null };
    case 'delay':
      return { type: 'delay', config: { duration: 'PT1H' }, next: null };
    case 'branch':
      return { type: 'branch', conditions_serialized: null, on_true: null, on_false: null };
    case 'wait':
      // `waitStep.config` requires BOTH event and timeout, so the timeout is
      // seeded the same way delay seeds duration and approval seeds P7D — the
      // event is the operator's to pick and cannot be defaulted.
      return {
        type: 'wait',
        config: { event: '', timeout: 'P1D' },
        on_event: null,
        on_timeout: null,
      };
    case 'switch':
      // One starter case, because `switchStep.cases` declares minItems 1
      // (Definition::assertSwitchStep rejects an empty list): a switch dropped
      // from the palette must be saveable without first hand-editing JSON.
      // conditions_serialized null = "always matches", so the starter case is
      // valid as-is and the panel's case editor renames/extends it.
      return { type: 'switch', cases: [blankCase('case_1')], default: null };
    case 'approval':
      // P7D default mirrors the form suggestion in docs/discovery/approval-gate.md §4
      // ("No indefinite parks" — timeout is required, P7D is the suggested default).
      return {
        type: 'approval',
        config: { title: '', timeout: 'P7D' },
        on_approved: null,
        on_rejected: null,
        on_timeout: null,
      };
    case 'stop':
    default:
      return { type: 'stop' };
  }
}
