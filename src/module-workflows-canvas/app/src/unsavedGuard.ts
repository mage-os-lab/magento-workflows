import type { Definition } from './types';

/**
 * Unsaved-changes guard (issue #18). The editor holds every edit in memory —
 * history/undo is session-local by design and the durable state is whatever the
 * admin Save controller last stored — so closing the tab or following an admin
 * menu link silently discarded the work.
 *
 * The dirty test is deliberately content-based rather than "a history push
 * happened": undo back to the mounted state is genuinely not dirty, and a
 * layout-only move IS dirty because the `ui` block is persisted. The seam is
 * pure (definition in, string/boolean out) so it pins under vitest without any
 * DOM gymnastics; the component only owns the two refs and one effect.
 *
 * Note the save loop is itself a navigation (submitSave posts a real hidden
 * form), so the guard must be disarmed BEFORE the submit — hence armUnloadGuard
 * consults a live predicate the caller flips synchronously, instead of being
 * registered/unregistered by a React state transition that lands too late.
 */

/**
 * The message passed to `returnValue`. Every current browser ignores it and
 * shows its own generic wording; it is set because the legacy contract still
 * requires a non-empty value for the dialog to appear at all.
 */
export const UNSAVED_CHANGES_MESSAGE =
  'This workflow has unsaved changes. Leave the page and discard them?';

/**
 * Canonical JSON for a definition: object keys sorted at every level, so the
 * fingerprint tracks CONTENT only. Without this, re-adding an edge that
 * `rebuildStep` appends rather than overwrites would flip key order and report
 * a false edit.
 */
function canonical(value: unknown): unknown {
  if (Array.isArray(value)) {
    return value.map(canonical);
  }
  if (value !== null && typeof value === 'object') {
    const source = value as Record<string, unknown>;
    const sorted: Record<string, unknown> = {};
    for (const key of Object.keys(source).sort()) {
      sorted[key] = canonical(source[key]);
    }
    return sorted;
  }
  return value;
}

/** Stable fingerprint of the definition the canvas would post on save. */
export function fingerprintDefinition(definition: Definition): string {
  return JSON.stringify(canonical(definition));
}

/** Dirty == the current definition differs from the last-saved/mounted one. */
export function isDirty(savedFingerprint: string, currentFingerprint: string): boolean {
  return savedFingerprint !== currentFingerprint;
}

/**
 * The subset of a beforeunload event the guard touches. Keeping it structural
 * (rather than the DOM BeforeUnloadEvent) is what lets the test fire a plain
 * object at the registered listener.
 */
export interface BeforeUnloadEventLike {
  preventDefault(): void;
  returnValue?: unknown;
}

/** The subset of `window` the guard needs. */
export interface UnloadGuardTarget {
  addEventListener(type: 'beforeunload', listener: (event: BeforeUnloadEventLike) => void): void;
  removeEventListener(type: 'beforeunload', listener: (event: BeforeUnloadEventLike) => void): void;
}

/**
 * Prompt only while dirty. Returns the message (rather than just mutating the
 * event) so the decision itself is assertable.
 */
export function guardUnload(
  event: BeforeUnloadEventLike,
  dirty: boolean,
): string | undefined {
  if (!dirty) {
    return undefined;
  }
  event.preventDefault();
  event.returnValue = UNSAVED_CHANGES_MESSAGE;
  return UNSAVED_CHANGES_MESSAGE;
}

/**
 * Register the beforeunload guard, returning its disposer (the shape a React
 * effect cleanup wants). `isDirtyNow` is read at fire time, so arming survives
 * the whole editing session while the PROMPT is armed only while dirty — and a
 * save can disarm it synchronously right before it navigates.
 */
export function armUnloadGuard(
  isDirtyNow: () => boolean,
  target: UnloadGuardTarget = window,
): () => void {
  const handler = (event: BeforeUnloadEventLike): void => {
    guardUnload(event, isDirtyNow());
  };
  target.addEventListener('beforeunload', handler);
  return () => target.removeEventListener('beforeunload', handler);
}
