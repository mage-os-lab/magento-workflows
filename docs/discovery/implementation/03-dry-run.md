# 03 — Dry-Run: Implementation Plan

**Discovery:** [dry-run.md](../dry-run.md) · **Foundations used:** F1 (edge helper), F2, F6, F7, F8 (`mode`)
**Modules touched:** `module-workflows`, `module-workflows-admin-ui`

## Intent

A synchronous, side-effect-free walker that answers "what would this do to entity X right now",
reusing every production semantic component (evaluator, resolver, delay math, `simulate()`)
while owning only routing, time compression, and trace assembly. The production executor is not
modified. The two-walker divergence risk is contained structurally: shared edge helper (F1) +
a dual-engine conformance suite.

## Components

| Component | Home | Intent |
|---|---|---|
| `DryRunService` | `Model/DryRun/` | Entry point: takes (definition JSON + conditions + entity ref \| synthetic payload), runs the F2 pipeline first with the **dry-run check subset** — Structural + Graph + ActionCodes + ConditionsShape, explicitly **excluding** `ActionAuthorizationCheck` (dry-run is not an authoring path; a `::dry_run` holder lacking one action-authoring resource must still be able to preview) — a broken graph returns validation results, not a trace; then builds a simulation `ExecutionContext` and walks |
| Walker + `PathExplorer` | `Model/DryRun/` | Iterative walk over the F1 edge helper (`getStepEdges`); delays/waits annotate instead of park; waits and failed branch evaluations fan out to all edges; cap on **distinct step visits** with rejoin detection (shared tails render once) |
| `Trace` / `TraceStep` DTOs | `Model/DryRun/` | `Trace = {workflow: {id?, name}, entity: {type, id?}, validation: ValidationMessage[], steps: TraceStep[]}`. `TraceStep = {step_key: string, type: string, status: TraceStepStatus, path_ids: string[], would: ?string, config: array (interpolated, secrets redacted), condition: ?{serialized: string, result: bool, revalidated: bool}, timing: ?{resume_at: string, clamped: bool, timezone: string}, edge_taken: ?string, notes: string[]}`. `TraceStepStatus enum: would_run \| would_fail \| skipped \| production_stops_here`. The trace is a **flat ordered list**; fan-out is encoded by `path_ids` (a rejoined shared tail is emitted once, carrying every contributing path id) — renderers rebuild the tree from that |
| Redaction (F7) | `Model/Secrets/` | The named virtualType `VariableResolverForDryRun` bound to `RedactingSecretsProvider` (the resolver already takes its provider by DI — do **not** touch the shared production instance); the one sanctioned deviation from share-everything |
| Conformance suite (two layers) | `Test/Unit` now; `Test/Integration` gated | **The unit harness cannot run the real Executor** — it persists via repository saves + raw `ResourceConnection` SQL, and zero Executor tests exist today. So: **layer 1 (ships with this plan)** — table-driven routing-equivalence tests asserting `DryRunService` edge selection per step type/outcome against expectations derived from the same fixtures, both walkers reading `getStepEdges` (the shared-helper design is itself the primary divergence control); **layer 2 (written now, executed at the live-install milestone that already gates GA)** — the full dual-engine suite: dispatch each fixture through the real DB-backed executor in shadow status and diff the step sequence against the DryRunService path. The plan accepts that layer 2 waits on the integration harness rather than modifying the executor to make it unit-runnable |
| CLI | `Console/Command/RunCommand` | `--dry-run` flag → DryRunService (injected *alongside* the dispatcher; the non-flag path is untouched). **Saved workflows by id only** — unsaved-definition dry-run is REST/admin surface, not CLI. Trace renders as a console table: step_key, type, status, would/summary, edge_taken |
| REST | `etc/webapi.xml` | `POST /V1/workflows/dry-run` (definition + entity ref or `trigger_payload` for CI-synthetic runs, labeled snapshot-only fidelity) and `POST /V1/workflows/:id/dry-run`. Route shapes are deliberately non-colliding with `GET /V1/workflows/:workflowId` (distinct method + segment depth); add a contract test pinning that `GET …/dry-run` falls through to `getById('dry-run')` → 404, so nobody "fixes" it into a collision later |
| ACL | `module-workflows-admin-ui/etc/acl.xml` | The core module has **no acl.xml** — declare `MageOS_Workflows::dry_run` under the existing `MageOS_Workflows::workflows` parent (title "Dry-Run Workflows"), referenced from the core webapi routes exactly as `::view`/`::manage` already are. Granted alongside `::manage` in role defaults; must **not** imply `::manual_run` |
| Admin UI | admin-ui | "Dry run" button on the edit form posting the *currently edited* JSON; entity picker backed by a per-entity-type `RecentEntityProvider` contract (`getRecent(entityType, limit): [{id, label}]` — default: most recent by created/updated, limit 20, **no condition filtering in v1**; new entity types register a provider); trace panel rendering (plain-language labels, failure lines with technical-details expander) |
| Persistence (F8) | core | **Default ON for admin dry-runs of saved workflows** (`mageos_workflows/dry_run/persist`, default 1 — this was decided in discovery; restated here so it isn't re-opened): execution row `mode='dry_run'`, steps from trace, pruned at `mageos_workflows/dry_run/retention_days` (default 7) by extending the existing `PruneExecutions` cron. Unsaved runs stay transient (NOT-NULL `workflow_id` FK — accepted constraint) |

## Stages

| # | Stage | Notes / done-when |
|---|---|---|
| 1 | `DryRunService` + walker + trace DTOs + redaction virtualType + unit tests (linear/branch/delay) | Requires **01 stage 2** (F2 pipeline — DryRunService runs the check subset), not just stage 1. Done when: both spec fixtures produce correct traces with a synthetic payload; secret-in-config renders redacted |
| 2 | Wait/switch fan-out, rejoin dedupe, branch-eval-failure both-edges rule, delay annotation polish | Semantics from discovery §4. Done when: wait fixture yields both paths with a shared-tail emitted once |
| 3 | Conformance suite: layer 1 (routing-equivalence, unit) + layer 2 authored (integration, gated) | Done when: layer 1 covers every step type × edge outcome; layer 2 exists, is documented as gated on the live-install milestone, and is wired to run there |
| 4 | CLI flag + REST endpoints + ACL + contract tests | Done when: `--dry-run` on a fixture-imported workflow prints the table; REST contract tests (incl. the GET-fallthrough pin) pass |
| 5 | Admin UI: button, entity picker (`RecentEntityProvider`), trace panel | Done when: unsaved-edit dry-run round-trips from the form without saving |
| 6 | `mode` column + default-on persistence + TTL prune + execution-view rendering of dry-run rows | Kept last so 1–5 don't wait on schema review |

## Tests

Walker table tests per step type × edge outcome; time-compression math delegates to existing
`DelayCalculator` suites (assert delegation, don't re-test math); redaction test (secret in
webhook URL config → `***name***` in trace, never the value); path-cap and rejoin tests; the
conformance suite as the keystone; REST contract tests for both endpoints incl. the synthetic-
payload mode.

## Guardrails (explicit "do not"s for the implementer)

- Do **not** add a per-execution simulation flag to `Executor` or call `Executor::execute()`
  from dry-run — that is discovery §3's rejected Approach A, and the executor's config
  interpolation runs *before* its simulation branch, so routing through it leaks real secrets.
- Do **not** modify `runBranchStep`/`runActionStep`/`runWaitStep` for this feature.
- Do **not** rebind the shared production `VariableResolver` — the redacting provider lives only
  in the named virtualType.
- Do **not** run `ActionAuthorizationCheck` in the dry-run pipeline subset.

## Compatibility & follow-ons

- Zero production-path changes; the only shared-code edit is the F1 edge helper
  (behavior-neutral refactor owned by 01).
- Shadow-mode redaction config (F7) ships here default-off; flip later.
- Extension points consumed by later plans: fan-out adds "would dispatch ~N children (sample)"
  trace nodes ([04](04-fan-out.md)); batch adds synthetic-batch fabrication
  ([05](05-batch-aggregation.md)); gallery calls DryRunService post-install
  ([06](06-template-gallery.md)); canvas overlays the trace on the graph ([07](07-canvas.md)).
  Each extends the *trace*, not the walker's contract.
