/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

import { describe, expect, it } from 'vitest';
import {
  SEARCH_DEBOUNCE_MS,
  addToMultiValue,
  eventOptionGroups,
  isCataloguedEvent,
  isValidEventName,
  isValidTimeOfDay,
  labelForValue,
  multiSelectOptions,
  normalizeConfigForm,
  normalizeField,
  optionsWithSelected,
  parseMultiValue,
  readValue,
  removeFromMultiValue,
  searchAddOptions,
  serializeMultiValue,
  shouldSearch,
  writeValue,
} from '../src/configPanel';
import { composeDuration } from '../src/duration';
import { toDefinition } from '../src/mapping';
import { action, makeGraph } from './support';
import type { ConfigField, StepNode, TriggerMeta } from '../src/types';

describe('normalizeField — the F6 options / options_search union', () => {
  it('classifies an inline bounded list as "inline"', () => {
    const f: ConfigField = {
      name: 'mode',
      label: 'Mode',
      type: 'select',
      options: [{ value: 'add', label: 'Add' }, { value: 'remove', label: 'Remove' }],
    };
    const n = normalizeField(f);
    expect(n.optionMode).toBe('inline');
    expect(n.inlineOptions).toHaveLength(2);
    expect(n.searchSource).toBeNull();
  });

  it('classifies an options_search source as "search" with min_chars', () => {
    const f: ConfigField = {
      name: 'rule',
      label: 'Cart Rule',
      type: 'select',
      options_search: { source: 'cart_price_rules', min_chars: 2 },
    };
    const n = normalizeField(f);
    expect(n.optionMode).toBe('search');
    expect(n.searchSource).toBe('cart_price_rules');
    expect(n.minChars).toBe(2);
  });

  it('classifies a plain input as "none"', () => {
    expect(normalizeField({ name: 'c', label: 'C', type: 'textarea' }).optionMode).toBe('none');
  });

  it('flags a secret field (names only, never values)', () => {
    const n = normalizeField({ name: 's', label: 'Secret', type: 'secret' });
    expect(n.isSecret).toBe(true);
    expect(n.optionMode).toBe('none');
  });

  it('flags a multiselect (multi), whichever option mode it carries', () => {
    const searched = normalizeField({
      name: 'skus',
      label: 'Products',
      type: 'multiselect',
      options_search: { source: 'products', min_chars: 2 },
    });
    expect(searched.multi).toBe(true);
    expect(searched.optionMode).toBe('search');

    const inline = normalizeField({
      name: 'website_ids',
      label: 'Websites',
      type: 'multiselect',
      options: [{ value: '1', label: 'Main' }],
    });
    expect(inline.multi).toBe(true);
    expect(inline.optionMode).toBe('inline');

    expect(normalizeField({ name: 'c', label: 'C', type: 'select' }).multi).toBe(false);
  });

  it('prefers inline options when both are somehow present', () => {
    const n = normalizeField({
      name: 'x',
      label: 'X',
      type: 'select',
      options: [{ value: 'a', label: 'A' }],
      options_search: { source: 's' },
    });
    expect(n.optionMode).toBe('inline');
  });
});

describe('normalizeConfigForm', () => {
  it('maps an action getConfigForm to a render model', () => {
    const a = action({
      configForm: [
        { name: 'comment', label: 'Comment', type: 'textarea', required: true },
        { name: 'status', label: 'Status', type: 'select', options: [{ value: 'a', label: 'A' }] },
      ],
    });
    const fields = normalizeConfigForm(a);
    expect(fields).toHaveLength(2);
    expect(fields[0].required).toBe(true);
    expect(fields[1].optionMode).toBe('inline');
  });

  it('returns [] for a config-less (empty-form) action', () => {
    expect(normalizeConfigForm(action({ configForm: [] }))).toEqual([]);
    expect(normalizeConfigForm(undefined)).toEqual([]);
  });
});

describe('readValue / writeValue', () => {
  const field = normalizeField({ name: 'comment', label: 'C', type: 'text', default: 'hi' });

  it('reads a set value, else the default', () => {
    const step: StepNode = { type: 'action', config: { comment: 'set' } };
    expect(readValue(step, field)).toBe('set');
    expect(readValue({ type: 'action', config: {} }, field)).toBe('hi');
  });

  it('writes immutably and removes emptied keys', () => {
    const step: StepNode = { type: 'action', config: { comment: 'x' } };
    const set = writeValue(step, 'comment', 'y');
    expect(set.config).toEqual({ comment: 'y' });
    expect(step.config).toEqual({ comment: 'x' }); // unchanged

    const cleared = writeValue(step, 'comment', '');
    expect(cleared.config).toEqual({});
  });
});

describe('shouldSearch', () => {
  const field = normalizeField({ name: 'r', label: 'R', type: 'select', options_search: { source: 's', min_chars: 3 } });

  it('fires only at/above min_chars', () => {
    expect(shouldSearch(field, 'ab')).toBe(false);
    expect(shouldSearch(field, 'abc')).toBe(true);
  });

  it('debounces at the install form’s 250ms', () => {
    expect(SEARCH_DEBOUNCE_MS).toBe(250);
  });
});

/**
 * The search-select bug: the persisted value used to live on the `value=""`
 * placeholder option, so re-picking the item the field already showed CLEARED
 * it, and the field displayed a bare id. It must be a real option, labelled
 * whenever a fetch has resolved it.
 */
describe('optionsWithSelected / labelForValue', () => {
  const fetched = [
    { value: '5', label: 'Summer Sale' },
    { value: '6', label: 'Winter Sale' },
  ];

  it('adds the persisted value as a real selectable option', () => {
    expect(optionsWithSelected(fetched, '9')).toEqual([...fetched, { value: '9', label: '9' }]);
    expect(optionsWithSelected(fetched, '9', 'Flash Sale')).toEqual([
      ...fetched,
      { value: '9', label: 'Flash Sale' },
    ]);
  });

  it('never duplicates a value the fetch already offers, and never adds an empty one', () => {
    expect(optionsWithSelected(fetched, '5')).toEqual(fetched);
    expect(optionsWithSelected(fetched, '')).toEqual(fetched);
  });

  it('resolves a label from the fetched options when it is there', () => {
    expect(labelForValue(fetched, '6')).toBe('Winter Sale');
    expect(labelForValue(fetched, '9')).toBeNull();
    expect(labelForValue(fetched, '')).toBeNull();
  });
});

/**
 * The multiselect gap (blocker for AssignWebsites, which declares
 * `type: multiselect`): the runtime explodes a COMMA-SEPARATED string
 * (AssignWebsites::parseWebsiteIds), so that is what the control must store.
 */
describe('multiselect serialization', () => {
  it('serializes the selection to the comma list the runtime parses', () => {
    expect(serializeMultiValue(['1', '2'])).toBe('1,2');
    expect(serializeMultiValue(['1'])).toBe('1');
  });

  it('empties to "" so writeValue removes the key rather than storing a blank', () => {
    expect(serializeMultiValue([])).toBe('');
    const step: StepNode = { type: 'action', action: 'a', config: { website_ids: '1,2' } };
    expect(writeValue(step, 'website_ids', serializeMultiValue([])).config).toEqual({});
  });

  it('drops blanks and duplicates while preserving selection order', () => {
    expect(serializeMultiValue(['2', '', '1', '2', ' 3 '])).toBe('2,1,3');
  });

  it('parses the stored string back (tolerating the runtime’s loose spacing)', () => {
    expect(parseMultiValue('1,2')).toEqual(['1', '2']);
    expect(parseMultiValue('1, 2 ,,3')).toEqual(['1', '2', '3']);
    expect(parseMultiValue('')).toEqual([]);
    expect(parseMultiValue(undefined)).toEqual([]);
  });

  it('tolerates an array from a hand-edited definition on read', () => {
    expect(parseMultiValue([1, 2])).toEqual(['1', '2']);
  });

  it('survives a store -> render -> store round-trip', () => {
    const stored = serializeMultiValue(['1', '3']);
    expect(serializeMultiValue(parseMultiValue(stored))).toBe(stored);
  });

  it('keeps a stored value the option list no longer offers', () => {
    const options = [{ value: '1', label: 'Main' }];
    expect(multiSelectOptions(options, ['1', '7'])).toEqual([
      { value: '1', label: 'Main' },
      { value: '7', label: '7 (not offered)' },
    ]);
  });
});

/**
 * The multi search-select (multiselect + options_search): chips over the same
 * comma-list value — picking a result ADDS, a chip's × REMOVES, and both write
 * through serializeMultiValue so the stored shape stays the runtime's.
 */
describe('multi search-select chip logic', () => {
  it('adds a picked value to the stored comma list', () => {
    expect(addToMultiValue('', '5')).toBe('5');
    expect(addToMultiValue('5', '9')).toBe('5,9');
    expect(addToMultiValue(undefined, '5')).toBe('5');
  });

  it('re-picking an already-selected value is a no-op, not a duplicate', () => {
    expect(addToMultiValue('5,9', '5')).toBe('5,9');
  });

  it('tolerates a legacy array value on add', () => {
    expect(addToMultiValue([5, 9], '2')).toBe('5,9,2');
  });

  it('removes one chip, preserving the rest in order', () => {
    expect(removeFromMultiValue('5,9,2', '9')).toBe('5,2');
    expect(removeFromMultiValue('5,9,2', 'missing')).toBe('5,9,2');
  });

  it('removing the last chip empties to "" so writeValue drops the key', () => {
    expect(removeFromMultiValue('5', '5')).toBe('');
    const step: StepNode = { type: 'action', action: 'a', config: { skus: '5' } };
    expect(writeValue(step, 'skus', removeFromMultiValue('5', '5')).config).toEqual({});
  });

  it('survives an add -> remove round-trip', () => {
    expect(removeFromMultiValue(addToMultiValue('5,9', '2'), '2')).toBe('5,9');
  });

  it('offers only the fetched results not already selected as chips', () => {
    const fetched = [
      { value: '5', label: 'Summer Sale' },
      { value: '6', label: 'Winter Sale' },
    ];
    expect(searchAddOptions(fetched, ['5'])).toEqual([{ value: '6', label: 'Winter Sale' }]);
    expect(searchAddOptions(fetched, [])).toEqual(fetched);
    expect(searchAddOptions([], ['5'])).toEqual([]);
  });
});

/**
 * Wait step (blocker): the server requires BOTH config.event and config.timeout
 * (`waitStep.config`), and the event name comes from the bootstrapped trigger
 * catalogue with a free-entry escape hatch.
 */
describe('wait step fields', () => {
  const triggers: TriggerMeta[] = [
    { event: 'sales.order.placed', entity: 'sales_order', label: 'Order Placed', group: 'Sales' },
    { event: 'sales.order.placed', entity: 'sales_invoice', label: 'Order Placed', group: 'Sales' },
    { event: 'sales.shipment.created', entity: 'sales_order', label: '', group: 'Sales' },
    { event: 'customer.registered', entity: 'customer', label: 'Registered', group: null },
  ];

  it('groups the catalogue and offers each distinct event once', () => {
    expect(eventOptionGroups(triggers)).toEqual([
      { label: 'Other', options: [{ value: 'customer.registered', label: 'Registered (customer.registered)' }] },
      {
        label: 'Sales',
        options: [
          { value: 'sales.order.placed', label: 'Order Placed (sales.order.placed)' },
          { value: 'sales.shipment.created', label: 'sales.shipment.created' },
        ],
      },
    ]);
    expect(eventOptionGroups([])).toEqual([]);
  });

  it('knows whether a stored event is in the catalogue (escape-hatch decision)', () => {
    expect(isCataloguedEvent(triggers, 'customer.registered')).toBe(true);
    expect(isCataloguedEvent(triggers, 'thirdparty.thing.done')).toBe(false);
    expect(isCataloguedEvent(triggers, '')).toBe(false);
  });

  it('validates a free-entry event against the server grammar', () => {
    expect(isValidEventName('sales.order.placed')).toBe(true);
    expect(isValidEventName('a-b_c.9')).toBe(true);
    expect(isValidEventName('a'.repeat(128))).toBe(true);
    expect(isValidEventName('a'.repeat(129))).toBe(false);
    expect(isValidEventName('Sales.Order')).toBe(false);
    expect(isValidEventName('has space')).toBe(false);
    expect(isValidEventName('')).toBe(false);
  });

  it('serializes event + timeout into exactly the two keys the server requires', () => {
    let step: StepNode = { type: 'wait', config: { event: '' }, on_event: null, on_timeout: null };
    step = writeValue(step, 'event', 'sales.order.placed');
    step = writeValue(step, 'timeout', composeDuration(2, 'days'));
    expect(step.config).toEqual({ event: 'sales.order.placed', timeout: 'P2D' });

    const g = makeGraph({ schema: 3, entry: 'w', steps: { w: step } });
    expect(toDefinition(g).steps.w.config).toEqual({
      event: 'sales.order.placed',
      timeout: 'P2D',
    });
  });
});

/**
 * Delay step: the server also accepts config.business_days (bool) and
 * config.at ("HH:MM", store-local) — Definition::assertDelayExtras.
 */
describe('delay step fields', () => {
  it('validates `at` against the server’s 24-hour grammar', () => {
    expect(isValidTimeOfDay('00:00')).toBe(true);
    expect(isValidTimeOfDay('09:30')).toBe(true);
    expect(isValidTimeOfDay('23:59')).toBe(true);
    expect(isValidTimeOfDay('24:00')).toBe(false);
    expect(isValidTimeOfDay('9:30')).toBe(false);
    expect(isValidTimeOfDay('09:60')).toBe(false);
    expect(isValidTimeOfDay('09:30:00')).toBe(false);
    expect(isValidTimeOfDay('')).toBe(false);
  });

  it('serializes duration + business_days + at', () => {
    let step: StepNode = { type: 'delay', config: { duration: 'PT1H' }, next: null };
    step = writeValue(step, 'duration', composeDuration(3, 'days'));
    step = writeValue(step, 'business_days', true);
    step = writeValue(step, 'at', '09:30');
    expect(step.config).toEqual({ duration: 'P3D', business_days: true, at: '09:30' });
  });

  it('clears `at` back out of the config when emptied', () => {
    const step: StepNode = { type: 'delay', config: { duration: 'P3D', at: '09:30' }, next: null };
    expect(writeValue(step, 'at', '').config).toEqual({ duration: 'P3D' });
  });
});
