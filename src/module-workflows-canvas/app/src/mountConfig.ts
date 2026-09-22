/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

import type { ConfigFieldOption, MountConfig } from './types';

/**
 * Reads the bootstrap config from the mount element's data-config attribute —
 * the ONLY channel from PHP to JS (CSP: no inline script). Pure and DOM-light
 * so it is unit-testable in jsdom.
 */
export function readMountConfig(el: Element | null): MountConfig | null {
  if (!el) {
    return null;
  }
  const raw = el.getAttribute('data-config');
  if (!raw) {
    return null;
  }
  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
  } catch {
    return null;
  }
  if (!parsed || typeof parsed !== 'object') {
    return null;
  }

  const c = parsed as Partial<MountConfig>;
  return {
    workflowId: c.workflowId ?? null,
    executionId: c.executionId ?? null,
    knownSchemaVersion: Number(c.knownSchemaVersion ?? 0),
    grants: {
      manage: Boolean(c.grants?.manage),
      dryRun: Boolean(c.grants?.dryRun),
    },
    endpoints: {
      executionSteps: String(c.endpoints?.executionSteps ?? ''),
      dryRun: String(c.endpoints?.dryRun ?? ''),
      validate: String(c.endpoints?.validate ?? ''),
      options: String(c.endpoints?.options ?? ''),
      conditionMeta: String(c.endpoints?.conditionMeta ?? ''),
      conditions: String(c.endpoints?.conditions ?? ''),
      save: String(c.endpoints?.save ?? ''),
    },
    formKey: String(c.formKey ?? ''),
    workflow: c.workflow ?? null,
    workflowOptions: {
      entityTypes: optionList(c.workflowOptions?.entityTypes),
      triggerTypes: optionList(c.workflowOptions?.triggerTypes),
      statuses: optionList(c.workflowOptions?.statuses),
      websites: optionList(c.workflowOptions?.websites),
    },
    i18n:
      c.i18n && typeof c.i18n === 'object' && !Array.isArray(c.i18n)
        ? (c.i18n as Record<string, string>)
        : {},
    actions: c.actions ?? {},
    actionsMeta: Array.isArray(c.actionsMeta) ? c.actionsMeta : [],
    triggers: Array.isArray(c.triggers) ? c.triggers : [],
    secrets: Array.isArray(c.secrets) ? c.secrets : [],
    approvalsAvailable: Boolean(c.approvalsAvailable),
  };
}

function optionList(value: unknown): ConfigFieldOption[] {
  return Array.isArray(value) ? (value as ConfigFieldOption[]) : [];
}

export const MOUNT_SELECTOR = '[data-role="mageos-workflows-canvas"]';
