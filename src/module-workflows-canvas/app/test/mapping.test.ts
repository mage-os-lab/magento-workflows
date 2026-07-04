import { readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { isReadOnly, toDefinition, toGraph, buildUi } from '../src/mapping';
import type { Definition, MountConfig } from '../src/types';

// test dir -> app -> module-workflows-canvas -> src -> repo root -> spec/fixtures
const FIXTURES_DIR = resolve(dirname(fileURLToPath(import.meta.url)), '../../../../spec/fixtures');

/** Extract the definition object whether the fixture is raw or an envelope. */
function extractDefinition(fixture: Record<string, unknown>): Definition {
  const def = ('definition' in fixture ? fixture.definition : fixture) as Definition;
  return def;
}

function config(def: Definition, knownSchemaVersion = 3): MountConfig {
  // Register every action code so nothing is marked degraded for the round-trip.
  const actions: MountConfig['actions'] = {};
  for (const step of Object.values(def.steps ?? {})) {
    if (step.type === 'action' && typeof step.action === 'string') {
      actions[step.action] = { label: step.action, group: 'Test' };
    }
  }
  return {
    workflowId: 1,
    executionId: null,
    knownSchemaVersion,
    grants: { manage: true, dryRun: true },
    endpoints: { executionSteps: '/steps', dryRun: '/dryrun' },
    formKey: 'k',
    workflow: null,
    actions,
  };
}

function stripUi(def: Definition): Omit<Definition, 'ui'> {
  const { ui, ...rest } = def;
  void ui;
  return rest;
}

const fixtureFiles = readdirSync(FIXTURES_DIR).filter((f) => f.endsWith('.json'));

describe('mapping — round-trips every spec/fixtures file (modulo ui)', () => {
  it('discovers the fixtures (guards against an empty glob)', () => {
    expect(fixtureFiles.length).toBeGreaterThan(0);
  });

  for (const file of fixtureFiles) {
    it(`round-trips ${file} losslessly`, () => {
      const fixture = JSON.parse(readFileSync(`${FIXTURES_DIR}/${file}`, 'utf8'));
      const def = extractDefinition(fixture);

      const graph = toGraph(def, config(def));
      const rebuilt = toDefinition(graph, { existingUi: def.ui });

      // Every declared field survives; only `ui` is out of scope.
      expect(stripUi(rebuilt)).toEqual(stripUi(def));
    });
  }
});

describe('mapping — version gate', () => {
  it('schema > known forces read-only', () => {
    expect(isReadOnly(4, 3)).toBe(true);
    expect(isReadOnly(3, 3)).toBe(false);
    expect(isReadOnly(1, 3)).toBe(false);
  });

  it('a schema-4 document maps to a read-only graph', () => {
    const def: Definition = {
      schema: 4,
      entry: 's1',
      steps: { s1: { type: 'stop' } },
    };
    const graph = toGraph(def, config(def, 3));
    expect(graph.readOnly).toBe(true);
    // It still renders (nodes present) — read-only view, not a blank page.
    expect(graph.nodes).toHaveLength(1);
  });
});

describe('mapping — ui merge behavior', () => {
  const def: Definition = {
    schema: 1,
    entry: 's1',
    steps: { s1: { type: 'action', action: 'x', next: 's2' }, s2: { type: 'stop' } },
    ui: {
      canvas: { zoom: 0.85 },
      nodes: { s1: { x: 80, y: 40, collapsed: false }, s2: { x: 80, y: 220 } },
    },
  };

  it('writes new positions while preserving non-node ui keys', () => {
    const graph = toGraph(def, config(def));
    const ui = buildUi(graph, { s1: { x: 500, y: 10 }, s2: { x: 500, y: 200 } }, def.ui);
    expect(ui?.canvas).toEqual({ zoom: 0.85 });
    expect(ui?.nodes?.s1).toMatchObject({ x: 500, y: 10, collapsed: false });
    expect(ui?.nodes?.s2).toMatchObject({ x: 500, y: 200 });
  });

  it('introduces no unknown top-level keys into the definition', () => {
    const graph = toGraph(def, config(def));
    const rebuilt = toDefinition(graph, {
      positions: { s1: { x: 500, y: 10 }, s2: { x: 500, y: 200 } },
      existingUi: def.ui,
    });
    expect(Object.keys(rebuilt).sort()).toEqual(['entry', 'schema', 'steps', 'ui']);
  });

  it('a layout-free definition stays ui-free', () => {
    const bare: Definition = {
      schema: 1,
      entry: 's1',
      steps: { s1: { type: 'stop' } },
    };
    const graph = toGraph(bare, config(bare));
    const rebuilt = toDefinition(graph);
    expect(rebuilt.ui).toBeUndefined();
  });
});

describe('mapping — degraded definition', () => {
  it('renders an unregistered action code as an error node', () => {
    const def: Definition = {
      schema: 1,
      entry: 's1',
      steps: {
        s1: { type: 'action', action: 'vendor.removed_action', next: 's2' },
        s2: { type: 'stop' },
      },
    };
    const graph = toGraph(def, config(def)); // config registers only present codes...
    // ...but override: no actions registered -> the code is degraded.
    const graphNoActions = toGraph(def, { ...config(def), actions: {} });
    const node = graphNoActions.nodes.find((n) => n.id === 's1');
    expect(node?.type).toBe('degraded');
    expect(node?.data.degraded).toBe(true);
    // Round-trip is still lossless for a degraded node.
    expect(stripUi(toDefinition(graph))).toEqual(stripUi(def));
  });
});
