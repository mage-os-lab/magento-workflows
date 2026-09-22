/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

import { describe, expect, it, vi } from 'vitest';
import { addNode, blankStep, connect, moveNode, positionsOf } from '../src/graphOps';
import { toDefinition } from '../src/mapping';
import {
  UNSAVED_CHANGES_MESSAGE,
  armUnloadGuard,
  fingerprintDefinition,
  guardUnload,
  isDirty,
  type BeforeUnloadEventLike,
  type UnloadGuardTarget,
} from '../src/unsavedGuard';
import { makeConfig, makeGraph } from './support';
import type { Definition, Graph } from '../src/types';

/**
 * The unsaved-changes seam (issue #18). Everything the guard decides is pure:
 * a fingerprint of the definition the canvas would post, a comparison against
 * the last-saved/mounted one, and a beforeunload handler that prompts only
 * while that comparison says dirty. The Editor owns nothing but two refs, so
 * pinning it here needs no React and no real window.
 */

const config = makeConfig({
  knownSchemaVersion: 4,
  actions: { 'order.add_comment': { label: 'Add Comment', group: 'Sales' } },
});

const baseDefinition: Definition = {
  schema: 4,
  entry: 's1',
  steps: {
    s1: { type: 'action', action: 'order.add_comment', config: {}, next: null },
  },
  ui: { nodes: { s1: { x: 120, y: 80 } } },
};

/** Exactly what the Editor fingerprints: the save-shaped definition. */
function fingerprintOf(graph: Graph): string {
  return fingerprintDefinition(
    toDefinition(graph, { positions: positionsOf(graph), existingUi: baseDefinition.ui }),
  );
}

/** A window stand-in that records what the guard registered. */
function fakeWindow(): UnloadGuardTarget & {
  listeners: ((event: BeforeUnloadEventLike) => void)[];
} {
  const listeners: ((event: BeforeUnloadEventLike) => void)[] = [];
  return {
    listeners,
    addEventListener: (_type, listener) => {
      listeners.push(listener);
    },
    removeEventListener: (_type, listener) => {
      const i = listeners.indexOf(listener);
      if (i >= 0) {
        listeners.splice(i, 1);
      }
    },
  };
}

function fireUnload(target: ReturnType<typeof fakeWindow>): BeforeUnloadEventLike & {
  preventDefault: ReturnType<typeof vi.fn>;
} {
  const event = { preventDefault: vi.fn(), returnValue: undefined as unknown };
  for (const listener of [...target.listeners]) {
    listener(event);
  }
  return event;
}

describe('fingerprintDefinition — content, not key order', () => {
  it('is stable across re-serialization of the same graph', () => {
    const graph = makeGraph(baseDefinition, config);
    expect(fingerprintOf(graph)).toBe(fingerprintOf(makeGraph(baseDefinition, config)));
  });

  it('ignores key order so a re-ordered but identical definition is not dirty', () => {
    const a: Definition = { schema: 4, entry: 's1', steps: { s1: { type: 'stop' } } };
    const b = { steps: { s1: { type: 'stop' } }, entry: 's1', schema: 4 } as Definition;
    expect(fingerprintDefinition(a)).toBe(fingerprintDefinition(b));
  });

  it('changes when a step is added, an edge is wired, or a node is moved', () => {
    const clean = makeGraph(baseDefinition, config);
    const base = fingerprintOf(clean);

    const added = addNode(clean, blankStep('stop'), { x: 40, y: 300 }, config, 'end');
    expect(fingerprintOf(added)).not.toBe(base);

    const wired = connect(added, { source: 's1', sourceHandle: 'next', target: 'end' });
    expect(wired.ok).toBe(true);
    expect(fingerprintOf(wired.graph)).not.toBe(fingerprintOf(added));

    // Layout is persisted in `ui`, so a pure move is a real unsaved change.
    expect(fingerprintOf(moveNode(clean, 's1', { x: 999, y: 999 }))).not.toBe(base);
  });
});

describe('dirty state — armed after an edit, disarmed after save', () => {
  it('follows the edit / save / edit-again cycle', () => {
    const mounted = makeGraph(baseDefinition, config);
    let saved = fingerprintOf(mounted);

    // Freshly mounted: clean.
    expect(isDirty(saved, fingerprintOf(mounted))).toBe(false);

    // Edit: dirty.
    const edited = addNode(mounted, blankStep('stop'), { x: 40, y: 300 }, config, 'end');
    expect(isDirty(saved, fingerprintOf(edited))).toBe(true);

    // Save re-baselines against what was posted: clean again.
    saved = fingerprintOf(edited);
    expect(isDirty(saved, fingerprintOf(edited))).toBe(false);

    // Another edit after the save: dirty again.
    const again = moveNode(edited, 'end', { x: 400, y: 400 });
    expect(isDirty(saved, fingerprintOf(again))).toBe(true);
  });

  it('undoing back to the saved state is clean again (content, not a push counter)', () => {
    const mounted = makeGraph(baseDefinition, config);
    const saved = fingerprintOf(mounted);
    const edited = addNode(mounted, blankStep('stop'), { x: 40, y: 300 }, config, 'end');

    expect(isDirty(saved, fingerprintOf(edited))).toBe(true);
    // History undo restores the previous immutable snapshot verbatim.
    expect(isDirty(saved, fingerprintOf(mounted))).toBe(false);
  });
});

describe('armUnloadGuard — prompts only while dirty', () => {
  it('does not prompt while clean', () => {
    const target = fakeWindow();
    armUnloadGuard(() => false, target);

    const event = fireUnload(target);
    expect(event.preventDefault).not.toHaveBeenCalled();
    expect(event.returnValue).toBeUndefined();
  });

  it('prompts while dirty', () => {
    const target = fakeWindow();
    armUnloadGuard(() => true, target);

    const event = fireUnload(target);
    expect(event.preventDefault).toHaveBeenCalled();
    expect(event.returnValue).toBe(UNSAVED_CHANGES_MESSAGE);
  });

  it('reads the predicate at fire time, so a save disarms it without re-registering', () => {
    const target = fakeWindow();
    let dirty = true;
    armUnloadGuard(() => dirty, target);

    expect(fireUnload(target).returnValue).toBe(UNSAVED_CHANGES_MESSAGE);
    dirty = false; // what onSave does synchronously before submitSave
    expect(fireUnload(target).returnValue).toBeUndefined();
  });

  it('the disposer unregisters the listener (unmount)', () => {
    const target = fakeWindow();
    const dispose = armUnloadGuard(() => true, target);
    expect(target.listeners.length).toBe(1);

    dispose();
    expect(target.listeners.length).toBe(0);
    expect(fireUnload(target).preventDefault).not.toHaveBeenCalled();
  });

  it('guardUnload reports its decision', () => {
    const dirtyEvent: BeforeUnloadEventLike = { preventDefault: () => undefined };
    expect(guardUnload(dirtyEvent, true)).toBe(UNSAVED_CHANGES_MESSAGE);
    expect(guardUnload({ preventDefault: () => undefined }, false)).toBeUndefined();
  });
});
