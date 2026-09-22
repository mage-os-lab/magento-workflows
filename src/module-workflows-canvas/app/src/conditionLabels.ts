/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

import type { NodeMeta } from './conditionTree';

/**
 * Attribute code → merchant label registry for condition summaries.
 *
 * Node faces and the trigger card render compact condition summaries at
 * mount, long before any condition editor opens — but the friendly labels
 * ("Grand Total", not `grand_total`) live in the lazily-fetched condition
 * metadata. The editor/viewer fetch the entity root's metadata once (cached
 * by conditionsClient anyway), feed the label map here, and re-render;
 * until then — and forever on installs where the fetch fails — summaries
 * degrade to the raw attribute codes, never to broken UI.
 *
 * Module-level registry on the i18n `t()` precedent: summaries are produced
 * deep inside pure graph code where threading a label map through every call
 * site would couple the whole graph model to the metadata transport.
 */
let labels: Record<string, string> = {};

export function setConditionAttributeLabels(map: Record<string, string>): void {
  labels = map;
}

/** Test seam / page-session reset. */
export function resetConditionAttributeLabels(): void {
  labels = {};
}

export function conditionAttributeLabel(code: string): string {
  return labels[code] ?? code;
}

/**
 * Harvest `FQCN|attribute` composite options from a combine's "Add condition"
 * groups: their labels are exactly the per-attribute merchant labels.
 */
export function buildAttributeLabelMap(meta: NodeMeta | null): Record<string, string> {
  const map: Record<string, string> = {};
  for (const group of meta?.new_children ?? []) {
    for (const option of group.options ?? []) {
      const pipe = option.value.indexOf('|');
      if (pipe > 0) {
        const code = option.value.slice(pipe + 1);
        if (code !== '' && map[code] === undefined) {
          map[code] = option.label;
        }
      }
    }
  }
  return map;
}
