/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

import { t } from './i18n';
import type { StepNode, SwitchCase } from './types';

/**
 * WHERE a condition tree lives. The slide-out is one editor over three
 * different homes, and the bug this descriptor fixes is that the editor used to
 * know only a step key — so a switch step's tree was written to the STEP, which
 * the executor never reads (Executor.php reads `cases[]` only) and the schema
 * rejects (`switchStep` is additionalProperties:false).
 *
 *  - `step`     : a branch step's own `conditions_serialized`;
 *  - `case`     : `cases[i].conditions_serialized` of a switch step;
 *  - `workflow` : the workflow-level root tree (WorkflowMeta.conditionsSerialized,
 *                 round-tripped through saveClient's `conditions_serialized`).
 *
 * Pure step-level reads/writes live here so the routing is pinned by vitest
 * instead of hiding inside a component handler.
 */
export type ConditionTarget =
  | { scope: 'step'; stepKey: string }
  | { scope: 'case'; stepKey: string; caseIndex: number; caseKey: string }
  | { scope: 'workflow' };

/** Slide-out heading text for a target. */
export function targetTitle(target: ConditionTarget): string {
  if (target.scope === 'workflow') {
    return t('Workflow conditions');
  }
  if (target.scope === 'case') {
    return `${t('Conditions')} — ${target.stepKey} / ${t('case')} "${target.caseKey}"`;
  }
  return `${t('Conditions')} — ${target.stepKey}`;
}

/** The tree currently stored at a step-scoped target (null = always run). */
export function readTargetConditions(
  step: StepNode | null,
  target: ConditionTarget,
): string | null {
  if (!step || target.scope === 'workflow') {
    return null;
  }
  if (target.scope === 'case') {
    const found = findCase(step, target.caseIndex, target.caseKey);
    const value = found?.conditions_serialized;
    return typeof value === 'string' ? value : null;
  }
  return typeof step.conditions_serialized === 'string' ? step.conditions_serialized : null;
}

/**
 * Branch and switch steps carry the shared `revalidate_entity` flag
 * (docs/06-conditions.md: one flag per step; a switch hydrates once and
 * evaluates N cases). Nothing else does.
 */
export function supportsRevalidate(step: StepNode | null): boolean {
  return step !== null && (step.type === 'branch' || step.type === 'switch');
}

/** The effective flag: an ABSENT key means true (the executor's default). */
export function readRevalidate(step: StepNode | null): boolean {
  return step?.revalidate_entity !== false;
}

/**
 * Write a tree (and the shared revalidate flag) into the step the target names.
 * Returns a new step; never mutates.
 *
 * `revalidate` is null when the control was not offered (non-branch/switch).
 * When offered, the key is written explicitly if the step already carried one
 * (preserving the author's explicitness), and otherwise only when it is false —
 * so applying conditions never injects a redundant `revalidate_entity: true`
 * into a definition that relied on the default.
 */
export function applyTargetConditions(
  step: StepNode,
  target: ConditionTarget,
  conditionsSerialized: string | null,
  revalidate: boolean | null = null,
): StepNode {
  const next: StepNode = { ...step };

  if (target.scope === 'case') {
    const cases = Array.isArray(step.cases) ? step.cases : [];
    const index = resolveCaseIndex(cases, target.caseIndex, target.caseKey);
    if (index >= 0) {
      next.cases = cases.map((c, i) =>
        i === index ? { ...c, conditions_serialized: conditionsSerialized } : c,
      );
    }
  } else if (target.scope === 'step') {
    next.conditions_serialized = conditionsSerialized;
  }

  // A switch step's tree lives on its cases; a step-level key there is dead
  // weight the schema rejects. Strip it whenever we touch a switch step.
  if (next.type === 'switch') {
    delete next.conditions_serialized;
  }

  if (revalidate !== null && supportsRevalidate(next)) {
    if (Object.prototype.hasOwnProperty.call(step, 'revalidate_entity') || revalidate === false) {
      next.revalidate_entity = revalidate;
    }
  }

  return next;
}

function findCase(step: StepNode, caseIndex: number, caseKey: string): SwitchCase | null {
  const cases = Array.isArray(step.cases) ? step.cases : [];
  const index = resolveCaseIndex(cases, caseIndex, caseKey);
  return index >= 0 ? cases[index] : null;
}

/**
 * Prefer the index, but verify the key still matches — a case list edited
 * between opening and applying (rename/reorder/remove) must not write the tree
 * into somebody else's case.
 */
function resolveCaseIndex(cases: SwitchCase[], caseIndex: number, caseKey: string): number {
  if (caseIndex >= 0 && caseIndex < cases.length && cases[caseIndex]?.key === caseKey) {
    return caseIndex;
  }
  return cases.findIndex((c) => c.key === caseKey);
}
