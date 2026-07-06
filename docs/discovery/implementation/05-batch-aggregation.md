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
| `aggregation` column (F8) | `mageos_workflow` | Nullable JSON: `{mode: collected\|window, window, item_cap, min_items, projection: [fields], aggregate_suppressed_events}`. `window` is mode-specific: `{type:"schedule", cron:"0 9 * * *", timezone:"America/New_York"}` or `{type:"interval", duration:"PT1H"}`. Non-null column = aggregated workflow (the "kind" derivation — no enum column) |
| **`window_key` format** | convention | The flush-idempotency key, so it must be deterministic: schedule mode → the window-start instant in the declared timezone, ISO-8601 (`"2026-07-04T09:00:00-04:00"`); interval mode → the opening event's UTC timestamp truncated to seconds. Backs `UNIQUE(workflow_id, window_key)` |
| Batch capability marker | `Api/BatchCapableActionInterface` + `AbstractAction` | **Not** a method on `ActionMetadataInterface` — PHP interfaces can't carry default bodies, and adding a method to a published `Api/` contract breaks every third-party implementor. Instead: a separate empty-ish capability interface checked via `instanceof` in the ProfileCheck (absence = not batch-capable), plus `supportsBatch(): bool { return false; }` on `AbstractAction` for first-party ergonomics. Email/webhook/admin-notify/set-variable opt in |
| Batch profile check | F2 `Check/` | For aggregated workflows: step-type allowlist (action/delay/branch-on-trigger-data/switch/stop — no wait, no `revalidate_entity`); actions must be batch-capable per the marker above; root conditions must classify fully `in_snapshot` (F4 classifier, first production wiring) — merchant-readable errors throughout |
| Batch context shape | convention (F8) | `trigger = {batch:true, count, window:{from,to}, overflow, items:[…]}`; documented in docs/04 |
| **B1** collected mode | `module-workflows-scheduler/Model/QueryRunner` | New branch: accumulate projections instead of per-match dispatch; route projections through `EntityDataConverter` (item-shape parity with B2 — review finding); one dispatch with the batch context; watermark/cap semantics unchanged, `item_cap` + `overflow` on top. **Membership must be enforced per item here**: in per-entity mode the unmapped-conditions fallback is safe because each execution re-evaluates root conditions — in collected mode there is one execution and no re-filter, so B1 evaluates the snapshot-only membership conditions against each flat projection before accumulating (the same membership evaluator B2 uses), on the mapped *and* fallback paths alike |
| Collection formatters | `Model/Variable/VariableResolver` | `count`, `pluck:'field'`, `join:', '`, `table:'f1,f2'` (escaped cells), `json` — same fixed-list, no-execution discipline. **Not a mere whitelist addition:** the resolver today stringifies arrays to `''` *before* filters run, and `applyFilter` is string-typed throughout — the pipeline needs a raw-value stage (keep the array intact when the first filter is collection-typed, stringify after), i.e. `applyFilter`/`applyFilters` move to `mixed` values |
| Batch rendering | admin-ui + core renderer | Execution grid/view render "batch (143 items)" instead of an entity link; plain language for aggregated workflows ("once per day, for everything that…") |
| **B2** accumulator | `Model/Engine/` + F8 tables | Dispatcher branch at the suppression guard (bypass when `aggregate_suppressed_events`); snapshot-only membership evaluation + item upsert via `insertOnDuplicate` (mirror `StockThresholdDetector`/`RunScheduledWorkflows` — note the dispatcher's *debounce* uses the different insert-and-catch idiom; don't copy that one); batch row per `(workflow_id, window_key)` |
| Flush sweep | rides the resume sweeper cadence | Claim `open→flushing` (conditional UPDATE); **write-before-publish**: create execution, record its id on the batch row, publish, stamp `flushed` — retry path re-publishes the recorded execution, never creates a second (the time-bucket debounce provably can't dedupe this) |
| Window policies | `Model/Engine/` (batch) | `schedule` (cron in an explicit store timezone — reuse scheduler tz resolution) and `interval` (close `PT…` after first event); quiet-period deferred |
| TTL pruning | existing retention cron | Flushed batches + items pruned with execution-context retention (docs/10 PII posture) |
| Dry-run extension | `Model/DryRun/` | Synthetic-batch mode: fabricate N sample items into the batch context and walk — no accumulator required |

## Stages

| # | Stage | Notes / done-when |
|---|---|---|
| 1 | `aggregation` column + profile check + `BatchCapableActionInterface` marker + classifier wiring (F4) + context-shape docs | All validation, no behavior. Done when: profile-check matrix tests pass (each forbidden step/action/condition → its specific message code) |
| 2 | **B1** collected mode + per-item membership + `EntityDataConverter` routing + batch rendering in grid/view | First user-visible digest. Done when: the unmapped-nested-conditions test proves non-matching rows are excluded from `items[]` |
| 3 | Collection formatters (incl. the raw-array pipeline stage) + email/webhook paths + tests | Done when: `pluck`/`table` resolve an EAV attribute identically under B1 and B2 item shapes |
| 4 | **B2**: tables, dispatcher branch, flush sweep, window policies, TTL | The accumulator. Done when: claim-race test (two sweepers, one winner) and crash-retry test (re-publishes the recorded execution, never a second) pass; `min_items` provisional default confirmed-or-revised + tested for both window modes |
| 5 | `aggregate_suppressed_events` + storm test + dry-run synthetic-batch + ops guide (docs/15: new sweep, new tables, remediation) | Done when: 10k-insert storm test yields exactly one execution |

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
- Projection default is deliberately simple: **identity fields + attributes named in the root
  conditions** (walked from the serialized tree's `attribute` keys). "Attributes referenced by
  templates" is *not* auto-derived (it would require parsing `{{ }}` placeholders out of every
  action config) — a workflow whose templates need more declares an explicit `projection` list,
  and the gallery/docs say so.
- `min_items` provisional default (so stage 4 is never blocked on a meeting): interval mode
  carries under-minimum items into the next window; schedule mode drops with a debug log.
  Stage-4 gate: this semantics is confirmed-or-revised in design review **and** unit-tested for
  both window modes before the stage merges.
