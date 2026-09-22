/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

import { Fragment } from 'react';
import type { AttributeMeta, MetaOption } from '../../conditionTree';
import {
  booleanValueOptions,
  optionSections,
  optionsWithCurrent,
  relativeDateHint,
} from '../../conditionTree';
import { t } from '../../i18n';

/**
 * The typed value control of a leaf condition row, driven entirely by the
 * server's per-attribute metadata (`value_element` / `input_type` /
 * `value_options`) — the client hardcodes no attribute knowledge.
 *
 *  select      : the served option list (plus the persisted value if the server
 *                did not offer it, so a template's value is never dropped);
 *  multiselect : the same list as a real multi-select, serialized as an array
 *                exactly as Combine::asArray() stores multiselect values;
 *  boolean     : Yes/No (values '1'/'0');
 *  date        : a TEXT input, not <input type="date"> — date conditions
 *                legitimately hold relative expressions ("-30 days",
 *                AbstractWorkflowCondition::RELATIVE_DATE_PATTERN) that a
 *                native date picker cannot represent, so the hint is shown
 *                instead of narrowing the input;
 *  otherwise   : text (numeric input types included — comma lists for `()`
 *                operators must stay typeable).
 *
 * Every control is labelled (aria-label, since the row is a compact grid with
 * no room for visible captions) and rendered as a React-controlled element:
 * server strings only ever land as text nodes / attribute values.
 */
interface Props {
  label: string;
  value: unknown;
  attributeMeta?: AttributeMeta | undefined;
  readOnly: boolean;
  onChange: (value: unknown) => void;
}

export function ValueControl({ label, value, attributeMeta, readOnly, onChange }: Props): JSX.Element {
  const element = attributeMeta?.value_element ?? 'text';
  const inputType = attributeMeta?.input_type ?? 'string';
  const served: MetaOption[] = attributeMeta?.value_options ?? [];

  if (element === 'multiselect' || inputType === 'multiselect') {
    const selected = toArray(value).map((v) => String(v));
    const options = served.length > 0 ? served : [];
    return (
      <select
        className="wf-cond__value wf-cond__value--multi"
        multiple
        aria-label={label}
        value={selected}
        disabled={readOnly}
        onChange={(e) =>
          onChange(Array.from(e.target.selectedOptions).map((o) => o.value))
        }
      >
        {renderSections(mergeSelected(options, selected))}
      </select>
    );
  }

  if (element === 'select' || inputType === 'select' || inputType === 'boolean') {
    const current = value === undefined || value === null ? '' : String(value);
    const base = served.length > 0 ? served : inputType === 'boolean' ? booleanValueOptions() : [];
    return (
      <select
        className="wf-cond__value"
        aria-label={label}
        value={current}
        disabled={readOnly}
        onChange={(e) => onChange(e.target.value)}
      >
        <option value="">{t('— select —')}</option>
        {renderSections(optionsWithCurrent(base, current))}
      </select>
    );
  }

  const text = value === undefined || value === null ? '' : String(value);
  const isDate = element === 'date' || inputType === 'date';
  return (
    <span className="wf-cond__value-wrap">
      <input
        type="text"
        className="wf-cond__value"
        aria-label={label}
        aria-describedby={isDate ? 'wf-cond-date-hint' : undefined}
        value={text}
        disabled={readOnly}
        spellCheck={false}
        onChange={(e) => onChange(e.target.value)}
      />
      {isDate && (
        <span className="wf-cond__hint" id="wf-cond-date-hint">
          {relativeDateHint()}
        </span>
      )}
    </span>
  );
}

function toArray(value: unknown): unknown[] {
  if (Array.isArray(value)) {
    return value;
  }
  if (value === undefined || value === null || value === '') {
    return [];
  }
  // A comma list is how the same field arrives from a hand-written tree.
  return String(value).split(',').map((part) => part.trim());
}

/** Grouped rows render under <optgroup> headings; ungrouped rows render bare. */
function renderSections(options: MetaOption[]): JSX.Element[] {
  return optionSections(options).map((section, i) => {
    const rows = section.options.map((o) => (
      <option key={o.value} value={o.value}>
        {o.label}
      </option>
    ));
    return section.group !== null ? (
      <optgroup key={`${section.group}-${i}`} label={section.group}>
        {rows}
      </optgroup>
    ) : (
      <Fragment key={`bare-${i}`}>{rows}</Fragment>
    );
  });
}

/** Keep every selected value selectable, even one the server did not offer. */
function mergeSelected(options: MetaOption[], selected: string[]): MetaOption[] {
  const merged = [...options];
  for (const value of selected) {
    if (!merged.some((o) => o.value === value)) {
      merged.push({ value, label: `${value} ${t('(not offered)')}` });
    }
  }
  return merged;
}
