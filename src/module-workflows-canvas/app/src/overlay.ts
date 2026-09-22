/**
 * Pure overlay math: given execution step rows or a dry-run trace, compute the
 * node tints, taken-edge ids, and per-step durations the viewer paints on the
 * graph. No React, no DOM — unit-testable.
 *
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

export interface ExecutionStepRow {
  step_key: string;
  status: string;
  started_at: string | null;
  finished_at: string | null;
  edge_taken: string | null;
  error_summary: string | null;
}

export interface DryRunTraceRow {
  step_key: string;
  type: string;
  status: string;
  path_ids: string[];
  would: string | null;
  edge_taken: string | null;
}

export interface Overlay {
  /** step key -> status tint. */
  nodeStatus: Record<string, string>;
  /** step key -> redacted error summary (execution only). */
  nodeError: Record<string, string>;
  /** step key -> duration in ms (execution only; null when not derivable). */
  durationMs: Record<string, number | null>;
  /** GraphEdge ids (`${source}::${edgeName}`) on the taken path. */
  takenEdgeIds: Set<string>;
}

const EMPTY: Overlay = {
  nodeStatus: {},
  nodeError: {},
  durationMs: {},
  takenEdgeIds: new Set(),
};

export function emptyOverlay(): Overlay {
  return { ...EMPTY, takenEdgeIds: new Set() };
}

export function buildExecutionOverlay(rows: ExecutionStepRow[]): Overlay {
  const overlay = emptyOverlay();
  for (const row of rows) {
    overlay.nodeStatus[row.step_key] = row.status;
    if (row.error_summary) {
      overlay.nodeError[row.step_key] = row.error_summary;
    }
    overlay.durationMs[row.step_key] = durationMs(row.started_at, row.finished_at);
    if (row.edge_taken) {
      overlay.takenEdgeIds.add(`${row.step_key}::${row.edge_taken}`);
    }
  }
  return overlay;
}

/**
 * Dry-run overlay. Unlike an execution, dry-run explores BOTH paths of a wait
 * (parallel tinted trees) and can fan out — so multiple edges out of one step
 * may be taken. `path_ids` distinguishes contributing paths; the tint is the
 * union.
 */
export function buildDryRunOverlay(rows: DryRunTraceRow[]): Overlay {
  const overlay = emptyOverlay();
  for (const row of rows) {
    overlay.nodeStatus[row.step_key] = row.status;
    if (row.edge_taken) {
      overlay.takenEdgeIds.add(`${row.step_key}::${row.edge_taken}`);
    }
  }
  return overlay;
}

/** Milliseconds between two MySQL datetime strings, or null. */
export function durationMs(startedAt: string | null, finishedAt: string | null): number | null {
  if (!startedAt || !finishedAt) {
    return null;
  }
  const start = Date.parse(startedAt.replace(' ', 'T') + 'Z');
  const end = Date.parse(finishedAt.replace(' ', 'T') + 'Z');
  if (Number.isNaN(start) || Number.isNaN(end)) {
    return null;
  }
  return Math.max(0, end - start);
}

/** "1.2s" / "340ms" / "2m 3s" — compact duration for the node badge. */
export function formatDuration(ms: number | null): string {
  if (ms === null) {
    return '';
  }
  if (ms < 1000) {
    return `${ms}ms`;
  }
  const s = ms / 1000;
  if (s < 60) {
    return `${s.toFixed(s < 10 ? 1 : 0)}s`;
  }
  const m = Math.floor(s / 60);
  const rem = Math.round(s % 60);
  return `${m}m ${rem}s`;
}
