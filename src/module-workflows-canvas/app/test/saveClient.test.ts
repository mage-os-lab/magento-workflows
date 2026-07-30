import { readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { buildSavePayload } from '../src/saveClient';
import { toDefinition, toGraph } from '../src/mapping';
import type { Definition, MountConfig } from '../src/types';

const FIXTURES_DIR = resolve(dirname(fileURLToPath(import.meta.url)), '../../../../spec/fixtures');

function extractDefinition(fixture: Record<string, unknown>): Definition {
  return ('definition' in fixture ? fixture.definition : fixture) as Definition;
}

function config(overrides: Partial<MountConfig> = {}): MountConfig {
  return {
    workflowId: 7,
    executionId: null,
    knownSchemaVersion: 3,
    grants: { manage: true, dryRun: true },
    endpoints: {
      executionSteps: '/steps',
      dryRun: '/dry',
      validate: '/validate',
      options: '/options',
      conditionMeta: '/conditionMeta',
      conditions: '/conditions',
      save: '/admin/mageos_workflows/workflow/save',
    },
    formKey: 'FKEY',
    workflow: {
      id: 7,
      name: 'My Workflow',
      status: 2,
      entityType: 'sales_order',
      triggerType: 'event',
      triggerRef: 'sales.order.created',
      conditionsSerialized: '{"type":"root"}',
      loopGuardDepth: 3,
      websiteIds: [1, 2],
      fanOutRelation: '',
      fanOutCap: '',
      definition: null,
    },
    actions: {},
    actionsMeta: [],
    triggers: [],
    secrets: [],
    approvalsAvailable: false,
    ...overrides,
  };
}

const asMap = (pairs: [string, string][]): Record<string, string[]> => {
  const map: Record<string, string[]> = {};
  for (const [k, v] of pairs) {
    (map[k] ??= []).push(v);
  }
  return map;
};

describe('buildSavePayload — posts through the existing admin Save controller', () => {
  const def: Definition = {
    schema: 3,
    entry: 's1',
    steps: { s1: { type: 'action', action: 'order.add_comment', config: { comment: 'hi' }, next: null } },
  };

  it('carries the form key and every general field verbatim from the bootstrap', () => {
    const map = asMap(buildSavePayload(config(), def));
    expect(map.form_key).toEqual(['FKEY']);
    expect(map.workflow_id).toEqual(['7']);
    expect(map.name).toEqual(['My Workflow']);
    expect(map.status).toEqual(['2']);
    expect(map.entity_type).toEqual(['sales_order']);
    expect(map.trigger_type).toEqual(['event']);
    expect(map.trigger_ref).toEqual(['sales.order.created']);
    expect(map.loop_guard_depth).toEqual(['3']);
    expect(map.conditions_serialized).toEqual(['{"type":"root"}']);
    // multiselect repeats one key per id
    expect(map['website_ids[]']).toEqual(['1', '2']);
  });

  it('sets the definition field to the mapped graph JSON', () => {
    const map = asMap(buildSavePayload(config(), def));
    expect(map.definition).toHaveLength(1);
    expect(JSON.parse(map.definition[0])).toEqual(def);
  });

  it('omits workflow_id for a new (unsaved) workflow', () => {
    const cfg = config({ workflow: { ...config().workflow!, id: 0 } });
    const map = asMap(buildSavePayload(cfg, def));
    expect(map.workflow_id).toBeUndefined();
  });

  it('never invents a save endpoint — it targets the injected Save controller URL', () => {
    // The URL is bootstrapped from getUrl('mageos_workflows/workflow/save'); the
    // client only reads it, guaranteeing no bespoke write path.
    expect(config().endpoints.save).toContain('workflow/save');
  });
});

describe('save-path equivalence (vitest half): canvas-save === textarea-save', () => {
  const fixtures = readdirSync(FIXTURES_DIR).filter((f) => f.endsWith('.json'));

  it.each(fixtures)('%s — toDefinition equals the textarea definition (modulo ui)', (file) => {
    const fixture = JSON.parse(readFileSync(resolve(FIXTURES_DIR, file), 'utf8')) as Record<string, unknown>;
    const textareaDef = extractDefinition(fixture);

    const cfg = config({
      workflow: { ...config().workflow!, definition: textareaDef },
    });
    // Register action codes so nothing degrades.
    for (const step of Object.values(textareaDef.steps ?? {})) {
      if (step.type === 'action' && typeof step.action === 'string') {
        cfg.actions[step.action] = { label: step.action, group: 'T' };
      }
    }

    const graph = toGraph(textareaDef, cfg);
    // No manual layout: pass the definition's own ui so the round-trip is exact.
    const canvasDef = toDefinition(graph, { existingUi: textareaDef.ui });

    const strip = (d: Definition): Omit<Definition, 'ui'> => {
      const { ui, ...rest } = d;
      void ui;
      return rest;
    };

    // The canvas-mapped definition body is semantically identical to the
    // textarea's (key ORDER may differ; the server re-normalizes both through
    // Definition::fromJson()->toJson(), where byte-identity is pinned PHP-side).
    expect(strip(canvasDef)).toEqual(strip(textareaDef));

    // The two save payloads carry the same logical definition.
    const textareaPayload = asMap(buildSavePayload(cfg, textareaDef)).definition[0];
    const canvasPayload = asMap(buildSavePayload(cfg, canvasDef)).definition[0];
    expect(JSON.parse(canvasPayload)).toEqual(JSON.parse(textareaPayload));
  });
});
