/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

import { t } from './i18n';
import { groupTriggers } from './palette';
import type { ConfigField, ConfigFieldOption, PaletteAction, StepNode, TriggerMeta } from './types';

/**
 * Config-panel generation from getConfigForm() metadata (F6). A field's option
 * mode is the F6 union:
 *   - 'inline'  : bounded list shipped in `options` — render a static select;
 *   - 'search'  : `options_search: {source, min_chars}` — the client hits
 *                 GET meta/options?source=&q= (min_chars gates when a live
 *                 search fires; 0 = load the whole list up front);
 *   - 'none'    : a plain input (text/textarea/integer/boolean/secret).
 * The secret type lists NAMES only (never values) — resolved from the
 * bootstrap secrets list, not from any option source.
 *
 * Pure: value get/set operate on a config object copy; no DOM, no eval. The
 * server re-validates every field on save, so this is authoring convenience.
 */

export type OptionMode = 'inline' | 'search' | 'none';

export interface NormalizedField {
  name: string;
  label: string;
  type: string;
  required: boolean;
  notice: string | null;
  default: unknown;
  optionMode: OptionMode;
  inlineOptions: ConfigFieldOption[];
  searchSource: string | null;
  minChars: number;
  isSecret: boolean;
  /** true for `type: multiselect` — the value is the comma-separated list. */
  multi: boolean;
}

/** Normalize an action's getConfigForm() field list into a render model. */
export function normalizeConfigForm(action: PaletteAction | undefined): NormalizedField[] {
  if (!action) {
    return [];
  }
  return action.configForm.map(normalizeField);
}

export function normalizeField(field: ConfigField): NormalizedField {
  const inline = Array.isArray(field.options) ? field.options : [];
  const search = field.options_search;
  let optionMode: OptionMode = 'none';
  if (inline.length > 0) {
    optionMode = 'inline';
  } else if (search && typeof search.source === 'string' && search.source !== '') {
    optionMode = 'search';
  }
  return {
    name: String(field.name ?? ''),
    label: String(field.label ?? field.name ?? ''),
    type: String(field.type ?? 'text'),
    required: Boolean(field.required),
    notice: typeof field.notice === 'string' ? field.notice : null,
    default: field.default,
    optionMode,
    inlineOptions: inline,
    searchSource: optionMode === 'search' ? String(search?.source) : null,
    minChars: optionMode === 'search' ? Number(search?.min_chars ?? 0) : 0,
    isSecret: String(field.type ?? '') === 'secret',
    multi: String(field.type ?? '') === 'multiselect',
  };
}

/** Read a config value out of a step, falling back to the field default. */
export function readValue(step: StepNode, field: NormalizedField): unknown {
  const config = (step.config ?? {}) as Record<string, unknown>;
  return field.name in config ? config[field.name] : field.default;
}

/**
 * Return a NEW step with one config field set (immutable). An undefined/empty
 * value removes the key so an unset optional field is not persisted as "".
 */
export function writeValue(step: StepNode, name: string, value: unknown): StepNode {
  const config: Record<string, unknown> = { ...(step.config as Record<string, unknown> ?? {}) };
  if (value === undefined || value === null || value === '') {
    delete config[name];
  } else {
    config[name] = value;
  }
  return { ...step, config };
}

/** Whether a live search should fire for the current query length. */
export function shouldSearch(field: NormalizedField, query: string): boolean {
  return field.optionMode === 'search' && query.length >= field.minChars;
}

/**
 * Keystroke-to-fetch delay for an options_search field, matching the install
 * form's search widget (250ms) so the two admin surfaces feel the same and a
 * fast typist costs one request instead of one per character.
 */
export const SEARCH_DEBOUNCE_MS = 250;

/**
 * The option list a search select renders: the fetched results PLUS the
 * persisted value as a real, selectable option. The bug this fixes is a
 * persisted value that lived on the `value=""` placeholder — re-selecting the
 * item the field was already showing silently cleared it. `label` is the
 * resolved label when one has been seen, else the bare id.
 */
export function optionsWithSelected(
  options: readonly ConfigFieldOption[],
  value: string,
  label?: string | null,
): ConfigFieldOption[] {
  if (value === '' || options.some((o) => o.value === value)) {
    return [...options];
  }
  return [...options, { value, label: label && label !== '' ? label : value }];
}

/** The fetched label for a value, or null when the results do not include it. */
export function labelForValue(
  options: readonly ConfigFieldOption[],
  value: string,
): string | null {
  if (value === '') {
    return null;
  }
  return options.find((o) => o.value === value)?.label ?? null;
}

// ---- multiselect (F6 `type: multiselect`) --------------------------------

/**
 * A multiselect's stored value -> the selected values. The runtime reads a
 * COMMA-SEPARATED STRING (AssignWebsites::parseWebsiteIds explodes on "," and
 * trims each piece), so that is the canonical stored shape; an array is
 * tolerated on read only, because a hand-edited definition or an older build
 * may carry one.
 */
export function parseMultiValue(value: unknown): string[] {
  const pieces = Array.isArray(value)
    ? value.map((v) => String(v ?? ''))
    : String(value ?? '').split(',');
  const out: string[] = [];
  for (const piece of pieces) {
    const trimmed = piece.trim();
    if (trimmed !== '' && !out.includes(trimmed)) {
      out.push(trimmed);
    }
  }
  return out;
}

/**
 * Selected values -> the stored value. Comma-joined with no spaces (the runtime
 * trims anyway, but the canonical form the notice advertises is "1,2"); an
 * empty selection returns '' so writeValue removes the key rather than
 * persisting an empty string.
 */
export function serializeMultiValue(values: readonly string[]): string {
  const seen: string[] = [];
  for (const value of values) {
    const trimmed = String(value ?? '').trim();
    if (trimmed !== '' && !seen.includes(trimmed)) {
      seen.push(trimmed);
    }
  }
  return seen.join(',');
}

/**
 * The option list a multiselect renders: the declared options plus any stored
 * value the metadata does not offer (a website that has since been deleted, a
 * hand-edited id), so rendering the control can never drop part of the value.
 */
export function multiSelectOptions(
  options: readonly ConfigFieldOption[],
  selected: readonly string[],
): ConfigFieldOption[] {
  const out = [...options];
  for (const value of selected) {
    if (!out.some((o) => o.value === value)) {
      out.push({ value, label: `${value} ${t('(not offered)')}` });
    }
  }
  return out;
}

/**
 * Chip-add for a multi search select: append one picked value to the stored
 * comma list. serializeMultiValue dedupes, so re-picking an already-selected
 * value is a no-op rather than a duplicate.
 */
export function addToMultiValue(stored: unknown, value: string): string {
  return serializeMultiValue([...parseMultiValue(stored), value]);
}

/** Chip-remove: drop one value from the stored comma list ('' drops the key). */
export function removeFromMultiValue(stored: unknown, value: string): string {
  return serializeMultiValue(parseMultiValue(stored).filter((v) => v !== value));
}

/**
 * The option list a multi search select offers for ADDING: the fetched results
 * minus the values already selected (those render as chips, not as choices).
 */
export function searchAddOptions(
  options: readonly ConfigFieldOption[],
  selected: readonly string[],
): ConfigFieldOption[] {
  return options.filter((o) => !selected.includes(o.value));
}

// ---- wait step: the trigger-event catalogue ------------------------------

/** The server's wait-event grammar (Definition: `config.event`). */
export const EVENT_NAME_PATTERN = /^[a-z0-9_.\-]{1,128}$/;

export function isValidEventName(value: unknown): boolean {
  return typeof value === 'string' && EVENT_NAME_PATTERN.test(value);
}

/**
 * The bootstrapped trigger catalogue (`config.triggers`, from Mount.php) as
 * grouped select options: one optgroup per trigger group, one option per
 * distinct event. The same event can be registered for several entities; the
 * wait step matches on the event name alone, so it is offered once, labelled
 * with the first registration's label.
 */
export function eventOptionGroups(
  triggers: readonly TriggerMeta[],
): { label: string; options: ConfigFieldOption[] }[] {
  const seen = new Set<string>();
  const groups: { label: string; options: ConfigFieldOption[] }[] = [];
  for (const group of groupTriggers([...triggers])) {
    const options: ConfigFieldOption[] = [];
    for (const trigger of group.triggers) {
      const event = String(trigger.event ?? '');
      if (event === '' || seen.has(event)) {
        continue;
      }
      seen.add(event);
      const label = String(trigger.label ?? '');
      options.push({ value: event, label: label === '' ? event : `${label} (${event})` });
    }
    if (options.length > 0) {
      groups.push({ label: group.label, options });
    }
  }
  return groups;
}

/** Whether an event name is one the catalogue offers. */
export function isCataloguedEvent(triggers: readonly TriggerMeta[], event: string): boolean {
  return event !== '' && triggers.some((t) => String(t.event ?? '') === event);
}

// ---- delay step: the store-local roll-forward time ----------------------

/** The server's `config.at` grammar (Definition::assertDelayExtras). */
export const TIME_OF_DAY_PATTERN = /^([01]\d|2[0-3]):[0-5]\d$/;

export function isValidTimeOfDay(value: unknown): boolean {
  return typeof value === 'string' && TIME_OF_DAY_PATTERN.test(value);
}
