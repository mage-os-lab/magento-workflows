import { describe, expect, it } from 'vitest';
import { readMountConfig } from '../src/mountConfig';

/**
 * The data-config channel is the ONLY path from PHP to JS (CSP). This pins the
 * reader against the shape the Mount block emits.
 */
describe('readMountConfig', () => {
  function mountEl(config: unknown): Element {
    const div = document.createElement('div');
    div.setAttribute('data-role', 'mageos-workflows-canvas');
    div.setAttribute('data-config', JSON.stringify(config));
    return div;
  }

  it('parses the full config shape', () => {
    const el = mountEl({
      workflowId: 7,
      executionId: 42,
      knownSchemaVersion: 3,
      grants: { manage: true, dryRun: false },
      endpoints: { executionSteps: '/steps', dryRun: '/dry' },
      formKey: 'abc',
      workflow: { id: 7, name: 'W', entityType: 'sales_order', triggerType: 'event', triggerRef: 'x', definition: null },
      actions: { 'order.add_comment': { label: 'Add Comment', group: 'Sales' } },
    });
    const config = readMountConfig(el);
    expect(config).not.toBeNull();
    expect(config?.workflowId).toBe(7);
    expect(config?.executionId).toBe(42);
    expect(config?.grants.manage).toBe(true);
    expect(config?.grants.dryRun).toBe(false);
    expect(config?.endpoints.executionSteps).toBe('/steps');
    expect(config?.actions['order.add_comment'].label).toBe('Add Comment');
  });

  it('returns null for a missing element or attribute', () => {
    expect(readMountConfig(null)).toBeNull();
    const bare = document.createElement('div');
    expect(readMountConfig(bare)).toBeNull();
  });

  it('returns null on malformed JSON rather than throwing', () => {
    const div = document.createElement('div');
    div.setAttribute('data-config', '{not json');
    expect(readMountConfig(div)).toBeNull();
  });

  it('fills defaults for a minimal config', () => {
    const div = document.createElement('div');
    div.setAttribute('data-config', JSON.stringify({ knownSchemaVersion: 2 }));
    const config = readMountConfig(div);
    expect(config?.knownSchemaVersion).toBe(2);
    expect(config?.workflowId).toBeNull();
    expect(config?.grants.manage).toBe(false);
    expect(config?.actions).toEqual({});
  });
});
