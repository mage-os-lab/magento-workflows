import { describe, expect, it } from 'vitest';
import {
  addNode,
  blankStep,
  connect,
  deleteNode,
  disconnect,
  positionsOf,
} from '../src/graphOps';
import { toDefinition, toGraph } from '../src/mapping';
import { makeConfig } from './support';
import type { Graph, StepNode } from '../src/types';

/**
 * Edit-then-serialize round-trip: build a graph BY EDITING (graphOps), not by
 * loading a fixture, then serialize via the mapping layer and assert every
 * edge lands in the schema key the server routes on (next / on_true / on_false
 * / case:<key> / default / on_approved / on_rejected / on_timeout), and that
 * the emitted definition maps back to an equivalent graph. This exercises the
 * rebuildStep paths that fixture round-trips (which start from a definition)
 * can miss — freshly-authored steps whose edge fields were seeded by blankStep
 * and whose targets exist only as graph edges.
 */

const config = makeConfig({
  knownSchemaVersion: 4,
  approvalsAvailable: true,
  actions: { 'order.add_comment': { label: 'Add Comment', group: 'Sales' } },
});

function emptyGraph(): Graph {
  return { nodes: [], edges: [], schema: 4, readOnly: false, entry: null };
}

/** Author the full menagerie by editing: action -> branch -> switch/approval. */
function authorGraph(): Graph {
  let g = emptyGraph();
  g = addNode(g, blankStep('action', 'order.add_comment'), { x: 40, y: 40 }, config, 'start');
  g = addNode(g, blankStep('branch'), { x: 40, y: 160 }, config, 'gate');
  const switchStep: StepNode = { type: 'switch', cases: [{ key: 'high' }, { key: 'low' }], default: null };
  g = addNode(g, switchStep, { x: 240, y: 160 }, config, 'route');
  g = addNode(g, blankStep('approval'), { x: 40, y: 280 }, config, 'approve');
  g = addNode(g, blankStep('delay'), { x: 240, y: 280 }, config, 'cool');
  g = addNode(g, blankStep('stop'), { x: 140, y: 400 }, config, 'end');

  const wire = (source: string, sourceHandle: string, target: string): void => {
    const r = connect(g, { source, sourceHandle, target });
    expect(r.ok, `${source} -[${sourceHandle}]-> ${target}`).toBe(true);
    g = r.graph;
  };

  wire('start', 'next', 'gate');
  wire('gate', 'on_true', 'route');
  wire('gate', 'on_false', 'end');
  wire('route', 'case:high', 'approve');
  wire('route', 'case:low', 'cool');
  wire('route', 'default', 'end');
  wire('approve', 'on_approved', 'cool');
  wire('approve', 'on_rejected', 'end');
  wire('approve', 'on_timeout', 'end');
  wire('cool', 'next', 'end');

  return g;
}

describe('edit-then-serialize — edges land in the right definition keys', () => {
  const graph = authorGraph();
  const def = toDefinition(graph, { positions: positionsOf(graph) });

  it('emits exactly the schema top-level keys and the authored entry', () => {
    expect(Object.keys(def).sort()).toEqual(['entry', 'schema', 'steps', 'ui']);
    expect(def.schema).toBe(4);
    expect(def.entry).toBe('start');
    expect(Object.keys(def.steps).sort()).toEqual(['approve', 'cool', 'end', 'gate', 'route', 'start']);
  });

  it('routes action/delay edges into next', () => {
    expect(def.steps.start.next).toBe('gate');
    expect(def.steps.cool.next).toBe('end');
  });

  it('routes branch edges into on_true / on_false', () => {
    expect(def.steps.gate.on_true).toBe('route');
    expect(def.steps.gate.on_false).toBe('end');
  });

  it('routes switch edges into the matching case by key, and default', () => {
    expect(def.steps.route.cases).toEqual([
      { key: 'high', next: 'approve' },
      { key: 'low', next: 'cool' },
    ]);
    expect(def.steps.route.default).toBe('end');
  });

  it('routes approval tri-edges into on_approved / on_rejected / on_timeout', () => {
    expect(def.steps.approve.on_approved).toBe('cool');
    expect(def.steps.approve.on_rejected).toBe('end');
    expect(def.steps.approve.on_timeout).toBe('end');
  });

  it('a stop step stays a bare sink (no edge keys leak in)', () => {
    expect(def.steps.end).toEqual({ type: 'stop' });
  });

  it('non-edge authored fields survive rebuildStep untouched', () => {
    expect(def.steps.start.action).toBe('order.add_comment');
    expect(def.steps.start.config).toEqual({});
    expect(def.steps.gate.conditions_serialized).toBeNull();
    expect(def.steps.approve.config).toEqual({ title: '', timeout: 'P7D' });
    expect(def.steps.cool.config).toEqual({ duration: 'PT1H' });
  });

  it('persists every authored position into ui.nodes', () => {
    expect(def.ui?.nodes?.start).toEqual({ x: 40, y: 40 });
    expect(def.ui?.nodes?.route).toEqual({ x: 240, y: 160 });
    expect(def.ui?.nodes?.end).toEqual({ x: 140, y: 400 });
  });

  it('round-trips back to an equivalent graph (nodes, edges, entry, positions)', () => {
    const g2 = toGraph(def, config);
    expect(g2.readOnly).toBe(false);
    expect(g2.entry).toBe('start');

    const nodeShape = (g: Graph): Array<[string, string, number, number]> =>
      g.nodes.map((n) => [n.id, n.type, n.position.x, n.position.y] as [string, string, number, number]).sort();
    expect(nodeShape(g2)).toEqual(nodeShape(graph));

    const edgeShape = (g: Graph): Array<[string, string, string]> =>
      g.edges.map((e) => [e.source, e.sourceHandle, e.target] as [string, string, string]).sort();
    expect(edgeShape(g2)).toEqual(edgeShape(graph));
  });

  it('is idempotent: serializing the round-tripped graph reproduces the definition', () => {
    const g2 = toGraph(def, config);
    expect(toDefinition(g2, { positions: positionsOf(g2), existingUi: def.ui })).toEqual(def);
  });
});

describe('edit-then-serialize — edits after authoring serialize faithfully', () => {
  it('re-pointing an occupied handle replaces the target in the definition', () => {
    let g = authorGraph();
    const r = connect(g, { source: 'gate', sourceHandle: 'on_false', target: 'cool' });
    expect(r.ok).toBe(true);
    g = r.graph;
    const def = toDefinition(g);
    expect(def.steps.gate.on_false).toBe('cool');
    // Still one on_false edge, not two.
    expect(g.edges.filter((e) => e.source === 'gate' && e.sourceHandle === 'on_false')).toHaveLength(1);
  });

  it('disconnecting an edge nulls a declared edge field and unsets a case target', () => {
    let g = authorGraph();
    g = disconnect(g, 'gate::on_false');
    g = disconnect(g, 'route::case:low');
    const def = toDefinition(g);
    // blankStep declared on_false, so it stays as an explicit null.
    expect(def.steps.gate).toHaveProperty('on_false', null);
    // The authored case never carried next; it stays without one.
    expect(def.steps.route.cases).toEqual([{ key: 'high', next: 'approve' }, { key: 'low' }]);
  });

  it('deleting a node nulls upstream references instead of leaving dangling targets', () => {
    let g = authorGraph();
    g = deleteNode(g, 'cool');
    const def = toDefinition(g);
    expect(def.steps.cool).toBeUndefined();
    expect(def.steps.approve.on_approved).toBeNull();
    expect(def.steps.route.cases).toEqual([{ key: 'high', next: 'approve' }, { key: 'low' }]);
    // No edge in the definition points at the deleted step.
    expect(JSON.stringify(def)).not.toContain('cool');
  });
});
