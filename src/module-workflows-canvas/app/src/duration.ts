/**
 * The duration model behind the composite amount+unit control (delay duration,
 * wait timeout, approval timeout). A behaviour port of the template install
 * form's `param-duration.js` peer: the REAL value is always the ISO-8601 string
 * the server validates (`Definition::assertDuration` -> `new \DateInterval`,
 * plus the `iso8601Duration` pattern in spec/workflow-definition.schema.json);
 * the composite only writes into it.
 *
 * Two modes, exactly as on the install form:
 *   - composite: the current value splits EXACTLY into an amount + one of the
 *     three offered units (`splitDuration` non-null) — the operator picks a
 *     number and a unit;
 *   - raw ISO: anything the composite cannot represent — no value yet, or a
 *     compound interval such as `P1DT12H`, or a unit we do not offer (weeks,
 *     months, seconds) — the plain ISO input IS the control.
 *
 * That fallback is what makes the round-trip lossless: an arbitrary existing
 * ISO string is never re-encoded through the composite, it is carried verbatim
 * in the escape-hatch input.
 *
 * Framework-free (no React, no DOM) so every rule here is pinned by vitest.
 *
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

import { t } from './i18n';

export type DurationUnit = 'minutes' | 'hours' | 'days';

export interface DurationParts {
  amount: number;
  unit: DurationUnit;
}

/** unit => ISO-8601 template; `%d` is the amount. Also the allowed unit set. */
const PATTERNS: Record<DurationUnit, string> = {
  minutes: 'PT%dM',
  hours: 'PT%dH',
  days: 'P%dD',
};

/**
 * The unit select's options, in the install form's order. A function rather
 * than a module constant so the labels resolve through t() AFTER the phrase
 * map is installed at mount; the values are machine codes and stay untouched.
 */
export function durationUnits(): { value: DurationUnit; label: string }[] {
  return [
    { value: 'minutes', label: t('minutes') },
    { value: 'hours', label: t('hours') },
    { value: 'days', label: t('days') },
  ];
}

/**
 * The exact ISO-8601 duration grammar the definition schema declares
 * (spec/workflow-definition.schema.json `iso8601Duration`), kept byte-identical
 * so the panel's advisory notice and the server's verdict agree.
 */
export const ISO_DURATION_PATTERN = /^P(?!$)(\d+Y)?(\d+M)?(\d+W)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+S)?)?$/;

/** unit => the single-component pattern the composite can represent exactly. */
const SPLIT_PATTERNS: { pattern: RegExp; unit: DurationUnit }[] = [
  { pattern: /^P(\d+)D$/, unit: 'days' },
  { pattern: /^PT(\d+)H$/, unit: 'hours' },
  { pattern: /^PT(\d+)M$/, unit: 'minutes' },
];

/** Whether a string is a well-formed ISO-8601 duration the server will accept. */
export function isIsoDuration(value: unknown): boolean {
  return typeof value === 'string' && ISO_DURATION_PATTERN.test(value);
}

/**
 * The value split into an amount and one of the three offered units, or null
 * for anything the composite cannot represent exactly (empty, a compound
 * interval such as `P1DT12H`, weeks/months/seconds, garbage). Null means "stay
 * in raw ISO mode" — the escape hatch.
 */
export function splitDuration(value: unknown): DurationParts | null {
  if (typeof value !== 'string') {
    return null;
  }
  for (const { pattern, unit } of SPLIT_PATTERNS) {
    const match = pattern.exec(value);
    if (match) {
      return { amount: Number(match[1]), unit };
    }
  }
  return null;
}

/**
 * The ISO-8601 string for an amount + unit, or null when the pair cannot be
 * composed (a half-typed or non-positive amount, an unknown unit). Null is the
 * caller's signal to LEAVE THE STORED VALUE ALONE — mirroring the install
 * form's rule that a half-typed number must never blank a value the operator
 * already has.
 */
export function composeDuration(amount: unknown, unit: unknown): string | null {
  const raw = String(amount ?? '').trim();
  if (!/^\d+$/.test(raw)) {
    return null;
  }
  const n = Number.parseInt(raw, 10);
  if (n < 1) {
    return null;
  }
  if (typeof unit !== 'string' || !Object.prototype.hasOwnProperty.call(PATTERNS, unit)) {
    return null;
  }
  return PATTERNS[unit as DurationUnit].replace('%d', String(n));
}

/**
 * Whether the composite control can render this value at all. Sugar over
 * splitDuration for the component's mode decision.
 */
export function isCompositeDuration(value: unknown): boolean {
  return splitDuration(value) !== null;
}
