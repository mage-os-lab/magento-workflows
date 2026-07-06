import { describe, expect, it } from 'vitest';
import { buildVariablePaths, upstreamStepKeys } from '../src/variablePicker';
import { makeConfig, makeGraph } from './support';
import type { Definition } from '../src/types';

const def: Definition = {
  schema: 3,
  entry: 'a',
  steps: {
    a: { type: 'action', action: 'x', config: {}, next: 'b' },
    b: { type: 'branch', conditions_serialized: null, on_true: 'c', on_false: 'd' },
    c: { type: 'action', action: 'x', config: {}, next: null },
    d: { type: 'stop' },
  },
};

describe('upstreamStepKeys', () => {
  it('returns the reverse-reachable ancestors in node order', () => {
    const g = makeGraph(def);
    expect(upstreamStepKeys(g, 'c')).toEqual(['a', 'b']);
    expect(upstreamStepKeys(g, 'a')).toEqual([]);
  });

  it('is cycle-safe', () => {
    const cyclic: Definition = {
      schema: 3,
      entry: 'a',
      steps: {
        a: { type: 'action', action: 'x', config: {}, next: 'b' },
        b: { type: 'action', action: 'x', config: {}, next: 'a' },
      },
    };
    const g = makeGraph(cyclic);
    expect(() => upstreamStepKeys(g, 'b')).not.toThrow();
    expect(upstreamStepKeys(g, 'b')).toContain('a');
  });
});

describe('buildVariablePaths', () => {
  it('offers trigger context, upstream outputs, and secret NAMES only', () => {
    const config = makeConfig({ secrets: ['stripe_key', 'hmac'] });
    const g = makeGraph(def, config);
    const paths = buildVariablePaths(config, g, 'c');

    const byGroup = (group: string) => paths.filter((p) => p.group === group).map((p) => p.path);
    expect(byGroup('Trigger')).toContain('entity');
    expect(byGroup('Steps')).toEqual(['steps.a.result', 'steps.b.result']);
    expect(byGroup('Secrets')).toEqual(['secret.stripe_key', 'secret.hmac']);
    // Never a secret VALUE — only the name in the path.
    expect(paths.every((p) => !p.path.includes('='))).toBe(true);
  });

  it('works without a graph (document-level context only)', () => {
    const config = makeConfig();
    const paths = buildVariablePaths(config, null, null);
    expect(paths.some((p) => p.group === 'Trigger')).toBe(true);
    expect(paths.some((p) => p.group === 'Steps')).toBe(false);
  });
});
