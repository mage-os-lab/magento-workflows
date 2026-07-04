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
| Edge-routing helper (F1) | `Model/Definition/Definition` | One map of step-type → ordered edge fields; `Executor::walk`'s switch statement and `PlainLanguageRenderer`'s edge logic both re-read from it |
| `GraphCheck` (F2) | `Model/Validation/Check/` | DFS from `entry` over the edge helper: cycle reachable from entry = **error**; unreachable step = warning; both-edges-null branch/switch = warning; post-delay branch with `revalidate_entity:false` = warning |
| `switch` parsing | `Model/Definition/Definition` | `cases[]` (key, conditions_serialized, next), `default`, step-level `revalidate_entity`; schema-3 gate exactly like the existing wait/schema-2 gate |
| `runSwitchStep` | `Model/Engine/Executor` | Loop `ConditionEvaluator::evaluateSerialized` over cases (one hydration via the fresh-flag, evaluated N times), first match wins, `default` fallback; step result records `{matched: <key>}`; step row written before edge-follow — identical persistence discipline to `runBranchStep` |
| Plain-language upgrades | core (relocated per F6) | Render both branch edges (indented "otherwise: …"), switch cases, wait steps; "always" for empty branch conditions; consumed by grid, edit-form preview block, validate endpoint |
| Form preview + warnings | `module-workflows-admin-ui` | Edit form gains a plain-language preview block and renders `ValidationResult` warnings next to the JSON editor (errors already block save) |
| Spec v3 + fixtures | `spec/` | Switch schema, multi-region-routing fixture, CHANGELOG entry |

## Stages

| # | Stage | Notes |
|---|---|---|
| 1 | Edge helper + refactor `Executor::walk` / `PlainLanguageRenderer` onto it (behavior-neutral) | Pure refactor PR; existing tests prove neutrality |
| 2 | F2 pipeline skeleton + `GraphCheck` wired into Save/REST/import | New saves validated; existing stored definitions untouched (validator never runs in the executor) |
| 3 | PlainLanguageRenderer relocation + both-edge/wait rendering + form preview | Includes the F6 relocation |
| 4 | `switch`: parser + schema-3 gate + `runSwitchStep` + spec/fixture + GraphCheck awareness | The only PR that changes the published format |
| 5 | Docs: 04/06/08 updates, spec changelog | |

## Tests

Edge-helper table test (every step type × every edge); GraphCheck suite (cycle, self-loop,
unreachable, dead-edge, the degenerate assembler output — the both-null last-row branch the
form can produce today must surface as a warning, not an error, or existing form-built workflows
fail to re-save); switch executor suite (first-match, default, no-match-no-default = graph end,
matched-case persistence); schema-gate tests mirroring the existing wait/schema-2 suite.

## Compatibility notes

- Re-saving an existing workflow must never get *harder* except for genuine cycles: everything
  the current form/assembler can produce maps to warnings, not errors. This is the acceptance
  bar for stage 2.
- `switch` inert until authored; no config flag needed.
- Executor continues parsing schema-1/2 snapshots untouched; schema-3 snapshots only exist after
  stage 4 saves.
