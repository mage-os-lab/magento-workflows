import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { toDefinition, toGraph } from '../src/mapping';
import { normalizeConfigForm } from '../src/configPanel';
import { deleteNode } from '../src/graphOps';
import { actionByCode } from '../src/palette';
import type { Definition, MountConfig } from '../src/types';

/**
 * Degraded install (docs/discovery/canvas.md §9 open question 1): an action code
 * present in a definition but no longer registered (its module removed). It must
 * render as an error node — deletable, not configurable — and still round-trip
 * losslessly (so it can be removed and saved).
 */
const FIXTURE = resolve(
  dirname(fileURLToPath(import.meta.url)),
  'fixtures/degraded-unregistered-action.json',
);

function config(actions: MountConfig['actions']): MountConfig {
  return {
    workflowId: 1,
    executionId: null,
    knownSchemaVersion: 3,
    grants: { manage: true, dryRun: true },
    endpoints: { executionSteps: '/steps', dryRun: '/dry', validate: '/validate', options: '/options', conditionMeta: '/conditionMeta', conditions: '/conditions', save: '/save' },
    formKey: 'k',
    workflow: null,
    actions,
    actionsMeta: [],
    triggers: [],
    secrets: [],
    workflowOptions: { entityTypes: [], triggerTypes: [], statuses: [], websites: [] },
    approvalsAvailable: false,
  };
}

describe('degraded definition (unregistered action code)', () => {
  const def = JSON.parse(readFileSync(FIXTURE, 'utf8')) as Definition;

  it('renders the unregistered action as a degraded error node', () => {
    // The action registry does NOT contain vendor_removed.deprecated_action.
    const graph = toGraph(def, config({ 'order.add_comment': { label: 'x', group: 'Sales' } }));
    const node = graph.nodes.find((n) => n.id === 's1');
    expect(node?.type).toBe('degraded');
    expect(node?.data.degraded).toBe(true);
    // The stop node is unaffected.
    expect(graph.nodes.find((n) => n.id === 's2')?.type).toBe('stop');
  });

  it('round-trips losslessly so the node can be deleted and saved', () => {
    const graph = toGraph(def, config({}));
    const rebuilt = toDefinition(graph);
    const { ui: _a, ...rebuiltNoUi } = rebuilt;
    const { ui: _b, ...defNoUi } = def;
    void _a;
    void _b;
    expect(rebuiltNoUi).toEqual(defNoUi);
  });
});

describe('degraded node UI contract — "deletable, not configurable"', () => {
  const def = JSON.parse(readFileSync(FIXTURE, 'utf8')) as Definition;

  it('is NOT configurable: no palette metadata exists, so the config panel derives zero fields', () => {
    // The pure seam ConfigPanel builds its field list from: an unregistered
    // action code resolves no PaletteAction, and normalizeConfigForm maps
    // "no action" to an empty field list — nothing to configure.
    const cfg = config({});
    expect(actionByCode(cfg, 'vendor_removed.deprecated_action')).toBeUndefined();
    expect(normalizeConfigForm(actionByCode(cfg, 'vendor_removed.deprecated_action'))).toEqual([]);
  });

  it('IS deletable: deleteNode removes the degraded node + its edges and the save omits it', () => {
    const graph = toGraph(def, config({}));
    expect(graph.nodes.find((n) => n.id === 's1')?.data.degraded).toBe(true);

    const afterDelete = deleteNode(graph, 's1');
    expect(afterDelete.nodes.find((n) => n.id === 's1')).toBeUndefined();
    expect(afterDelete.edges.filter((e) => e.source === 's1' || e.target === 's1')).toEqual([]);
    // s1 was the entry; deletion re-assigns it so the document stays saveable.
    expect(afterDelete.entry).toBe('s2');

    const rebuilt = toDefinition(afterDelete);
    expect(rebuilt.steps.s1).toBeUndefined();
    expect(rebuilt.entry).toBe('s2');
    expect(JSON.stringify(rebuilt)).not.toContain('vendor_removed.deprecated_action');
  });
});
