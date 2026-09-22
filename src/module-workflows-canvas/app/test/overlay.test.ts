/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

import { describe, expect, it } from 'vitest';
import {
  buildDryRunOverlay,
  buildExecutionOverlay,
  durationMs,
  formatDuration,
  type DryRunTraceRow,
  type ExecutionStepRow,
} from '../src/overlay';

describe('execution overlay', () => {
  const rows: ExecutionStepRow[] = [
    {
      step_key: 's1',
      status: 'complete',
      started_at: '2026-07-04 10:00:00',
      finished_at: '2026-07-04 10:00:02',
      edge_taken: 'on_true',
      error_summary: null,
    },
    {
      step_key: 's2',
      status: 'failed',
      started_at: '2026-07-04 10:00:02',
      finished_at: '2026-07-04 10:00:03',
      edge_taken: null,
      error_summary: 'boom ***stripe***',
    },
  ];

  it('maps status, duration, error and taken edges', () => {
    const o = buildExecutionOverlay(rows);
    expect(o.nodeStatus.s1).toBe('complete');
    expect(o.nodeStatus.s2).toBe('failed');
    expect(o.durationMs.s1).toBe(2000);
    expect(o.nodeError.s2).toBe('boom ***stripe***');
    // Taken edge id mirrors GraphEdge id = `${source}::${edgeName}`.
    expect(o.takenEdgeIds.has('s1::on_true')).toBe(true);
    expect(o.takenEdgeIds.size).toBe(1);
  });
});

describe('dry-run overlay', () => {
  it('unions taken edges across both wait paths', () => {
    const rows: DryRunTraceRow[] = [
      { step_key: 'w', type: 'wait', status: 'would_run', path_ids: ['p1', 'p2'], would: 'wait', edge_taken: 'on_event' },
      { step_key: 'w2', type: 'action', status: 'would_run', path_ids: ['p2'], would: 'x', edge_taken: 'next' },
    ];
    const o = buildDryRunOverlay(rows);
    expect(o.nodeStatus.w).toBe('would_run');
    expect(o.takenEdgeIds.has('w::on_event')).toBe(true);
    expect(o.takenEdgeIds.has('w2::next')).toBe(true);
  });
});

describe('duration helpers', () => {
  it('derives ms between datetimes', () => {
    expect(durationMs('2026-07-04 10:00:00', '2026-07-04 10:00:05')).toBe(5000);
    expect(durationMs(null, '2026-07-04 10:00:05')).toBeNull();
    expect(durationMs('bad', 'worse')).toBeNull();
  });

  it('formats compactly', () => {
    expect(formatDuration(340)).toBe('340ms');
    expect(formatDuration(1200)).toBe('1.2s');
    expect(formatDuration(45000)).toBe('45s');
    expect(formatDuration(125000)).toBe('2m 5s');
    expect(formatDuration(null)).toBe('');
  });
});
