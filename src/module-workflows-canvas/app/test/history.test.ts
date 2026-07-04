import { describe, expect, it } from 'vitest';
import { canRedo, canUndo, initHistory, push, redo, replacePresent, undo } from '../src/history';

describe('undo/redo history', () => {
  it('starts with nothing to undo or redo', () => {
    const h = initHistory('a');
    expect(canUndo(h)).toBe(false);
    expect(canRedo(h)).toBe(false);
  });

  it('push records the prior present and clears redo', () => {
    let h = initHistory('a');
    h = push(h, 'b');
    expect(h.present).toBe('b');
    expect(canUndo(h)).toBe(true);
    expect(canRedo(h)).toBe(false);
  });

  it('undo then redo round-trips', () => {
    let h = push(push(initHistory('a'), 'b'), 'c');
    h = undo(h);
    expect(h.present).toBe('b');
    expect(canRedo(h)).toBe(true);
    h = redo(h);
    expect(h.present).toBe('c');
  });

  it('a new push after undo drops the redo branch', () => {
    let h = push(push(initHistory('a'), 'b'), 'c');
    h = undo(h); // present b, future [c]
    h = push(h, 'd'); // future cleared
    expect(h.present).toBe('d');
    expect(canRedo(h)).toBe(false);
  });

  it('bounds the undo stack to its limit', () => {
    let h = initHistory(0, 3);
    for (let i = 1; i <= 10; i += 1) {
      h = push(h, i);
    }
    expect(h.past.length).toBeLessThanOrEqual(3);
    expect(h.present).toBe(10);
  });

  it('replacePresent does not create an undo step', () => {
    let h = push(initHistory('a'), 'b');
    const before = h.past.length;
    h = replacePresent(h, 'b-dragging');
    expect(h.present).toBe('b-dragging');
    expect(h.past.length).toBe(before);
  });

  it('undo/redo are no-ops at the ends', () => {
    const h = initHistory('a');
    expect(undo(h)).toBe(h);
    expect(redo(h)).toBe(h);
  });
});
