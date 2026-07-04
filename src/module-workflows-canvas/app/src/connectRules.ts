import { edgeHandles } from './edges';
import type { Graph, GraphNode } from './types';

/**
 * Connect rules (Phase B). Client-side UX only — the server remains the sole
 * authority (GraphValidator runs on every save; the debounced validate loop
 * mirrors it live). These rules reject obviously-invalid drags early so the
 * editor never builds an edge the server would only bounce.
 *
 * The handle set per step type comes from the F1 edge model (edgeHandles):
 *   action/delay -> [next]
 *   branch       -> [on_true, on_false]
 *   wait         -> [on_event, on_timeout]
 *   switch       -> [case:<key>…, default]   (data-dependent)
 *   stop         -> []                        (a sink: originates nothing)
 * Each handle carries at most ONE outgoing edge (a step key or null); a new
 * connection on an occupied handle REPLACES its target (handled in graphOps),
 * it is not a second parallel edge.
 */

export interface ConnectParams {
  source: string;
  sourceHandle: string | null;
  target: string;
}

export interface ConnectVerdict {
  ok: boolean;
  reason?: string;
}

export function canConnect(graph: Graph, params: ConnectParams): ConnectVerdict {
  const { source, sourceHandle, target } = params;

  const sourceNode = findNode(graph, source);
  if (!sourceNode) {
    return { ok: false, reason: 'Unknown source step.' };
  }
  const targetNode = findNode(graph, target);
  if (!targetNode) {
    return { ok: false, reason: 'Unknown target step.' };
  }

  const handles = edgeHandles(sourceNode.data.step);
  if (handles.length === 0) {
    return { ok: false, reason: 'A stop step has no outgoing path.' };
  }
  if (sourceHandle === null || !handles.includes(sourceHandle)) {
    return { ok: false, reason: 'That connection point is not valid for this step type.' };
  }
  if (source === target) {
    return { ok: false, reason: 'A step cannot connect to itself.' };
  }
  if (targetNode.data.isEntry || graph.entry === target) {
    return { ok: false, reason: 'The entry step cannot be a target.' };
  }

  return { ok: true };
}

function findNode(graph: Graph, id: string): GraphNode | undefined {
  return graph.nodes.find((n) => n.id === id);
}
