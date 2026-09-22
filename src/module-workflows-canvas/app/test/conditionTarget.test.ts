import { describe, expect, it } from 'vitest';
import {
  applyTargetConditions,
  readRevalidate,
  readTargetConditions,
  supportsRevalidate,
  targetTitle,
  type ConditionTarget,
} from '../src/conditionTarget';
import { toDefinition, toGraph } from '../src/mapping';
import { makeConfig, makeGraph } from './support';
import type { Definition, StepNode } from '../src/types';

/**
 * The slide-out routing contract: a branch's tree is the STEP's, a switch's
 * trees belong to its CASES, and the workflow root tree is not a step at all.
 * Writing a switch's tree at step level was the bug — the executor reads
 * cases[] only and the schema rejects the extra key.
 */

const TREE = '{"type":"combine","aggregator":"all","value":"1","conditions":[]}';

function branchStep(overrides: Partial<StepNode> = {}): StepNode {
  return { type: 'branch', conditions_serialized: null, on_true: null, on_false: null, ...overrides };
}

function switchStep(overrides: Partial<StepNode> = {}): StepNode {
  return {
    type: 'switch',
    cases: [
      { key: 'high', conditions_serialized: TREE, next: null },
      { key: 'low', conditions_serialized: null, next: null },
    ],
    default: null,
    ...overrides,
  };
}

describe('conditionTarget — titles', () => {
  it('names the home of the tree being edited', () => {
    expect(targetTitle({ scope: 'workflow' })).toBe('Workflow conditions');
    expect(targetTitle({ scope: 'step', stepKey: 'gate' })).toBe('Conditions — gate');
    expect(targetTitle({ scope: 'case', stepKey: 'route', caseIndex: 0, caseKey: 'high' })).toBe(
      'Conditions — route / case "high"',
    );
  });
});

describe('conditionTarget — reads', () => {
  it('reads a branch step tree', () => {
    const step = branchStep({ conditions_serialized: TREE });
    expect(readTargetConditions(step, { scope: 'step', stepKey: 'gate' })).toBe(TREE);
  });

  it('reads the addressed switch case, not the step', () => {
    const step = switchStep();
    expect(
      readTargetConditions(step, { scope: 'case', stepKey: 'route', caseIndex: 0, caseKey: 'high' }),
    ).toBe(TREE);
    expect(
      readTargetConditions(step, { scope: 'case', stepKey: 'route', caseIndex: 1, caseKey: 'low' }),
    ).toBeNull();
  });

  it('recovers when the case list was reordered under it (key wins over index)', () => {
    const step = switchStep({
      cases: [
        { key: 'low', conditions_serialized: null, next: null },
        { key: 'high', conditions_serialized: TREE, next: null },
      ],
    });
    expect(
      readTargetConditions(step, { scope: 'case', stepKey: 'route', caseIndex: 0, caseKey: 'high' }),
    ).toBe(TREE);
  });

  it('never reads a step for the workflow-root target', () => {
    expect(readTargetConditions(branchStep({ conditions_serialized: TREE }), { scope: 'workflow' })).toBeNull();
  });
});

describe('conditionTarget — writes route per target', () => {
  it('branch -> step-level conditions_serialized', () => {
    const next = applyTargetConditions(branchStep(), { scope: 'step', stepKey: 'gate' }, TREE);
    expect(next.conditions_serialized).toBe(TREE);
  });

  it('switch case -> cases[i].conditions_serialized, and no step-level key', () => {
    const step = switchStep();
    const target: ConditionTarget = { scope: 'case', stepKey: 'route', caseIndex: 1, caseKey: 'low' };
    const next = applyTargetConditions(step, target, TREE);
    expect(next.cases?.[1].conditions_serialized).toBe(TREE);
    expect(next.cases?.[0].conditions_serialized).toBe(TREE);
    expect('conditions_serialized' in next).toBe(false);
    // Immutable: the source step and its cases are untouched.
    expect(step.cases?.[1].conditions_serialized).toBeNull();
  });

  it('strips a stray step-level tree from a switch step it touches', () => {
    const step = switchStep({ conditions_serialized: TREE });
    const next = applyTargetConditions(
      step,
      { scope: 'case', stepKey: 'route', caseIndex: 0, caseKey: 'high' },
      null,
    );
    expect('conditions_serialized' in next).toBe(false);
    expect(next.cases?.[0].conditions_serialized).toBeNull();
  });

  it('writes into the renamed/reordered case by key, never into the wrong one', () => {
    const step = switchStep({
      cases: [
        { key: 'low', conditions_serialized: null, next: null },
        { key: 'high', conditions_serialized: null, next: null },
      ],
    });
    const next = applyTargetConditions(
      step,
      { scope: 'case', stepKey: 'route', caseIndex: 0, caseKey: 'high' },
      TREE,
    );
    expect(next.cases?.[0].conditions_serialized).toBeNull();
    expect(next.cases?.[1].conditions_serialized).toBe(TREE);
  });

  it('leaves the step alone when the addressed case has vanished', () => {
    const step = switchStep();
    const next = applyTargetConditions(
      step,
      { scope: 'case', stepKey: 'route', caseIndex: 5, caseKey: 'gone' },
      TREE,
    );
    expect(next.cases?.map((c) => c.conditions_serialized)).toEqual([TREE, null]);
  });

  it('an empty tree clears the target to null ("always run")', () => {
    const next = applyTargetConditions(
      branchStep({ conditions_serialized: TREE }),
      { scope: 'step', stepKey: 'gate' },
      null,
    );
    expect(next.conditions_serialized).toBeNull();
  });
});

describe('conditionTarget — revalidate_entity (docs/06-conditions.md)', () => {
  it('is offered for branch and switch only', () => {
    expect(supportsRevalidate(branchStep())).toBe(true);
    expect(supportsRevalidate(switchStep())).toBe(true);
    expect(supportsRevalidate({ type: 'action', action: 'x' })).toBe(false);
    expect(supportsRevalidate(null)).toBe(false);
  });

  it('an ABSENT key reads as true (the executor default)', () => {
    expect(readRevalidate(branchStep())).toBe(true);
    expect(readRevalidate(branchStep({ revalidate_entity: true }))).toBe(true);
    expect(readRevalidate(branchStep({ revalidate_entity: false }))).toBe(false);
  });

  it('does not inject a redundant true into a step that relied on the default', () => {
    const next = applyTargetConditions(branchStep(), { scope: 'step', stepKey: 'g' }, TREE, true);
    expect('revalidate_entity' in next).toBe(false);
  });

  it('writes false explicitly', () => {
    const next = applyTargetConditions(branchStep(), { scope: 'step', stepKey: 'g' }, TREE, false);
    expect(next.revalidate_entity).toBe(false);
  });

  it('preserves an author who spelled the flag out', () => {
    const step = branchStep({ revalidate_entity: false });
    const next = applyTargetConditions(step, { scope: 'step', stepKey: 'g' }, TREE, true);
    expect(next.revalidate_entity).toBe(true);
  });

  it('is ignored when the control was not offered (null)', () => {
    const next = applyTargetConditions(branchStep(), { scope: 'step', stepKey: 'g' }, TREE, null);
    expect('revalidate_entity' in next).toBe(false);
  });
});

describe('mapping — a switch step never posts a step-level tree', () => {
  it('drops a stray conditions_serialized from a switch on the way out', () => {
    const def: Definition = {
      schema: 3,
      entry: 'route',
      steps: {
        route: {
          type: 'switch',
          // A key an older editor build (or a hand edit) could have left behind.
          conditions_serialized: TREE,
          cases: [{ key: 'a', conditions_serialized: TREE, next: 'end' }],
          default: 'end',
        },
        end: { type: 'stop' },
      },
    };
    const rebuilt = toDefinition(toGraph(def, makeConfig()));
    expect('conditions_serialized' in rebuilt.steps.route).toBe(false);
    expect(rebuilt.steps.route.cases?.[0].conditions_serialized).toBe(TREE);
    // A branch keeps its own.
    expect(rebuilt.steps.end.type).toBe('stop');
  });

  it('leaves branch trees untouched', () => {
    const def: Definition = {
      schema: 3,
      entry: 'gate',
      steps: {
        gate: { type: 'branch', conditions_serialized: TREE, on_true: 'end', on_false: 'end' },
        end: { type: 'stop' },
      },
    };
    const rebuilt = toDefinition(makeGraph(def));
    expect(rebuilt.steps.gate.conditions_serialized).toBe(TREE);
  });
});
