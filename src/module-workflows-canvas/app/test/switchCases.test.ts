import { describe, expect, it } from 'vitest';
import {
  addCase,
  blankCase,
  casesOf,
  isValidCaseKey,
  moveCase,
  nextCaseKey,
  removeCase,
  renameCase,
  sanitizeCaseKey,
} from '../src/switchCases';
import { blankStep } from '../src/graphOps';
import { toDefinition } from '../src/mapping';
import { getStepEdges } from '../src/edges';
import { makeConfig, makeGraph } from './support';
import type { Definition, Graph, StepNode } from '../src/types';

/**
 * The switch case-list editor's contract. A case key IS an edge handle
 * (`case:<key>`), and mapping re-points case targets BY KEY on the way out, so
 * the interesting assertions are all about what survives a key change: the
 * case's conditions, its target, and the graph edge that carries it.
 */

const config = makeConfig();
const TREE = '{"type":"Combine","aggregator":"all","value":"1","conditions":[]}';

const switchDef: Definition = {
  schema: 3,
  entry: 'route',
  steps: {
    route: {
      type: 'switch',
      cases: [
        { key: 'high', conditions_serialized: TREE, next: 'a' },
        { key: 'low', conditions_serialized: null, next: 'b' },
      ],
      default: 'b',
    },
    a: { type: 'stop' },
    b: { type: 'stop' },
  },
};

function graph(): Graph {
  return makeGraph(switchDef, config);
}

function stepOf(g: Graph, key = 'route'): StepNode {
  const step = g.nodes.find((n) => n.id === key)?.data.step;
  if (!step) {
    throw new Error(`no step ${key}`);
  }
  return step;
}

function handles(g: Graph, source = 'route'): string[] {
  return g.edges.filter((e) => e.source === source).map((e) => e.sourceHandle);
}

describe('case key rules (Definition::assertSwitchStep)', () => {
  it('accepts the server grammar and rejects everything else', () => {
    expect(isValidCaseKey('high')).toBe(true);
    expect(isValidCaseKey('High-1_x')).toBe(true);
    expect(isValidCaseKey('a'.repeat(64))).toBe(true);
    expect(isValidCaseKey('a'.repeat(65))).toBe(false);
    expect(isValidCaseKey('has space')).toBe(false);
    expect(isValidCaseKey('dots.not.allowed')).toBe(false);
    expect(isValidCaseKey('')).toBe(false);
  });

  it('sanitizes typed input to that grammar', () => {
    expect(sanitizeCaseKey('over 9000!')).toBe('over9000');
    expect(sanitizeCaseKey('vip-tier_2')).toBe('vip-tier_2');
    expect(sanitizeCaseKey('***')).toBe('');
    expect(sanitizeCaseKey('a'.repeat(80))).toHaveLength(64);
  });

  it('generates a fresh case_<n> that cannot collide', () => {
    expect(nextCaseKey([])).toBe('case_1');
    expect(nextCaseKey([{ key: 'x' }])).toBe('case_2');
    expect(nextCaseKey([{ key: 'case_2' }])).toBe('case_3');
    expect(nextCaseKey([{ key: 'case_2' }, { key: 'case_3' }])).toBe('case_4');
  });
});

describe('blankStep("switch")', () => {
  it('seeds ONE starter case so a freshly dropped switch is saveable', () => {
    // switchStep.cases declares minItems 1 — an empty switch can never save.
    expect(blankStep('switch')).toEqual({
      type: 'switch',
      cases: [{ key: 'case_1', conditions_serialized: null, next: null }],
      default: null,
    });
    expect(blankCase('case_1')).toEqual({ key: 'case_1', conditions_serialized: null, next: null });
  });

  it('exposes the starter case as a case:<key> handle', () => {
    expect(Object.keys(getStepEdges(blankStep('switch')))).toEqual(['case:case_1', 'default']);
  });
});

describe('addCase', () => {
  it('appends an always-matching, unwired case and leaves the others alone', () => {
    const before = graph();
    const { graph: after, error } = addCase(before, 'route', config.actions);
    expect(error).toBeNull();
    expect(casesOf(stepOf(after))).toEqual([
      { key: 'high', conditions_serialized: TREE, next: 'a' },
      { key: 'low', conditions_serialized: null, next: 'b' },
      { key: 'case_3', conditions_serialized: null, next: null },
    ]);
    // Existing edges untouched; the new case simply has no edge yet.
    expect(handles(after).sort()).toEqual(['case:high', 'case:low', 'default']);
    expect(casesOf(stepOf(before))).toHaveLength(2); // input graph unchanged
  });

  it('refreshes the node summary so the canvas label tracks the cases', () => {
    const { graph: after } = addCase(graph(), 'route', config.actions);
    expect(after.nodes.find((n) => n.id === 'route')?.data.summary).toBe('Cases: high, low, case_3');
  });

  it('refuses a step that is not a switch', () => {
    const before = graph();
    const result = addCase(before, 'a', config.actions);
    expect(result.error).toBe('Not a switch step.');
    expect(result.graph).toBe(before);
  });
});

describe('renameCase', () => {
  it('carries the case conditions AND its edge target to the new key', () => {
    const { graph: after, error } = renameCase(graph(), 'route', 0, 'vip', config.actions);
    expect(error).toBeNull();
    expect(casesOf(stepOf(after))[0]).toEqual({
      key: 'vip',
      conditions_serialized: TREE,
      next: 'a',
    });

    const edge = after.edges.find((e) => e.sourceHandle === 'case:vip');
    expect(edge).toMatchObject({ id: 'route::case:vip', source: 'route', target: 'a', label: 'vip' });
    expect(after.edges.find((e) => e.sourceHandle === 'case:high')).toBeUndefined();
  });

  it('survives a save round-trip with the target intact', () => {
    const { graph: after } = renameCase(graph(), 'route', 0, 'vip', config.actions);
    const def = toDefinition(after);
    expect(def.steps.route.cases).toEqual([
      { key: 'vip', conditions_serialized: TREE, next: 'a' },
      { key: 'low', conditions_serialized: null, next: 'b' },
    ]);
  });

  it('sanitizes the typed key instead of storing something the server rejects', () => {
    const { graph: after } = renameCase(graph(), 'route', 1, 'low value!', config.actions);
    expect(casesOf(stepOf(after))[1].key).toBe('lowvalue');
    expect(handles(after)).toContain('case:lowvalue');
  });

  it('refuses an emptied key, unchanged', () => {
    const before = graph();
    const result = renameCase(before, 'route', 0, '  ', config.actions);
    expect(result.error).toBe('A case key is required.');
    expect(result.graph).toBe(before);
  });

  it('refuses a duplicate key, unchanged', () => {
    const before = graph();
    const result = renameCase(before, 'route', 0, 'low', config.actions);
    expect(result.error).toBe('This case key is already used by this switch: "low"');
    expect(result.graph).toBe(before);
  });

  it('is a silent no-op when the key does not actually change', () => {
    const before = graph();
    const result = renameCase(before, 'route', 0, 'high', config.actions);
    expect(result.error).toBeNull();
    expect(result.graph).toBe(before);
  });

  it('ignores an out-of-range index (a stale click)', () => {
    const before = graph();
    expect(renameCase(before, 'route', 7, 'x', config.actions).graph).toBe(before);
  });
});

describe('removeCase', () => {
  it('drops the case and only its own edge', () => {
    const { graph: after, error } = removeCase(graph(), 'route', 0, config.actions);
    expect(error).toBeNull();
    expect(casesOf(stepOf(after)).map((c) => c.key)).toEqual(['low']);
    expect(handles(after).sort()).toEqual(['case:low', 'default']);
  });

  it('refuses to empty the case list (minItems 1 server-side)', () => {
    const one = removeCase(graph(), 'route', 0, config.actions).graph;
    const result = removeCase(one, 'route', 0, config.actions);
    expect(result.error).toBe('A switch needs at least one case.');
    expect(result.graph).toBe(one);
  });
});

describe('moveCase', () => {
  it('reorders evaluation without disturbing conditions, targets or edges', () => {
    const before = graph();
    const { graph: after, error } = moveCase(before, 'route', 1, -1, config.actions);
    expect(error).toBeNull();
    expect(casesOf(stepOf(after))).toEqual([
      { key: 'low', conditions_serialized: null, next: 'b' },
      { key: 'high', conditions_serialized: TREE, next: 'a' },
    ]);
    expect(after.edges).toEqual(before.edges);

    // …and a save keeps each case pointing where it pointed.
    const def = toDefinition(after);
    expect(def.steps.route.cases?.map((c) => [c.key, c.next])).toEqual([
      ['low', 'b'],
      ['high', 'a'],
    ]);
  });

  it('is a no-op at the boundaries', () => {
    const before = graph();
    expect(moveCase(before, 'route', 0, -1, config.actions).graph).toBe(before);
    expect(moveCase(before, 'route', 1, 1, config.actions).graph).toBe(before);
  });
});

describe('casesOf', () => {
  it('tolerates a step with no (or a non-array) cases key', () => {
    expect(casesOf(null)).toEqual([]);
    expect(casesOf({ type: 'switch' })).toEqual([]);
    expect(casesOf({ type: 'switch', cases: 'nope' } as unknown as StepNode)).toEqual([]);
  });
});
