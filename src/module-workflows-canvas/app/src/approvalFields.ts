import { t } from './i18n';
import type { ApprovalPayloadField, ConfigFieldOption } from './types';

/**
 * The model behind the approval gate's two list-shaped config fields —
 * `payload_fields` and `notify_emails`. It replaces the JSON textarea whose
 * commit-on-blur-only-if-parseable behaviour silently dropped edits: the row
 * editors commit through these pure functions on EVERY change, so there is no
 * uncommitted buffer to lose.
 *
 * Serialization matches the server exactly (`Definition::assertApprovalStep`
 * and `approvalStep.config` in spec/workflow-definition.schema.json):
 *   payload_fields : non-empty list of {key, label, type, required?} and
 *                    NOTHING else — the schema declares
 *                    additionalProperties:false on the row, so an unmodelled
 *                    key from a hand-edited definition is dropped here rather
 *                    than posted into a save the server would bounce;
 *   notify_emails  : non-empty list of non-empty strings.
 * "Non-empty list" is why both serializers return undefined for an empty row
 * list: writeValue's undefined convention REMOVES the config key, and absent is
 * the shape that means "none" (an empty array is rejected).
 */

/** Row key grammar, byte-identical to the server's. */
export const PAYLOAD_KEY_PATTERN = /^[a-zA-Z0-9_]{1,64}$/;

/**
 * The scalar types a payload field may declare. A function (not a module
 * constant) so the labels resolve through t() after the phrase map is
 * installed at mount; the values are the schema's machine codes.
 */
export function payloadTypes(): ConfigFieldOption[] {
  return [
    { value: 'string', label: t('Text') },
    { value: 'number', label: t('Number') },
    { value: 'boolean', label: t('Yes / No') },
  ];
}

/**
 * A conservative address shape: one @, no spaces, a dotted domain. Deliberately
 * looser than a full RFC grammar (the server only requires a non-empty string)
 * so a legitimate-but-unusual address is never blocked — this is an inline
 * warning, not a gate.
 */
export const EMAIL_PATTERN = /^[^\s@,]+@[^\s@,]+\.[^\s@,]+$/;

/** The stored payload_fields, normalized into editable rows. */
export function readPayloadFields(value: unknown): ApprovalPayloadField[] {
  if (!Array.isArray(value)) {
    return [];
  }
  return value.map((entry) => {
    const row = (entry && typeof entry === 'object' ? entry : {}) as Record<string, unknown>;
    const field: ApprovalPayloadField = {
      key: typeof row.key === 'string' ? row.key : '',
      label: typeof row.label === 'string' ? row.label : '',
      type: normalizeType(row.type),
    };
    if (row.required === true) {
      field.required = true;
    }
    return field;
  });
}

/**
 * Rows -> the config value. undefined (i.e. "remove the key") for an empty list
 * because the schema requires minItems 1 when the key is present. `required` is
 * emitted only when true: absent already means "not required" (schema default),
 * so this keeps the definition minimal.
 */
export function serializePayloadFields(
  rows: readonly ApprovalPayloadField[],
): ApprovalPayloadField[] | undefined {
  if (rows.length === 0) {
    return undefined;
  }
  return rows.map((row) => {
    const out: ApprovalPayloadField = {
      key: String(row.key ?? ''),
      label: String(row.label ?? ''),
      type: normalizeType(row.type),
    };
    if (row.required === true) {
      out.required = true;
    }
    return out;
  });
}

/** A new blank row, keyed so it does not collide with an existing one. */
export function blankPayloadField(rows: readonly ApprovalPayloadField[]): ApprovalPayloadField {
  const used = new Set(rows.map((r) => String(r.key ?? '')));
  let n = rows.length + 1;
  while (used.has(`field_${n}`)) {
    n += 1;
  }
  return { key: `field_${n}`, label: '', type: 'string' };
}

/**
 * The inline message for one row (null when it is fine) — the same three checks
 * the server runs, surfaced before the save round-trip. Every row is committed
 * either way: a half-filled row stays visible and fixable rather than being
 * silently withheld.
 */
export function payloadFieldError(
  rows: readonly ApprovalPayloadField[],
  index: number,
): string | null {
  const row = rows[index];
  if (!row) {
    return null;
  }
  const key = String(row.key ?? '');
  if (key === '') {
    return t('A key is required.');
  }
  if (!PAYLOAD_KEY_PATTERN.test(key)) {
    return t('Use letters, numbers or "_" (max 64 characters).');
  }
  if (rows.some((other, i) => i !== index && String(other.key ?? '') === key)) {
    return `${t('This key is used more than once:')} "${key}"`;
  }
  if (String(row.label ?? '') === '') {
    return t('A label is required.');
  }
  return null;
}

/**
 * The type select's options, always including the stored value so a type this
 * build does not offer cannot be silently rewritten by rendering a select.
 */
export function payloadTypeOptions(current: unknown): ConfigFieldOption[] {
  const value = String(current ?? '');
  const types = payloadTypes();
  if (value === '' || types.some((o) => o.value === value)) {
    return types;
  }
  return [...types, { value, label: `${value} ${t('(not offered)')}` }];
}

/** The stored notify_emails, normalized into editable rows. */
export function readNotifyEmails(value: unknown): string[] {
  if (!Array.isArray(value)) {
    return [];
  }
  return value.map((entry) => (typeof entry === 'string' ? entry : String(entry ?? '')));
}

/** Rows -> the config value; undefined for an empty list (see above). */
export function serializeNotifyEmails(rows: readonly string[]): string[] | undefined {
  if (rows.length === 0) {
    return undefined;
  }
  return rows.map((row) => String(row ?? ''));
}

/** The inline message for one email row, or null when it looks like an address. */
export function notifyEmailError(email: unknown): string | null {
  const value = String(email ?? '').trim();
  if (value === '') {
    return t('An email address is required.');
  }
  if (!EMAIL_PATTERN.test(value)) {
    return t('That does not look like an email address.');
  }
  return null;
}

function normalizeType(value: unknown): ApprovalPayloadField['type'] {
  // Anything unrecognized is carried through verbatim (never rewritten behind
  // the operator's back); the select offers it back via payloadTypeOptions.
  return (typeof value === 'string' && value !== ''
    ? value
    : 'string') as ApprovalPayloadField['type'];
}
