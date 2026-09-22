/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

import { t } from './i18n';
import type { MountConfig, WorkflowMeta } from './types';

/**
 * The workflow-settings panel's pure half (canvas-first authoring). The panel
 * edits the GENERAL workflow fields the classic form otherwise owns — name,
 * status, entity type, trigger, website scope — held as a small editable state
 * beside the graph history. On save/validate the edited fields are folded back
 * into the bootstrap config (applyMeta), so buildSavePayload keeps its
 * signature and keeps round-tripping whatever the config's workflow says; the
 * server stays the authority on every value.
 */

/** The fields the settings panel edits (a subset of WorkflowMeta). */
export interface EditableMeta {
  name: string;
  status: number;
  entityType: string;
  triggerType: string;
  triggerRef: string;
  websiteIds: number[];
}

/** Initial panel state from the bootstrap (blank-workflow defaults included). */
export function initMeta(workflow: WorkflowMeta | null): EditableMeta {
  return {
    name: workflow?.name ?? '',
    status: workflow?.status ?? 0,
    entityType: workflow?.entityType ?? '',
    triggerType: workflow?.triggerType ?? 'event',
    triggerRef: workflow?.triggerRef ?? '',
    websiteIds: [...(workflow?.websiteIds ?? [])],
  };
}

/**
 * Fold the edited meta into the config handed to saveClient/validateClient.
 * Identity when nothing differs, so an untouched panel changes no object the
 * memoized consumers compare.
 */
export function applyMeta(config: MountConfig, meta: EditableMeta): MountConfig {
  const w = config.workflow;
  if (w === null || metaOf(w) === metaFingerprint(meta)) {
    return config;
  }
  return {
    ...config,
    workflow: {
      ...w,
      name: meta.name,
      status: meta.status,
      entityType: meta.entityType,
      triggerType: meta.triggerType,
      triggerRef: meta.triggerRef,
      websiteIds: [...meta.websiteIds],
    },
  };
}

/**
 * Stable fingerprint of the edited fields, for the unsaved-changes guard (the
 * same role fingerprintDefinition plays for the graph).
 */
export function metaFingerprint(meta: EditableMeta): string {
  return JSON.stringify([
    meta.name,
    meta.status,
    meta.entityType,
    meta.triggerType,
    meta.triggerRef,
    meta.websiteIds,
  ]);
}

function metaOf(workflow: WorkflowMeta): string {
  return metaFingerprint(initMeta(workflow));
}

/**
 * Client-side save gate: a workflow cannot be saved nameless (the server
 * refuses it anyway — this only surfaces the refusal before the navigation).
 * Returns the message to show, or null when saving may proceed.
 */
export function metaSaveError(meta: EditableMeta): string | null {
  return meta.name.trim() === '' ? t('A name is required before saving.') : null;
}

/** Toggle one website id in the scope list (kept sorted for a stable payload). */
export function toggleWebsite(ids: readonly number[], id: number, checked: boolean): number[] {
  const rest = ids.filter((x) => x !== id);
  if (checked) {
    rest.push(id);
    rest.sort((a, b) => a - b);
  }
  return rest;
}
