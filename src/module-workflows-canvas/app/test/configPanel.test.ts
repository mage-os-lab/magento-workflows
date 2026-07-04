import { describe, expect, it } from 'vitest';
import {
  normalizeConfigForm,
  normalizeField,
  readValue,
  shouldSearch,
  writeValue,
} from '../src/configPanel';
import { action } from './support';
import type { ConfigField, StepNode } from '../src/types';

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
});
