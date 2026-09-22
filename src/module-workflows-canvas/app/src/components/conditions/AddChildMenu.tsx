/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

import { useState } from 'react';
import type { MetaOptionGroup } from '../../conditionTree';
import { t } from '../../i18n';

/**
 * The "⊕ Add condition" affordance of a combine row. The stock rule widget uses
 * a hover/JS-driven chooser; this is a grouped <select> plus an explicit Add
 * button, which is keyboard-reachable and screen-reader-legible for free and
 * needs no popup/focus-trap machinery.
 *
 * Groups and option values come verbatim from the server's
 * `getNewChildSelectOptions()` projection — values are `FQCN` or
 * `FQCN|attribute` composites, split by conditionTree.splitTypeSpec, so a
 * third-party condition class appears here with no client change.
 */
interface Props {
  groups: MetaOptionGroup[];
  /** Row identity, only used to keep the select's accessible name unique. */
  contextLabel: string;
  readOnly: boolean;
  onAdd: (spec: string) => void;
}

export function AddChildMenu({ groups, contextLabel, readOnly, onAdd }: Props): JSX.Element | null {
  const [spec, setSpec] = useState('');

  if (groups.length === 0) {
    return null;
  }

  const add = (): void => {
    if (spec !== '') {
      onAdd(spec);
      setSpec('');
    }
  };

  return (
    <span className="wf-cond__add">
      <select
        className="wf-cond__add-select"
        aria-label={`${t('Add a condition to')} ${contextLabel}`}
        value={spec}
        disabled={readOnly}
        onChange={(e) => setSpec(e.target.value)}
      >
        <option value="">{t('— add condition —')}</option>
        {groups.map((group, gi) => (
          <optgroup key={`${group.label}-${gi}`} label={group.label}>
            {(group.options ?? []).map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </optgroup>
        ))}
      </select>
      <button
        type="button"
        className="wf-cond__add-button"
        disabled={readOnly || spec === ''}
        onClick={add}
      >
        {t('Add')}
      </button>
    </span>
  );
}
