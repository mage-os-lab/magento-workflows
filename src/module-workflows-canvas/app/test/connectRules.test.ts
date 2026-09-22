/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

import { describe, expect, it } from 'vitest';
import { canConnect } from '../src/connectRules';
import { makeGraph } from './support';
import type { Definition } from '../src/types';

/**
 * The connect-rule matrix per step type (Phase B). Handles come from the F1
 * edge model; the server stays the authority — these are early-reject UX rules.
 */

// entry -> every step type as a source, plus a common sink target "sink".
const def: Definition = {
  schema: 3,
  entry: 'entry',
  steps: {
    entry: { type: 'action', action: 'a', config: {}, next: null },
    act: { type: 'action', action: 'a', config: {}, next: null },
    del: { type: 'delay', config: { duration: 'PT1H' }, next: null },
    br: { type: 'branch', conditions_serialized: null, on_true: null, on_false: null },
    wt: { type: 'wait', config: { event: 'e' }, on_event: null, on_timeout: null },
    sw: { type: 'switch', cases: [{ key: 'hi', next: null }], default: null },
    ap: {
      type: 'approval',
      config: { title: 'Approve', timeout: 'P3D' },
      on_approved: null,
      on_rejected: null,
      on_timeout: null,
    },
    st: { type: 'stop' },
    sink: { type: 'stop' },
  },
};

const graph = makeGraph(def);

describe('canConnect — valid handles per step type', () => {
  it('action/delay accept the single next handle', () => {
    expect(canConnect(graph, { source: 'act', sourceHandle: 'next', target: 'sink' }).ok).toBe(true);
    expect(canConnect(graph, { source: 'del', sourceHandle: 'next', target: 'sink' }).ok).toBe(true);
  });

  it('branch accepts on_true and on_false only', () => {
    expect(canConnect(graph, { source: 'br', sourceHandle: 'on_true', target: 'sink' }).ok).toBe(true);
    expect(canConnect(graph, { source: 'br', sourceHandle: 'on_false', target: 'sink' }).ok).toBe(true);
    expect(canConnect(graph, { source: 'br', sourceHandle: 'next', target: 'sink' }).ok).toBe(false);
  });

  it('wait accepts on_event and on_timeout only', () => {
    expect(canConnect(graph, { source: 'wt', sourceHandle: 'on_event', target: 'sink' }).ok).toBe(true);
    expect(canConnect(graph, { source: 'wt', sourceHandle: 'on_timeout', target: 'sink' }).ok).toBe(true);
    expect(canConnect(graph, { source: 'wt', sourceHandle: 'on_true', target: 'sink' }).ok).toBe(false);
  });

  it('switch accepts one handle per case plus default', () => {
    expect(canConnect(graph, { source: 'sw', sourceHandle: 'case:hi', target: 'sink' }).ok).toBe(true);
    expect(canConnect(graph, { source: 'sw', sourceHandle: 'default', target: 'sink' }).ok).toBe(true);
    expect(canConnect(graph, { source: 'sw', sourceHandle: 'case:nope', target: 'sink' }).ok).toBe(false);
  });

  it('approval accepts on_approved, on_rejected, and on_timeout only', () => {
    expect(canConnect(graph, { source: 'ap', sourceHandle: 'on_approved', target: 'sink' }).ok).toBe(true);
    expect(canConnect(graph, { source: 'ap', sourceHandle: 'on_rejected', target: 'sink' }).ok).toBe(true);
    expect(canConnect(graph, { source: 'ap', sourceHandle: 'on_timeout', target: 'sink' }).ok).toBe(true);
    expect(canConnect(graph, { source: 'ap', sourceHandle: 'on_true', target: 'sink' }).ok).toBe(false);
  });

  it('stop originates nothing (no source handles)', () => {
    const v = canConnect(graph, { source: 'st', sourceHandle: 'next', target: 'sink' });
    expect(v.ok).toBe(false);
    expect(v.reason).toMatch(/stop step/i);
  });
});

describe('canConnect — structural rejects', () => {
  it('rejects a self-loop', () => {
    expect(canConnect(graph, { source: 'act', sourceHandle: 'next', target: 'act' }).ok).toBe(false);
  });

  it('rejects a connection into the entry step', () => {
    const v = canConnect(graph, { source: 'act', sourceHandle: 'next', target: 'entry' });
    expect(v.ok).toBe(false);
    expect(v.reason).toMatch(/entry/i);
  });

  it('rejects an unknown source or target', () => {
    expect(canConnect(graph, { source: 'ghost', sourceHandle: 'next', target: 'sink' }).ok).toBe(false);
    expect(canConnect(graph, { source: 'act', sourceHandle: 'next', target: 'ghost' }).ok).toBe(false);
  });

  it('rejects a null/absent source handle', () => {
    expect(canConnect(graph, { source: 'act', sourceHandle: null, target: 'sink' }).ok).toBe(false);
  });
});
