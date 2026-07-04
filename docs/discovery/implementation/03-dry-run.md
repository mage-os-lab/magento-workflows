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
| `DryRunService` | `Model/DryRun/` | Entry point: takes (definition JSON + conditions + entity ref \| synthetic payload), runs F2 validation first (a broken graph returns validation results, not a trace), builds a simulation `ExecutionContext`, walks |
| Walker + `PathExplorer` | `Model/DryRun/` | Iterative walk over the F1 edge helper; delays/waits annotate instead of park; waits and failed branch evaluations fan out to all edges; cap on **distinct step visits** with rejoin detection (shared tails render once) |
| `Trace` / `TraceStep` DTOs | `Model/DryRun/` | Per step: type, status, `would` text, interpolated config (secrets redacted), condition result + serialized tree, resume-time annotations (real `DelayCalculator`, store tz, clamp notes), path/edge annotations ("production would follow the false edge here — entity could not be re-loaded") |
| Redaction (F7) | `Model/Secrets/RedactingSecretsProvider` | Injected into the dry-run resolver wiring; the one sanctioned deviation from share-everything |
| Dual-engine conformance suite | `Test/Unit` + `spec/fixtures/` | Per fixture + synthetic payload: step sequence from the real executor (shadow status, existing harness) must equal the DryRunService path; fails loudly when a step type lands in one walker only |
| CLI | `Console/Command/RunCommand` | `--dry-run` flag → DryRunService, trace as console table; without the flag, behavior unchanged |
| REST | `etc/webapi.xml` | `POST /V1/workflows/dry-run` (definition + entity ref or `trigger_payload` for CI-synthetic runs, labeled snapshot-only fidelity) and `POST /V1/workflows/:id/dry-run`; new ACL `MageOS_Workflows::dry_run` |
| Admin UI | admin-ui | "Dry run" button on the edit form posting the *currently edited* JSON; entity picker (small recent-matching-entities query per entity type); trace panel rendering (plain-language labels, failure lines with technical-details expander) |
| Optional persistence (F8) | core | Saved-workflow runs only: execution row `mode='dry_run'`, steps from trace, 7-day TTL prune; unsaved runs stay transient (NOT-NULL `workflow_id` FK — accepted constraint) |

## Stages

| # | Stage | Notes |
|---|---|---|
| 1 | `DryRunService` + walker + trace DTOs + redaction wiring + unit tests (linear/branch/delay) | Core value; no UI |
| 2 | Wait/switch fan-out, rejoin dedupe, branch-eval-failure both-edges rule, delay annotation polish | Semantics from discovery §4 |
| 3 | Dual-engine conformance suite | Requires 01 stage 1 (edge helper) landed |
| 4 | CLI flag + REST endpoints + ACL | Agency/CI surface |
| 5 | Admin UI: button, entity picker, trace panel | The merchant surface |
| 6 | `mode` column + persistence + TTL prune + execution-view rendering of dry-run rows | Optional; keep last so 1–5 don't wait on schema review |

## Tests

Walker table tests per step type × edge outcome; time-compression math delegates to existing
`DelayCalculator` suites (assert delegation, don't re-test math); redaction test (secret in
webhook URL config → `***name***` in trace, never the value); path-cap and rejoin tests; the
conformance suite as the keystone; REST contract tests for both endpoints incl. the synthetic-
payload mode.

## Compatibility & follow-ons

- Zero production-path changes; the only shared-code edits are the F1 edge helper (behavior-
  neutral refactor in 01) and resolver wiring made injectable for the secrets decorator.
- Shadow-mode redaction config (F7) ships here default-off; flip later.
- Extension points consumed by later plans: fan-out adds "would dispatch ~N children (sample)"
  trace nodes ([04](04-fan-out.md)); batch adds synthetic-batch fabrication
  ([05](05-batch-aggregation.md)); gallery calls DryRunService post-install
  ([06](06-template-gallery.md)); canvas overlays the trace on the graph ([07](07-canvas.md)).
  Each extends the *trace*, not the walker's contract.
