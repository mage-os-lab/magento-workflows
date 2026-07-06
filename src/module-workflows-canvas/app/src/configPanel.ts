import type { ConfigField, ConfigFieldOption, PaletteAction, StepNode } from './types';

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
