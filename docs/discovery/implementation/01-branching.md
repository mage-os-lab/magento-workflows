# 01 — Branching: Implementation Plan

**Discovery:** [branching.md](../branching.md) · **Foundations used:** F1, F2, F6
**Modules touched:** `module-workflows`, `module-workflows-admin-ui`, `spec/`

## Intent

Ship graph safety and the `switch` step as the engine-side prerequisites for everything
downstream (dry-run walks validated graphs; canvas renders switch; gallery installs through the
validator). No executor architecture changes; `switch` is a sibling of `branch`, evaluated
inline, one outgoing edge chosen per visit.

## Components

| Component | Home | Intent |
|---|---|---|
| Edge-routing helper (F1) | `Model/Definition/Definition` | `getStepEdges(stepKey)` per the F1 pinned signature — **topology only**, read from step data. Refactor onto it: the edge-selection *returns* inside `Executor`'s `run*Step` methods (note: `walk()`'s own switch dispatches by step *type* — the edge logic lives in the callees), `ResumeConsumer`'s `on_event`/`on_timeout` routing (the only runtime follower of wait edges — easy to miss), and `PlainLanguageRenderer` |
| `GraphCheck` (F2) | `Model/Validation/Check/` | DFS from `entry` over the edge helper: cycle reachable from entry = **error**; unreachable step = warning; both-edges-null branch/switch = warning (**deliberate override of discovery §2**, which classed the no-conditions variant an error — the shipped form assembler can produce exactly that shape as a last-row branch, and re-save compatibility wins; discovery doc updated to match); post-delay branch with `revalidate_entity:false` = warning. Message codes and `target.step_key` per the F2 `ValidationMessage` shape |
| `switch` parsing | `Model/Definition/Definition` | `cases[]` (key, conditions_serialized, next), `default`, step-level `revalidate_entity`; schema-3 gate exactly like the existing wait/schema-2 gate. The full mechanical edit list a literal implementer needs: bump `SCHEMA_VERSION`/`SCHEMA_VERSIONS`; add `STEP_SWITCH` to `STEP_TYPES`; **extend the dangling-edge validation loop** (it checks a flat field list today — `cases[].next` and `default` are not in it and would silently skip validation); teach `getStepEdges` the case edges; preserve the top-level `ui` block through `fromArray()`/`toArray()` (F1 — ships here, exercised by the ui round-trip fixture); bump the `enum` in `spec/workflow-definition.schema.json` |
| `runSwitchStep` | `Model/Engine/Executor` | Loop `ConditionEvaluator::evaluateSerialized` over cases (one hydration via the fresh-flag, evaluated N times), first match wins, `default` fallback; step result records `{matched: <key>}`; step row written before edge-follow — identical persistence discipline to `runBranchStep` |
| Plain-language upgrades | core (relocated per F6) | Render both branch edges (indented "otherwise: …"), switch cases, wait steps; "always" for empty branch conditions; consumed by grid, edit-form preview block, validate endpoint |
| `POST /V1/workflows/validate` | `module-workflows` webapi | Built here (stage 2), per the F6 endpoint-ownership table: runs the F2 pipeline on a posted definition + conditions, returns `ValidationMessage[]` + plain-language rendering |
| Form preview + warnings | `module-workflows-admin-ui` | A container after the `definition` field in the form's `actions` fieldset: plain-language preview server-rendered on load, with a "Refresh preview" action posting the textarea content to the validate endpoint (no live-keystroke JS in this stage). `ValidationResult` warnings render in the same block, anchored by `target.step_key`; errors already block save |
| Spec v3 + fixtures | `spec/` | Switch schema, multi-region-routing fixture, CHANGELOG entry |

## Stages

| # | Stage | Notes / done-when |
|---|---|---|
| 1 | Edge helper + refactor `Executor` `run*Step` edge returns, `ResumeConsumer`, `PlainLanguageRenderer` onto it (behavior-neutral) | Pure refactor PR. Done when: existing suites green unchanged; the helper is the only place edge fields are named outside `Definition` validation |
| 2 | F2 pipeline skeleton + `GraphCheck` + repository-plugin wiring (Save/REST/import) + `POST /V1/workflows/validate` | Done when: REST contract tests pass (invalid → 400, unauthorized action → 403 — this is the deliberate REST tightening F2 documents); both spec fixtures save cleanly; status-only saves skip validation (`isDefinitionChanged` guard) |
| 3 | PlainLanguageRenderer relocation + both-edge/wait rendering + form preview block | Done when: grid column output unchanged for existing workflows; preview renders both fixtures correctly. Also here: flip the form assembler's post-delay branch `revalidate_entity` default to `true` (aligns the shipped default with [06 §Delay semantics](../../06-conditions.md#delay-semantics); today the assembler hardwires false, which would make every form-built delay→branch fire the stale-revalidation warning) |
| 4 | `switch`: parser (full mechanical list above) + `runSwitchStep` + `ui` preservation + spec v3 + fixtures | The only PR that changes the published format. Done when: switch fixture executes through the shadow-status path and validates against the published schema; ui fixture round-trips byte-for-byte |
| 5 | Docs: 04/06/08 updates, spec changelog | |

## Tests

Edge-helper table test (every step type × every edge); GraphCheck suite (cycle, self-loop,
unreachable, dead-edge, the degenerate assembler output — the both-null last-row branch the
form can produce today must surface as a warning, not an error, or existing form-built workflows
fail to re-save); switch executor suite (first-match, default, no-match-no-default = graph end,
matched-case persistence); schema-gate tests mirroring the existing wait/schema-2 suite.

## Compatibility notes

- The stage-2 acceptance bar, precisely scoped: **`GraphCheck` never turns a currently-savable
  definition into an unsavable one** — a genuine cycle (which the assembler cannot produce) is
  the only new error on previously-valid input. Note the bar is about GraphCheck, not the whole
  pipeline: an empty/unknown action code already errors today via `authorizeActionCodes`, and
  stays an error.
- `switch` inert until authored; no config flag needed.
- Executor continues parsing schema-1/2 snapshots untouched; schema-3 snapshots only exist after
  stage 4 saves.
