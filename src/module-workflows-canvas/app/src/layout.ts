import ELK from 'elkjs/lib/elk.bundled.js';
import type { Graph } from './types';

/**
 * elkjs layered auto-layout. Runs when a definition has no `ui` block (or a node
 * lacks a position). Deterministic layered layout suits these mostly-tree
 * graphs. Bundled locally (elk.bundled.js) — no web worker URL, no CDN.
 */
const elk = new ELK();

const NODE_W = 220;
const NODE_H = 88;

export async function autoLayout(graph: Graph): Promise<Record<string, { x: number; y: number }>> {
  const elkGraph = {
    id: 'root',
    layoutOptions: {
      'elk.algorithm': 'layered',
      'elk.direction': 'DOWN',
      'elk.layered.spacing.nodeNodeBetweenLayers': '64',
      'elk.spacing.nodeNode': '48',
    },
    children: graph.nodes.map((n) => ({ id: n.id, width: NODE_W, height: NODE_H })),
    edges: graph.edges.map((e) => ({ id: e.id, sources: [e.source], targets: [e.target] })),
  };

  const laid = await elk.layout(elkGraph);
  const positions: Record<string, { x: number; y: number }> = {};
  for (const child of laid.children ?? []) {
    positions[child.id] = { x: child.x ?? 0, y: child.y ?? 0 };
  }
  return positions;
}

/** True when at least one node has no persisted position (needs auto-layout). */
export function needsLayout(graph: Graph): boolean {
  return graph.nodes.some((n) => n.position.x === 0 && n.position.y === 0);
}
