import { describe, expect, it } from 'vitest';
import {
  addNode,
  blankStep,
  connect,
  deleteNode,
  disconnect,
  moveNode,
  positionsOf,
  setEntry,
} from '../src/graphOps';
import { toDefinition } from '../src/mapping';
import { makeConfig, makeGraph } from './support';
import type { Definition, Graph } from '../src/types';

const config = makeConfig();

function emptyGraph(): Graph {
  return { nodes: [], edges: [], schema: 3, readOnly: false, entry: null };
}

const twoStep: Definition = {
  schema: 3,
  entry: 'a',
  steps: {
    a: { type: 'action', action: 'x', config: {}, next: 'b' },
    b: { type: 'stop' },
  },
};

describe('addNode', () => {
  it('adds a node and makes the first one the entry', () => {
    let g = emptyGraph();
    g = addNode(g, blankStep('action', 'x'), { x: 0, y: 0 }, config, 'a');
    expect(g.nodes).toHaveLength(1);
    expect(g.entry).toBe('a');
    expect(g.nodes[0].data.isEntry).toBe(true);
  });

  it('does not mutate the input graph (immutable)', () => {
    const g = emptyGraph();
    const g2 = addNode(g, blankStep('stop'), { x: 0, y: 0 }, config);
    expect(g.nodes).toHaveLength(0);
    expect(g2.nodes).toHaveLength(1);
  });

  it('blankStep("wait") seeds the required timeout (the event stays the author\'s call)', () => {
    // waitStep.config requires event AND timeout; without the timeout a
    // freshly-dropped wait step could never save.
    expect(blankStep('wait')).toEqual({
      type: 'wait',
      config: { event: '', timeout: 'P1D' },
      on_event: null,
      on_timeout: null,
    });
  });

  it('blankStep("approval") seeds a required timeout and all three null edges', () => {
    const step = blankStep('approval');
    expect(step).toEqual({
      type: 'approval',
      config: { title: '', timeout: 'P7D' },
      on_approved: null,
      on_rejected: null,
      on_timeout: null,
    });
  });
});

describe('connect / disconnect', () => {
  it('connects a valid handle and replaces an occupied one', () => {
    const g = makeGraph(twoStep, config);
    // a.next already -> b; re-point to a new stop node.
    let g2 = addNode(g, blankStep('stop'), { x: 10, y: 10 }, config, 'c');
    const r = connect(g2, { source: 'a', sourceHandle: 'next', target: 'c' });
    expect(r.ok).toBe(true);
    g2 = r.graph;
    const outFromA = g2.edges.filter((e) => e.source === 'a' && e.sourceHandle === 'next');
    expect(outFromA).toHaveLength(1);
    expect(outFromA[0].target).toBe('c');
  });

  it('rejects an invalid connection and returns the graph unchanged', () => {
    const g = makeGraph(twoStep, config);
    const r = connect(g, { source: 'a', sourceHandle: 'next', target: 'a' });
    expect(r.ok).toBe(false);
    expect(r.graph).toBe(g);
  });

  it('disconnect removes an edge by id', () => {
    const g = makeGraph(twoStep, config);
    const g2 = disconnect(g, 'a::next');
    expect(g2.edges.find((e) => e.id === 'a::next')).toBeUndefined();
  });
});

describe('deleteNode', () => {
  it('removes the node and all edges touching it', () => {
    const g = makeGraph(twoStep, config);
    const g2 = deleteNode(g, 'b');
    expect(g2.nodes.find((n) => n.id === 'b')).toBeUndefined();
    expect(g2.edges.find((e) => e.source === 'b' || e.target === 'b')).toBeUndefined();
  });

  it('re-assigns entry when the entry node is deleted', () => {
    const g = makeGraph(twoStep, config);
    const g2 = deleteNode(g, 'a');
    expect(g2.entry).toBe('b');
    expect(g2.nodes.find((n) => n.id === 'b')?.data.isEntry).toBe(true);
  });
});

describe('setEntry', () => {
  it('moves entry and drops the new entry incoming edges', () => {
    const g = makeGraph(twoStep, config);
    const g2 = setEntry(g, 'b');
    expect(g2.entry).toBe('b');
    expect(g2.edges.find((e) => e.target === 'b')).toBeUndefined();
  });
});

describe('moveNode + layout-dirty persistence', () => {
  it('a manual move updates the position and persists into ui on save', () => {
    let g = makeGraph(twoStep, config);
    g = moveNode(g, 'a', { x: 120, y: 40 });
    expect(g.nodes.find((n) => n.id === 'a')?.position).toEqual({ x: 120, y: 40 });

    // Save maps positions into the ui block (Phase-A gate #1).
    const def = toDefinition(g, { positions: positionsOf(g) });
    expect(def.ui?.nodes?.a).toEqual({ x: 120, y: 40 });
  });
});
