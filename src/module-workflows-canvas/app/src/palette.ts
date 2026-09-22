/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

import { t } from './i18n';
import type { MountConfig, PaletteAction, StepType, TriggerMeta } from './types';

/**
 * The editor palette (Phase B). Two sections:
 *   - flow primitives (delay/branch/wait/switch/stop, always available, plus
 *     approval when the optional addon is installed — see below);
 *   - actions grouped by their metadata group (Sales/Customer/…).
 * Plus the trigger reference section for context.
 *
 * IMPORTANT (security): the action list is ACL-filtered SERVER-SIDE by the
 * metadata provider before it reaches the bootstrap — an action whose ACL the
 * current admin lacks never appears. That is a display convenience only; the
 * save path re-authorizes every action code (authorizeActionCodes), so hiding
 * here is never the gate. This module only re-shapes the already-filtered list.
 *
 * The `approval` step type is spec-level and always understood by core (it
 * renders, dry-runs, and views regardless of the addon), but ACTUALLY SAVING
 * one requires the optional MageOS_WorkflowsApprovals addon — the server
 * rejects it with APPROVAL_MODULE_MISSING otherwise (ApprovalCheck.php). The
 * palette entry is offered only when `config.approvalsAvailable` is true
 * (bootstrapped from the same nullable ApprovalTaskManagerInterface seam the
 * server-side check uses), so authors are never invited to drop a node that
 * cannot save — matching docs/discovery/approval-gate.md §7 ("renders only
 * when both optional packages are present").
 */

export interface PaletteFlowItem {
  kind: 'flow';
  type: StepType;
  label: string;
}

export interface PaletteActionItem {
  kind: 'action';
  type: 'action';
  code: string;
  label: string;
  action: PaletteAction;
}

export type PaletteItem = PaletteFlowItem | PaletteActionItem;

export interface PaletteGroup {
  label: string;
  items: PaletteItem[];
}

/**
 * The flow-primitive items. Built per call (not a module constant) so the
 * labels resolve through t() after the phrase map is installed at mount.
 */
function flowItems(): PaletteFlowItem[] {
  return [
    { kind: 'flow', type: 'delay', label: t('Delay') },
    { kind: 'flow', type: 'branch', label: t('Branch (if/else)') },
    { kind: 'flow', type: 'wait', label: t('Wait for event') },
    { kind: 'flow', type: 'switch', label: t('Switch (multi-way)') },
  ];
}

/**
 * Build the grouped palette from the bootstrap. The flow group is first; action
 * groups follow in a stable, case-insensitive alphabetical order; within a
 * group, actions are sorted by label. Empty groups are dropped.
 */
export function buildPalette(config: MountConfig): PaletteGroup[] {
  const flow: PaletteFlowItem[] = flowItems();
  if (config.approvalsAvailable) {
    flow.push({ kind: 'flow', type: 'approval', label: t('Approval gate') });
  }
  // The always-last sink item; approval (when available) sits just before it.
  flow.push({ kind: 'flow', type: 'stop', label: t('Stop') });
  const groups: PaletteGroup[] = [{ label: t('Flow'), items: flow }];

  const byGroup = new Map<string, PaletteActionItem[]>();
  for (const action of config.actionsMeta) {
    const item: PaletteActionItem = {
      kind: 'action',
      type: 'action',
      code: action.code,
      label: action.label,
      action,
    };
    const list = byGroup.get(action.group) ?? [];
    list.push(item);
    byGroup.set(action.group, list);
  }

  const groupNames = [...byGroup.keys()].sort((a, b) =>
    a.toLowerCase().localeCompare(b.toLowerCase()),
  );
  for (const name of groupNames) {
    const items = (byGroup.get(name) ?? []).sort((a, b) =>
      a.label.toLowerCase().localeCompare(b.label.toLowerCase()),
    );
    groups.push({ label: name || t('Other'), items });
  }

  return groups;
}

/** Triggers grouped for the reference section (grouped by group, null last). */
export function groupTriggers(triggers: TriggerMeta[]): { label: string; triggers: TriggerMeta[] }[] {
  const byGroup = new Map<string, TriggerMeta[]>();
  for (const t of triggers) {
    const key = t.group ?? '';
    const list = byGroup.get(key) ?? [];
    list.push(t);
    byGroup.set(key, list);
  }
  return [...byGroup.entries()]
    .sort((a, b) => a[0].localeCompare(b[0]))
    .map(([label, list]) => ({ label: label || t('Other'), triggers: list }));
}

/** Look up a palette action's full metadata by code (for config-panel gen). */
export function actionByCode(config: MountConfig, code: string): PaletteAction | undefined {
  return config.actionsMeta.find((a) => a.code === code);
}
