/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

import { describe, expect, it } from 'vitest';
import { preSaveFindings } from '../src/preSave';
import { freePosition } from '../src/graphOps';
import { initMeta } from '../src/workflowMeta';
import { action, makeConfig, makeGraph } from './support';
import type { Definition } from '../src/types';

/**
 * The pre-save review (merchant finding A4/A8): empty required fields,
 * dangling routing paths, and unregistered trigger events must surface on the
 * first Save click instead of saving silently broken workflows. And the
 * click-add placement fix (A1): new nodes must never stack on a fixed point.
 */

const DEF: Definition = {
  schema: 2,
  entry: 'b1',
  steps: {
    b1: { type: 'branch', conditions_serialized: null, on_true: 'a1', on_false: null },
    a1: { type: 'action', action: 'notify.email', config: {}, next: null },
  },
  ui: { nodes: { b1: { x: 100, y: 100 }, a1: { x: 100, y: 260 } } },
};

function config() {
  const cfg = makeConfig({
    triggers: [{ event: 'sales.order.created', entity: 'sales_order', label: 'Order Created', group: 'Sales' }],
  });
  cfg.actions['notify.email'] = { label: 'Send Email', group: 'Notify' };
  cfg.actionsMeta = [
    action({
      code: 'notify.email',
      label: 'Send Email',
      configForm: [{ name: 'template', label: 'Email template', type: 'select', required: true }],
    }),
  ];
  return cfg;
}

describe('preSaveFindings', () => {
  it('flags empty required fields, dangling multi-outcome paths, and unknown trigger events', () => {
    const cfg = config();
    const graph = makeGraph(DEF, cfg);
    const meta = { ...initMeta(cfg.workflow), triggerType: 'event', triggerRef: 'order created' };

    const messages = preSaveFindings(graph, cfg, meta).map((f) => f.message);

    expect(messages.some((m) => m.includes('"order created"') && m.includes('may never run'))).toBe(true);
    expect(messages.some((m) => m.includes('Email template'))).toBe(true);
    // The branch's unwired "no" path is a real routing decision left dangling…
    expect(messages.some((m) => m.includes('"no"') && m.includes('leads nowhere'))).toBe(true);
    // …while the action's absent `next` is a legitimate end-of-flow: no finding.
    expect(messages.some((m) => m.includes('"next"'))).toBe(false);
  });

  it('is silent for a fully-wired workflow with its required fields set', () => {
    const cfg = config();
    const wired: Definition = {
      ...DEF,
      steps: {
        b1: { type: 'branch', conditions_serialized: null, on_true: 'a1', on_false: 'a1' },
        a1: { type: 'action', action: 'notify.email', config: { template: 'order_vip' }, next: null },
      },
    };
    const meta = { ...initMeta(cfg.workflow), triggerType: 'event', triggerRef: 'sales.order.created' };

    expect(preSaveFindings(makeGraph(wired, cfg), cfg, meta)).toEqual([]);
  });

  it('does not flag free-text events when no catalogue exists to check against', () => {
    const cfg = config();
    cfg.triggers = [];
    const meta = { ...initMeta(cfg.workflow), triggerType: 'event', triggerRef: 'custom.event' };
    const findings = preSaveFindings(makeGraph(DEF, cfg), cfg, meta);
    expect(findings.some((f) => f.message.includes('may never run'))).toBe(false);
  });
});

describe('freePosition', () => {
  it('places below the lowest node instead of the old fixed stacking point', () => {
    const graph = makeGraph(DEF, config());
    expect(freePosition(graph)).toEqual({ x: 100, y: 410 });
  });

  it('has a sane default for an empty graph', () => {
    const empty = makeGraph({ schema: 1, entry: null, steps: {} }, config());
    expect(freePosition(empty).y).toBeGreaterThan(0);
  });
});
