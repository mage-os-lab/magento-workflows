import { describe, expect, it } from 'vitest';
import {
  buildTriggerCard,
  summarizeConditions,
  triggerPosition,
  TRIGGER_NODE_ID,
} from '../src/triggerNode';
import { nodeSummary } from '../src/nodeSummary';
import { makeConfig, makeGraph } from './support';
import type { TriggerMeta, WorkflowOptions } from '../src/types';

const TRIGGERS: TriggerMeta[] = [
  { event: 'sales.order.created', entity: 'sales_order', label: 'Order Created', group: 'Sales' },
];
const OPTIONS: WorkflowOptions = {
  entityTypes: [{ value: 'sales_order', label: 'Orders' }],
  triggerTypes: [],
  statuses: [],
  websites: [],
};

const fields = (over: Partial<Parameters<typeof buildTriggerCard>[0]> = {}) => ({
  triggerType: 'event',
  triggerRef: 'sales.order.created',
  entityType: 'sales_order',
  conditionsSerialized: null,
  ...over,
});

describe('buildTriggerCard', () => {
  it('renders a catalogued event via its label and the entity via its label', () => {
    const card = buildTriggerCard(fields(), TRIGGERS, OPTIONS);
    expect(card.title).toBe('When: Order Created');
    expect(card.entity).toBe('Orders');
    expect(card.conditions).toBeNull();
  });

  it('falls back to the raw event name and raw entity code when uncatalogued', () => {
    const card = buildTriggerCard(
      fields({ triggerRef: 'custom.event', entityType: 'quote' }),
      TRIGGERS,
      OPTIONS,
    );
    expect(card.title).toBe('When: custom.event');
    expect(card.entity).toBe('quote');
  });

  it('renders schedule and manual trigger types distinctly', () => {
    expect(buildTriggerCard(fields({ triggerType: 'schedule', triggerRef: '0 3 * * *' }), TRIGGERS, OPTIONS).title)
      .toBe('Schedule: 0 3 * * *');
    expect(buildTriggerCard(fields({ triggerType: 'manual', triggerRef: '' }), TRIGGERS, OPTIONS).title)
      .toBe('Manual run');
  });

  it('marks an unset event/entity honestly', () => {
    const card = buildTriggerCard(
      fields({ triggerRef: '', entityType: '' }),
      TRIGGERS,
      OPTIONS,
    );
    expect(card.title).toBe('When: (not set)');
    expect(card.entity).toBe('');
  });
});

describe('summarizeConditions', () => {
  const leaf = (attribute: string, operator: string, value: unknown) => ({
    type: 'X\\Leaf',
    attribute,
    operator,
    value,
  });

  it('is null for no/blank/empty trees', () => {
    expect(summarizeConditions(null)).toBeNull();
    expect(summarizeConditions('')).toBeNull();
    expect(
      summarizeConditions(JSON.stringify({ type: 'X\\Combine', aggregator: 'all', conditions: [] })),
    ).toBeNull();
  });

  it('renders a single leaf with an operator glyph', () => {
    const tree = JSON.stringify({
      type: 'X\\Combine',
      aggregator: 'all',
      conditions: [leaf('grand_total', '>=', '500')],
    });
    expect(summarizeConditions(tree)).toBe('grand_total ≥ 500');
  });

  it('counts extra leaves and spells out an ANY root', () => {
    const tree = JSON.stringify({
      type: 'X\\Combine',
      aggregator: 'any',
      conditions: [
        leaf('grand_total', '>=', '500'),
        leaf('customer_group_id', '==', '2'),
        { type: 'X\\Combine', aggregator: 'all', conditions: [leaf('status', '!=', 'holded')] },
      ],
    });
    expect(summarizeConditions(tree)).toBe('grand_total ≥ 500 +2 more (ANY)');
  });

  it('degrades to a marker for unparseable JSON instead of throwing', () => {
    expect(summarizeConditions('{nope')).toBe('(unreadable condition tree)');
  });
});

describe('node face summaries (branch / switch / wait)', () => {
  const config = makeConfig();

  it('a branch face shows its condition, not the word "Condition"', () => {
    const tree = JSON.stringify({
      type: 'X\\Combine',
      aggregator: 'all',
      conditions: [{ type: 'X\\Leaf', attribute: 'grand_total', operator: '>', value: '100' }],
    });
    expect(
      nodeSummary(
        { type: 'branch', conditions_serialized: tree, on_true: null, on_false: null },
        config.actions,
      ),
    ).toBe('If grand_total > 100');
    expect(
      nodeSummary({ type: 'branch', on_true: null, on_false: null }, config.actions),
    ).toBe('If — always');
  });

  it('a switch face lists its case keys', () => {
    expect(
      nodeSummary(
        {
          type: 'switch',
          cases: [
            { key: 'vip', conditions_serialized: null, next: null },
            { key: 'standard', conditions_serialized: null, next: null },
          ],
          default: null,
        },
        config.actions,
      ),
    ).toBe('Cases: vip, standard');
  });

  it('a wait face includes the timeout', () => {
    expect(
      nodeSummary(
        { type: 'wait', config: { event: 'x.y', timeout: 'P2D' }, on_event: null, on_timeout: null },
        config.actions,
      ),
    ).toBe('Wait for "x.y" · timeout 2 days');
  });
});

describe('triggerPosition', () => {
  it('sits above the entry step, and at the origin for an empty graph', () => {
    const graph = makeGraph({
      schema: 1,
      entry: 's1',
      steps: { s1: { type: 'stop' } },
      ui: { nodes: { s1: { x: 300, y: 200 } } },
    });
    expect(triggerPosition(graph)).toEqual({ x: 300, y: 50 });

    const empty = makeGraph({ schema: 1, entry: null, steps: {} });
    expect(triggerPosition(empty)).toEqual({ x: 80, y: 40 });
    expect(TRIGGER_NODE_ID.startsWith('__')).toBe(true);
  });
});
