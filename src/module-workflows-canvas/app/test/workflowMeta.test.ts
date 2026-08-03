import { describe, expect, it } from 'vitest';
import {
  applyMeta,
  initMeta,
  metaFingerprint,
  metaSaveError,
  toggleWebsite,
} from '../src/workflowMeta';
import { buildSavePayload } from '../src/saveClient';
import { makeConfig } from './support';
import type { Definition } from '../src/types';

/**
 * The settings panel's pure half (canvas-first authoring): edited general
 * fields fold back into the config buildSavePayload reads, so the save POST
 * carries them without any change to the save seam itself.
 */
describe('initMeta', () => {
  it('projects the bootstrap workflow into the editable fields', () => {
    const meta = initMeta(makeConfig().workflow);
    expect(meta).toEqual({
      name: 'W',
      status: 2,
      entityType: 'sales_order',
      triggerType: 'event',
      triggerRef: 'sales.order.created',
      websiteIds: [],
    });
  });

  it('defaults a null workflow to the server blank-workflow shape', () => {
    expect(initMeta(null)).toEqual({
      name: '',
      status: 0,
      entityType: '',
      triggerType: 'event',
      triggerRef: '',
      websiteIds: [],
    });
  });

  it('copies websiteIds so panel edits never mutate the bootstrap', () => {
    const workflow = { ...makeConfig().workflow!, websiteIds: [1] };
    const meta = initMeta(workflow);
    meta.websiteIds.push(2);
    expect(workflow.websiteIds).toEqual([1]);
  });
});

describe('applyMeta', () => {
  const def: Definition = { schema: 3, entry: null, steps: {} };

  it('is identity when nothing was edited', () => {
    const config = makeConfig();
    expect(applyMeta(config, initMeta(config.workflow))).toBe(config);
  });

  it('folds edited fields into the config the save payload reads', () => {
    const config = makeConfig();
    const meta = {
      ...initMeta(config.workflow),
      name: 'Renamed',
      status: 1,
      entityType: 'customer',
      triggerType: 'schedule',
      triggerRef: '0 3 * * *',
      websiteIds: [1, 3],
    };
    const effective = applyMeta(config, meta);
    expect(effective).not.toBe(config);
    // The bootstrap stays untouched (immutability).
    expect(config.workflow?.name).toBe('W');

    const map: Record<string, string[]> = {};
    for (const [k, v] of buildSavePayload(effective, def)) {
      (map[k] ??= []).push(v);
    }
    expect(map.name).toEqual(['Renamed']);
    expect(map.status).toEqual(['1']);
    expect(map.entity_type).toEqual(['customer']);
    expect(map.trigger_type).toEqual(['schedule']);
    expect(map.trigger_ref).toEqual(['0 3 * * *']);
    expect(map['website_ids[]']).toEqual(['1', '3']);
    // Fields the panel does not edit round-trip unchanged.
    expect(map.loop_guard_depth).toEqual(['1']);
  });

  it('leaves a workflow-less config alone', () => {
    const config = makeConfig({ workflow: null });
    expect(applyMeta(config, initMeta(null))).toBe(config);
  });
});

describe('metaFingerprint — arms the unsaved-changes guard', () => {
  it('is stable for equal metas and differs on any edited field', () => {
    const base = initMeta(makeConfig().workflow);
    expect(metaFingerprint({ ...base })).toBe(metaFingerprint(base));
    expect(metaFingerprint({ ...base, name: 'X' })).not.toBe(metaFingerprint(base));
    expect(metaFingerprint({ ...base, websiteIds: [1] })).not.toBe(metaFingerprint(base));
  });
});

describe('metaSaveError — the client-side name gate', () => {
  it('blocks a nameless (or whitespace) workflow and passes a named one', () => {
    const base = initMeta(null);
    expect(metaSaveError(base)).not.toBeNull();
    expect(metaSaveError({ ...base, name: '   ' })).not.toBeNull();
    expect(metaSaveError({ ...base, name: 'My Workflow' })).toBeNull();
  });
});

describe('toggleWebsite', () => {
  it('adds, removes, dedupes and keeps a sorted stable order', () => {
    expect(toggleWebsite([], 2, true)).toEqual([2]);
    expect(toggleWebsite([2], 1, true)).toEqual([1, 2]);
    expect(toggleWebsite([1, 2], 1, true)).toEqual([1, 2]);
    expect(toggleWebsite([1, 2], 1, false)).toEqual([2]);
    expect(toggleWebsite([2], 1, false)).toEqual([2]);
  });
});
