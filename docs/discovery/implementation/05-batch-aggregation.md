# 05 — Batch Aggregation: Implementation Plan

**Discovery:** [batch-aggregation.md](../batch-aggregation.md) · **Foundations used:** F2 (profile check), F4, F8 (batch tables, `aggregation` column, `entity_id=0`)
**Modules touched:** `module-workflows`, `module-workflows-scheduler`, `module-workflows-admin-ui`

## Intent

Two deliverables sharing one restricted "aggregated workflow" profile: **B1** (scheduler
collected-mode — cheap, ships first, validates the context shape and rendering story) and
**B2** (event-window accumulator — the real infrastructure). The executor learns exactly one
thing: tolerate `entity_id = 0` executions. All other batch-awareness concentrates in save-time
validation and the dispatch layer.

## Components

| Component | Home | Intent |
|---|---|---|
| `aggregation` column (F8) | `mageos_workflow` | Nullable JSON: `{mode: collected\|window, window: {…}, item_cap, min_items, projection: [fields], aggregate_suppressed_events}`; non-null = aggregated workflow (the "kind" derivation — no enum column) |
| Batch profile check | F2 `Check/` | For aggregated workflows: step-type allowlist (action/delay/branch-on-trigger-data/switch/stop — no wait, no `revalidate_entity`); actions must declare `supportsBatch()` (new `ActionMetadataInterface` default-false method, overridden true by email/webhook/admin-notify/set-variable); root conditions must classify fully `in_snapshot` (F4 classifier, first production wiring) — merchant-readable errors throughout |
| Batch context shape | convention (F8) | `trigger = {batch:true, count, window:{from,to}, overflow, items:[…]}`; documented in docs/04 |
| **B1** collected mode | `module-workflows-scheduler/Model/QueryRunner` | New branch: accumulate projections instead of per-match dispatch; route projections through `EntityDataConverter` (item-shape parity with B2 — review finding); one dispatch with the batch context; watermark/cap semantics unchanged, `item_cap` + `overflow` on top |
| Collection formatters | `Model/Variable/VariableResolver` | Whitelist additions: `count`, `pluck:'field'`, `join:', '`, `table:'f1,f2'` (escaped cells), `json`; same fixed-list, no-execution discipline |
| Batch rendering | admin-ui + core renderer | Execution grid/view render "batch (143 items)" instead of an entity link; plain language for aggregated workflows ("once per day, for everything that…") |
| **B2** accumulator | `Model/Engine/` + F8 tables | Dispatcher branch at the suppression guard (bypass when `aggregate_suppressed_events`); snapshot-only membership evaluation + `INSERT…ON DUPLICATE` item; batch row per `(workflow_id, window_key)` |
| Flush sweep | rides the resume sweeper cadence | Claim `open→flushing` (conditional UPDATE); **write-before-publish**: create execution, record its id on the batch row, publish, stamp `flushed` — retry path re-publishes the recorded execution, never creates a second (the time-bucket debounce provably can't dedupe this) |
| Window policies | `Model/Engine/` (batch) | `schedule` (cron in an explicit store timezone — reuse scheduler tz resolution) and `interval` (close `PT…` after first event); quiet-period deferred |
| TTL pruning | existing retention cron | Flushed batches + items pruned with execution-context retention (docs/10 PII posture) |
| Dry-run extension | `Model/DryRun/` | Synthetic-batch mode: fabricate N sample items into the batch context and walk — no accumulator required |

## Stages

| # | Stage | Notes |
|---|---|---|
| 1 | `aggregation` column + profile check + `supportsBatch()` + classifier wiring (F4) + context-shape docs | All validation, no behavior |
| 2 | **B1** collected mode + `EntityDataConverter` routing + batch rendering in grid/view | First user-visible digest |
| 3 | Collection formatters + email/webhook paths + tests | Rendering story complete |
| 4 | **B2**: tables, dispatcher branch, flush sweep, window policies, TTL | The accumulator |
| 5 | `aggregate_suppressed_events` + storm test + dry-run synthetic-batch + ops guide (docs/15: new sweep, new tables, remediation) | |

## Tests

Profile check matrix (each forbidden step/action/condition class → its specific error); B1:
watermark + item_cap + overflow + EAV-attribute parity via converter; formatters: escaping,
chaining, non-list inputs fail open; B2: window-key uniqueness, dedupe within batch, claim race
(two sweepers, one winner), crash-between-create-and-publish retry re-publishes same execution,
timezone table tests per policy; storm test (10k inserts → exactly one execution); suppression
bypass on/off.

## Compatibility notes

- Per-entity workflows: zero behavior change; the dispatcher's new branch is guarded on the
  `aggregation` column being non-null (and its suppression interaction only activates with the
  explicit flag).
- Honest-cost documentation carries into docs/08: membership evaluation adds synchronous
  per-event rule evaluation for aggregated workflows — the pipeline win is downstream.
- `min_items` semantics (interval-mode carry-forward vs schedule-mode drop) decided in stage 4
  design review — flagged from discovery §9, not silently defaulted.
