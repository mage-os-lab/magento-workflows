import { describe, expect, it } from 'vitest';
import { actionByCode, buildPalette, groupTriggers } from '../src/palette';
import { action, makeConfig } from './support';
import type { TriggerMeta } from '../src/types';

describe('buildPalette', () => {
  it('always leads with the Flow primitives group', () => {
    const groups = buildPalette(makeConfig({ actionsMeta: [] }));
    expect(groups[0].label).toBe('Flow');
    expect(groups[0].items.map((i) => i.type)).toEqual(['delay', 'branch', 'wait', 'switch', 'stop']);
  });

  it('groups actions by their metadata group, sorted', () => {
    const config = makeConfig({
      actionsMeta: [
        action({ code: 'c.b', label: 'Beta', group: 'Customer' }),
        action({ code: 'c.a', label: 'Alpha', group: 'Customer' }),
        action({ code: 's.a', label: 'Ship', group: 'Sales' }),
      ],
    });
    const groups = buildPalette(config);
    const labels = groups.map((g) => g.label);
    expect(labels).toEqual(['Flow', 'Customer', 'Sales']);
    // sorted by label within group
    expect(groups[1].items.map((i) => (i.kind === 'action' ? i.label : ''))).toEqual(['Alpha', 'Beta']);
  });

  it('omits the Approval gate item when the addon is not installed', () => {
    const groups = buildPalette(makeConfig({ actionsMeta: [], approvalsAvailable: false }));
    expect(groups[0].items.map((i) => i.type)).toEqual(['delay', 'branch', 'wait', 'switch', 'stop']);
  });

  it('offers the Approval gate item (before Stop) only when the addon is installed', () => {
    const groups = buildPalette(makeConfig({ actionsMeta: [], approvalsAvailable: true }));
    expect(groups[0].items.map((i) => i.type)).toEqual([
      'delay',
      'branch',
      'wait',
      'switch',
      'approval',
      'stop',
    ]);
  });

  it('only surfaces actions present in the (server ACL-filtered) bootstrap', () => {
    // The provider hides actions the admin cannot author; the palette reflects
    // exactly that list — it never re-adds a hidden action.
    const config = makeConfig({
      actionsMeta: [action({ code: 'order.hold', label: 'Hold', group: 'Sales' })],
    });
    const groups = buildPalette(config);
    const codes = groups.flatMap((g) => g.items).filter((i) => i.kind === 'action').map((i) => (i.kind === 'action' ? i.code : ''));
    expect(codes).toEqual(['order.hold']);
    expect(codes).not.toContain('order.cancel');
  });
});

describe('groupTriggers', () => {
  it('groups by group with null-group last as "Other"', () => {
    const triggers: TriggerMeta[] = [
      { event: 'a', entity: 'sales_order', label: 'A', group: 'Sales' },
      { event: 'b', entity: 'customer', label: 'B', group: null },
    ];
    const grouped = groupTriggers(triggers);
    expect(grouped.map((g) => g.label)).toEqual(['Other', 'Sales']);
  });
});

describe('actionByCode', () => {
  it('resolves a palette action for config-panel generation', () => {
    const config = makeConfig({ actionsMeta: [action({ code: 'x.y' })] });
    expect(actionByCode(config, 'x.y')?.code).toBe('x.y');
    expect(actionByCode(config, 'nope')).toBeUndefined();
  });
});
