/**
 * Session-local undo/redo (Phase B). A plain past/present/future stack of
 * immutable snapshots — because every graphOps op returns a new Graph, a
 * snapshot is just the current graph reference. No persistence (undo history is
 * per-session by design; the saved definition is the durable state).
 *
 * Generic over the snapshot type so it is trivially unit-testable without a
 * Graph. Bounded so a long editing session cannot grow memory without limit.
 *
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

export interface History<T> {
  past: T[];
  present: T;
  future: T[];
  limit: number;
}

export function initHistory<T>(present: T, limit = 100): History<T> {
  return { past: [], present, future: [], limit };
}

/** Record a new present, pushing the old one onto the undo stack (clears redo). */
export function push<T>(history: History<T>, next: T): History<T> {
  const past = [...history.past, history.present];
  if (past.length > history.limit) {
    past.shift();
  }
  return { ...history, past, present: next, future: [] };
}

export function canUndo<T>(history: History<T>): boolean {
  return history.past.length > 0;
}

export function canRedo<T>(history: History<T>): boolean {
  return history.future.length > 0;
}

export function undo<T>(history: History<T>): History<T> {
  if (!canUndo(history)) {
    return history;
  }
  const past = [...history.past];
  const previous = past.pop() as T;
  return {
    ...history,
    past,
    present: previous,
    future: [history.present, ...history.future],
  };
}

export function redo<T>(history: History<T>): History<T> {
  if (!canRedo(history)) {
    return history;
  }
  const [next, ...rest] = history.future;
  return {
    ...history,
    past: [...history.past, history.present],
    present: next,
    future: rest,
  };
}

/** Replace the present WITHOUT creating an undo step (e.g. a live drag). */
export function replacePresent<T>(history: History<T>, present: T): History<T> {
  return { ...history, present };
}
