import { describe, expect, it } from 'vitest';
import { edgeHandles, edgeLabel, getStepEdges } from '../src/edges';
import type { StepNode } from '../src/types';

/**
 * The edge model must mirror PHP Definition::getStepEdges exactly, including
 * the switch `case:<key>` naming and the `default` edge.
 */
describe('getStepEdges — per step type edge-rule table', () => {
  it('action / delay expose only `next`', () => {
    expect(getStepEdges({ type: 'action', action: 'x', next: 's2' })).toEqual({ next: 's2' });
    expect(getStepEdges({ type: 'delay', config: { duration: 'PT1H' }, next: null })).toEqual({
      next: null,
    });
  });

  it('branch exposes on_true / on_false', () => {
    expect(getStepEdges({ type: 'branch', on_true: 'a', on_false: 'b' })).toEqual({
      on_true: 'a',
      on_false: 'b',
    });
    // Legacy shape: on_false absent -> null.
    expect(getStepEdges({ type: 'branch', on_true: 'a' })).toEqual({ on_true: 'a', on_false: null });
  });

  it('wait exposes on_event / on_timeout', () => {
    expect(
      getStepEdges({ type: 'wait', config: { event: 'e' }, on_event: 'a', on_timeout: 'b' }),
    ).toEqual({ on_event: 'a', on_timeout: 'b' });
  });

  it('switch exposes case:<key> per case plus default', () => {
    const step: StepNode = {
      type: 'switch',
      cases: [
        { key: 'high', next: 'a' },
        { key: 'low', next: 'b' },
        { key: 'none' }, // no next -> null
      ],
      default: 'd',
    };
    expect(getStepEdges(step)).toEqual({
      'case:high': 'a',
      'case:low': 'b',
      'case:none': null,
      default: 'd',
    });
  });

  it('switch with no default emits default:null', () => {
    expect(getStepEdges({ type: 'switch', cases: [{ key: 'k', next: 'a' }] })).toEqual({
      'case:k': 'a',
      default: null,
    });
  });

  it('approval exposes on_approved / on_rejected / on_timeout', () => {
    expect(
      getStepEdges({
        type: 'approval',
        config: { title: 't', timeout: 'P3D' },
        on_approved: 'a',
        on_rejected: 'b',
        on_timeout: 'c',
      }),
    ).toEqual({ on_approved: 'a', on_rejected: 'b', on_timeout: 'c' });
    // Legacy/partial shape: absent edges -> null, same convention as branch/wait.
    expect(
      getStepEdges({ type: 'approval', config: { title: 't', timeout: 'P3D' } }),
    ).toEqual({ on_approved: null, on_rejected: null, on_timeout: null });
  });

  it('stop has no edges', () => {
    expect(getStepEdges({ type: 'stop' })).toEqual({});
  });

  it('edgeHandles returns the handle name set', () => {
    expect(edgeHandles({ type: 'branch', on_true: 'a', on_false: 'b' })).toEqual([
      'on_true',
      'on_false',
    ]);
    expect(
      edgeHandles({ type: 'switch', cases: [{ key: 'k' }], default: null }),
    ).toEqual(['case:k', 'default']);
    expect(
      edgeHandles({ type: 'approval', config: { title: 't', timeout: 'P3D' }, on_approved: 'a' }),
    ).toEqual(['on_approved', 'on_rejected', 'on_timeout']);
  });

  it('edgeLabel humanizes handle names', () => {
    expect(edgeLabel('on_true')).toBe('yes');
    expect(edgeLabel('on_false')).toBe('no');
    expect(edgeLabel('on_timeout')).toBe('on timeout');
    expect(edgeLabel('on_approved')).toBe('approved');
    expect(edgeLabel('on_rejected')).toBe('rejected');
    expect(edgeLabel('case:high')).toBe('high');
    expect(edgeLabel('default')).toBe('default');
    expect(edgeLabel('next')).toBe('');
  });
});
