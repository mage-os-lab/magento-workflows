import { describe, expect, it } from 'vitest';
import {
  payloadTypes,
  blankPayloadField,
  notifyEmailError,
  payloadFieldError,
  payloadTypeOptions,
  readNotifyEmails,
  readPayloadFields,
  serializeNotifyEmails,
  serializePayloadFields,
} from '../src/approvalFields';
import { moveItem, removeAt, replaceAt } from '../src/listEdit';
import { writeValue } from '../src/configPanel';
import type { ApprovalPayloadField, StepNode } from '../src/types';

/**
 * The approval gate's two list-shaped config fields, now row editors instead of
 * JSON textareas. Two things are pinned here: the serialized shape (which must
 * match Definition::assertApprovalStep and the schema's
 * additionalProperties:false rows exactly) and the "commit every change"
 * property that replaces the textarea's commit-on-blur data loss.
 */

const rows: ApprovalPayloadField[] = [
  { key: 'amount', label: 'Amount', type: 'number', required: true },
  { key: 'note', label: 'Note', type: 'string' },
];

describe('payload_fields — read', () => {
  it('normalizes stored rows and drops keys the schema forbids', () => {
    // approvalStep.config.payload_fields.items is additionalProperties:false,
    // so an unmodelled key must not survive into a save.
    expect(
      readPayloadFields([
        { key: 'amount', label: 'Amount', type: 'number', required: true, bogus: 'x' },
      ]),
    ).toEqual([{ key: 'amount', label: 'Amount', type: 'number', required: true }]);
  });

  it('fills missing pieces without inventing content', () => {
    expect(readPayloadFields([{}])).toEqual([{ key: '', label: '', type: 'string' }]);
    expect(readPayloadFields([{ key: 'k', label: 'L', type: 'weird' }])).toEqual([
      { key: 'k', label: 'L', type: 'weird' },
    ]);
  });

  it('treats a non-array (the old textarea could hold anything) as empty', () => {
    expect(readPayloadFields(undefined)).toEqual([]);
    expect(readPayloadFields('[]')).toEqual([]);
    expect(readPayloadFields({ key: 'x' })).toEqual([]);
  });
});

describe('payload_fields — serialize', () => {
  it('emits exactly {key,label,type} plus required only when true', () => {
    expect(serializePayloadFields(rows)).toEqual([
      { key: 'amount', label: 'Amount', type: 'number', required: true },
      { key: 'note', label: 'Note', type: 'string' },
    ]);
    expect(
      serializePayloadFields([{ key: 'k', label: 'L', type: 'string', required: false }]),
    ).toEqual([{ key: 'k', label: 'L', type: 'string' }]);
  });

  it('removes the config key for an empty list (minItems 1 server-side)', () => {
    expect(serializePayloadFields([])).toBeUndefined();
    const step: StepNode = { type: 'approval', config: { title: 'T', payload_fields: rows } };
    expect(writeValue(step, 'payload_fields', serializePayloadFields([])).config).toEqual({
      title: 'T',
    });
  });

  it('round-trips read -> serialize unchanged', () => {
    const stored = serializePayloadFields(rows);
    expect(serializePayloadFields(readPayloadFields(stored))).toEqual(stored);
  });
});

describe('payload_fields — row editing commits every change', () => {
  it('applies a key edit, a type change, a reorder and a removal immutably', () => {
    const renamed = replaceAt(rows, 0, { ...rows[0], key: 'total' });
    expect(serializePayloadFields(renamed)?.[0].key).toBe('total');

    const retyped = replaceAt<ApprovalPayloadField>(rows, 1, { ...rows[1], type: 'boolean' });
    expect(serializePayloadFields(retyped)?.[1].type).toBe('boolean');

    expect(serializePayloadFields(moveItem(rows, 1, -1))?.map((r) => r.key)).toEqual([
      'note',
      'amount',
    ]);
    expect(serializePayloadFields(removeAt(rows, 0))).toEqual([
      { key: 'note', label: 'Note', type: 'string' },
    ]);
    expect(rows.map((r) => r.key)).toEqual(['amount', 'note']); // untouched
  });

  it('adds a blank row with a non-colliding key', () => {
    expect(blankPayloadField([])).toEqual({ key: 'field_1', label: '', type: 'string' });
    expect(blankPayloadField(rows).key).toBe('field_3');
    expect(blankPayloadField([{ key: 'field_2', label: 'L', type: 'string' }]).key).toBe('field_3');
  });
});

describe('payload_fields — inline validation (the server checks, restated)', () => {
  it('reports a missing, malformed or duplicate key and a missing label', () => {
    expect(payloadFieldError(rows, 0)).toBeNull();
    expect(payloadFieldError([{ key: '', label: 'L', type: 'string' }], 0)).toBe(
      'A key is required.',
    );
    expect(payloadFieldError([{ key: 'not ok', label: 'L', type: 'string' }], 0)).toBe(
      'Use letters, numbers or "_" (max 64 characters).',
    );
    expect(payloadFieldError([{ key: 'a-b', label: 'L', type: 'string' }], 0)).toBe(
      'Use letters, numbers or "_" (max 64 characters).',
    );
    expect(payloadFieldError([{ key: 'k', label: '', type: 'string' }], 0)).toBe(
      'A label is required.',
    );
    const dupes: ApprovalPayloadField[] = [
      { key: 'k', label: 'A', type: 'string' },
      { key: 'k', label: 'B', type: 'string' },
    ];
    expect(payloadFieldError(dupes, 1)).toBe('This key is used more than once: "k"');
    expect(payloadFieldError(rows, 9)).toBeNull();
  });
});

describe('payload_fields — type options', () => {
  it('offers the three server types, plus an unrecognized stored one', () => {
    expect(payloadTypes().map((o) => o.value)).toEqual(['string', 'number', 'boolean']);
    expect(payloadTypeOptions('number')).toEqual(payloadTypes());
    expect(payloadTypeOptions('weird')).toEqual([
      ...payloadTypes(),
      { value: 'weird', label: 'weird (not offered)' },
    ]);
  });
});

describe('notify_emails', () => {
  it('reads, edits and serializes a plain string list', () => {
    const stored = ['ops@example.com', 'boss@example.com'];
    expect(readNotifyEmails(stored)).toEqual(stored);
    expect(readNotifyEmails(undefined)).toEqual([]);
    expect(readNotifyEmails('ops@example.com')).toEqual([]);

    expect(serializeNotifyEmails(replaceAt(stored, 1, 'cfo@example.com'))).toEqual([
      'ops@example.com',
      'cfo@example.com',
    ]);
    expect(serializeNotifyEmails(removeAt(stored, 0))).toEqual(['boss@example.com']);
    expect(serializeNotifyEmails([...stored, 'new@example.com'])).toHaveLength(3);
  });

  it('removes the config key for an empty list (minItems 1 server-side)', () => {
    expect(serializeNotifyEmails([])).toBeUndefined();
    const step: StepNode = { type: 'approval', config: { notify_emails: ['a@b.co'] } };
    expect(writeValue(step, 'notify_emails', serializeNotifyEmails([])).config).toEqual({});
  });

  it('validates the address format inline', () => {
    expect(notifyEmailError('ops@example.com')).toBeNull();
    expect(notifyEmailError('first.last+tag@sub.example.co.uk')).toBeNull();
    expect(notifyEmailError('')).toBe('An email address is required.');
    expect(notifyEmailError('   ')).toBe('An email address is required.');
    expect(notifyEmailError('ops@example')).toBe('That does not look like an email address.');
    expect(notifyEmailError('a@b.co, c@d.co')).toBe('That does not look like an email address.');
    expect(notifyEmailError('nope')).toBe('That does not look like an email address.');
  });
});

describe('listEdit primitives', () => {
  it('is a no-op (same array) for an out-of-range index or a boundary move', () => {
    const list = ['a', 'b'];
    expect(removeAt(list, 5)).toBe(list);
    expect(replaceAt(list, -1, 'z')).toBe(list);
    expect(moveItem(list, 0, -1)).toBe(list);
    expect(moveItem(list, 1, 1)).toBe(list);
    expect(moveItem(list, 0, 0)).toBe(list);
  });

  it('never mutates its input', () => {
    const list = ['a', 'b', 'c'];
    expect(moveItem(list, 2, -2)).toEqual(['c', 'a', 'b']);
    expect(removeAt(list, 1)).toEqual(['a', 'c']);
    expect(replaceAt(list, 1, 'z')).toEqual(['a', 'z', 'c']);
    expect(list).toEqual(['a', 'b', 'c']);
  });
});
