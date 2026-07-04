# Discovery — Batch Aggregation (N Events → One Execution)

**Status:** Discovery / evaluation · **Track:** capability enhancements (beyond Phase 3) · **Implementation plan:** [implementation/05-batch-aggregation.md](implementation/05-batch-aggregation.md)
**Related:** [07 — Actions §Loop prevention](../07-actions.md#loop-prevention-storms-and-circuit-breaking) · [18 — Known Boundaries](../18-limitations.md#flow-control--orchestration) · [fan-out.md](fan-out.md) · [branching.md](branching.md)

---

## 1. The promise being cashed

[07 — Actions](../07-actions.md#loop-prevention-storms-and-circuit-breaking) already names this
feature: *"aggregate triggers — 'fire once per batch with the matched collection' — turning the
storm problem into a feature (e.g., 'email me a summary of all products that went out of stock
today')."* It is the inverse of [fan-out](fan-out.md): N inputs collapse into **one** execution
whose trigger context carries a collection.

Target flows:

- "Email me everything that stocked out today" (digest of `inventory.stock_threshold_crossed`).
- "Every morning, one Slack webhook with yesterday's orders over $1k."
- "When an import fires 10k `product.saved` events, I want one summary, not 10k suppressed logs."

Current reality (verified): the engine is strictly per-entity. The dispatcher creates one
execution per event; `WorkflowSuppression` *drops* storms rather than summarizing them; the
scheduler's `QueryRunner` dispatches per match; the two detectors batch their *detection* SQL
but still publish one event per row. The nearest aggregation anywhere is
`CustomerAggregateProvider` — per-customer, at hydration time. Nothing accumulates across
entities into one execution, and several core assumptions (every execution has an `entity_id`;
`wait`/`revalidate` presume an entity; the variable resolver can't render lists) bite the moment
one does. Those assumptions are the real cost of this feature and are treated honestly in §4–5.

## 2. Two distinct shapes — build the cheap one first

Most "batch" requests decompose into one of:

- **Scheduled digest** — "at 09:00, one execution with everything matching a query." No
  accumulation needed; the collection exists in the database and the *scheduler already finds
  it* — it just dispatches N executions instead of one.
- **Event-window digest** — "collect matching events as they happen; flush the batch when the
  window closes." Needs real accumulation infrastructure.

### B1 — Scheduled digest: a collect-mode on the existing scheduler (recommended first)

A schedule-type workflow gains `dispatch_mode: per_entity | collected`. In `collected` mode,
`QueryRunner` — which already pages matches at 500/page under a 5k cap with watermarks
(`src/module-workflows-scheduler/Model/QueryRunner.php:114–158`) — accumulates flattened
projections instead of dispatching each, then dispatches **one** execution:

```json
context.trigger = {
  "batch": true, "count": 143, "overflow": false,
  "window": {"from": "<prev watermark>", "to": "<new watermark>"},
  "items": [ {…capped projection…}, … ]
}
```

- ✅ Small: one branch in `QueryRunner`, a context shape, plus the shared §4/§5 work. Covers the
  two most-requested digests ("daily summary of X") outright.
- ✅ Watermarks already prevent re-digesting the same rows; the match cap becomes the batch cap
  with an `overflow: true` marker (never silent).
- ⚠️ Only reaches what a root-condition query can express (flat conditions map to
  `SearchCriteria`; nested trees fall back to load-and-filter — existing behavior, fine at
  digest cadence).
- ⚠️ Item-shape parity with B2 is not automatic: `QueryRunner::toFlatArray()` flattens via
  `getData()`/`__toArray()` only, while event snapshots flow through `EntityDataConverter`,
  which lifts custom/extension (EAV) attributes to the top level — so a `|pluck:'my_eav_attr'`
  would resolve under B2 but come up empty under B1. Route B1 projections through
  `EntityDataConverter` so both modes produce the same flat item shape.

### B2 — Event-window accumulator (the full feature)

New infrastructure: when an *aggregated* event workflow's trigger fires, the dispatcher does
**not** create an execution; it appends a row to a batch, and a flush releases one execution per
window.

```
mageos_workflow_batch       (batch_id PK, workflow_id, window_key, status open|flushing|flushed,
                             opened_at, flush_due_at, item_count)  UNIQUE(workflow_id, window_key)
mageos_workflow_batch_item  (batch_id, entity_id, snapshot JSON (projected, capped), created_at)
                             UNIQUE(batch_id, entity_id)   -- dedupe: an entity appears once
```

Dispatch path: the accumulation branch sits **at the suppression guard** in the dispatcher's
sequence (an aggregated workflow with `aggregate_suppressed_events` set bypasses the suppression
drop — §6; today suppression returns before scope/debounce, `Dispatcher.php:86–91`): evaluate
the workflow's root conditions against the event **snapshot only** (membership filter — see §4),
then `INSERT … ON DUPLICATE KEY` the item. Storm-proof: no hydration, no execution row, one
small insert per event — though *not* zero-cost; see §4's honesty note on per-event rule
evaluation.

Flush path: the existing one-minute resume sweeper cadence gains a batch sweep — batches whose
`flush_due_at` has passed are claimed with an atomic `open → flushing` conditional UPDATE (the
same claim idiom as wait-step wakes, `Dispatcher::resumeWaiting`), their items loaded (capped,
`overflow` flagged), and one execution dispatched with the B1 context shape. Crash between claim
and dispatch: the sweeper retries `flushing` batches older than a grace period. Idempotency is
the **batch row itself**, following `resumeWaiting`'s write-before-publish discipline: the flush
creates the execution row and records its id on the batch row *before* publishing, so a retried
flush that finds an execution id re-publishes that execution rather than creating a second one
(`flushed` is stamped after successful publish). This is deliberately *not* the time-bucketed
debounce table: a retry after a grace period would land in a different `time_bucket` and sail
through, so the existing debounce mechanism cannot dedupe flushes.

**Window policies** (per workflow):
- `schedule`: window closes on a cron expression ("daily at 09:00 store time" — reuse the
  scheduler's store-timezone resolution; this is the [14 — Risks](../14-risks.md) timezone
  lesson, applied on day one).
- `interval`: window closes `PT1H` after the *first* event opens it (rolling digests).
- Quiet-period flushing (close after N idle minutes) is deferred — it's the only policy needing
  per-event `flush_due_at` updates, and demand is unproven.

### B3 — Post-hoc digests over execution history (query the log, not the stream)

"Summarize what workflows did" is reporting, not workflow execution — served better by the ES
indexing + `workflow:stats` surfaces ([09 — Observability](../09-scope-acl-observability.md#observability)).
Rejected as out of scope here.

**Recommendation: B1 now, B2 as the follow-on** sharing all of §4–5. B1 validates the context
shape, action semantics, and rendering story with ~20% of the infrastructure; B2 then adds only
the accumulator + sweeper.

## 3. What a batch execution is (domain-model honesty)

A batch execution has **no single entity**, which touches real assumptions:

| Assumption | Resolution |
|---|---|
| `entity_id` NOT NULL on executions | `entity_id = 0` + `context.trigger.batch = true`; execution grid renders "batch (143 items)" instead of an entity link. Flush idempotency keys on the batch row (§2), not the entity debounce. |
| `wait` steps park per entity | **Invalid in aggregated workflows** — rejected at save by a definition-**profile** check (a step-type allowlist per workflow kind) that runs alongside [branching.md §2](branching.md)'s `GraphValidator`; the topological validator itself stays profile-agnostic. |
| `revalidate_entity` re-hydrates the trigger entity | Invalid likewise — branches in batch workflows evaluate snapshot-only. |
| Root conditions can hydrate (phase 2) | **Membership filtering is snapshot-phase only** (§4). |
| Branch conditions evaluate one entity | Batch-level branching uses the existing **Trigger Data** leaf over the batch context — `count >= 5`, `overflow == true` work today with zero new condition code. Per-item conditions inside the flow are out (that's the membership filter's job). |
| Delays | Fine — delays don't touch the entity. |

This "restricted definition profile" is the single most important design move: instead of
teaching every step type about batches, aggregated workflows accept a *validated subset*
(action / delay / branch-on-trigger-data / switch / stop), enforced at save with clear messages.
Nothing in the executor changes except tolerating `entity_id = 0`.

## 4. Membership conditions: snapshot-only, enforced at save

The accumulation path runs per event during exactly the storms this feature exists to absorb —
it must be **zero-query**. Rule: an aggregated workflow's root conditions must classify fully
`in_snapshot`. The classification logic exists (`Model/Rule/AttributeClassifier.php`, computing
the `in_snapshot`/`needs_hydration` split described in
[06 §Two-phase](../06-conditions.md#two-phase-evaluation-the-eav-at-scale-answer)) but is
currently **unwired** — unit-tested only, with no production caller; evaluation *ordering* is a
separate mechanism (`sortForShortCircuit`). Batch membership becomes its first production
consumer: a save-time constraint with a merchant-readable error ("Batch workflows can only
filter on data included in the event"). Wiring it is greenfield integration work, priced into §8.

Honesty note on cost: zero-*query* is not zero-*cost*. Today the synchronous dispatch path never
evaluates root conditions (that happens later, in the async executor — `Executor.php:106`);
accumulation moves a rule-tree instantiation + `validate()` per event into the notifier/dispatch
hot path. Snapshot-only evaluation is cheap and allocation-bound, but it is *added* synchronous
work — the honest claim is that the **whole pipeline** gets far cheaper (no execution rows, no
per-event queue round-trips, one action run instead of thousands), not that the per-event
dispatch cost drops.
Cross-entity/aggregate/relation conditions stay available in per-entity workflows; a merchant who
needs them plus a digest chains features: per-entity workflow → tags/flags → scheduled B1 digest
over the flag.

## 5. Rendering a collection (actions & variables)

Through-line 4 of [18](../18-limitations.md#the-five-structural-through-lines): the resolver has
no iteration. Batch context makes this acute. Resolution per channel, keeping the
no-code-execution stance:

- **Webhook** — needs nothing: the JSON body template already interpolates values, and a new
  pass-through `{{ trigger.items|json }}` formatter embeds the full (capped) collection for the
  receiving system. This is the agency-grade path and it's nearly free.
- **Email / text bodies** — three new whitelisted, chainable **collection formatters** (same
  fixed-list discipline as wave 5, no expressions): `|count`, `|pluck:'sku'` (list → list of one
  field), `|join:', '`, plus `|table:'sku,name,qty'` rendering a plain HTML/text table for the
  ad-hoc email body (HTML-escaped per cell, matching the existing ad-hoc escaping rule). Covers
  "digest email" without touching template directives.
- **Template-based email** — the batch context is passed to the transactional template as
  `items`; merchants with template access already have Magento's own template tooling. No
  resolver change.
- **Per-entity mutating actions** (hold order, set attribute…) — **not applicable to batch
  executions.** `ActionMetadataInterface` gains `supportsBatch(): bool` (default false; email /
  webhook / admin-notify / set-variable return true); save-time validation rejects
  non-batch-capable actions in aggregated workflows. "Mutate each item in the batch" is
  explicitly [fan-out](fan-out.md)'s territory — a future `batch → fan-out` composition is noted
  there, not smuggled in here.
- **Item snapshots are projections**: configurable field list per workflow (default: identity
  fields + the attributes referenced by conditions/templates), item cap default 500 with
  `count`/`overflow` always accurate. Keeps batch rows small and bounds the PII surface; batch
  items and batch executions get the same TTL pruning as execution context
  ([10 §PII containment](../10-security.md#pii-containment)).

## 6. Suppression synergy (the storm story, completed)

Today a bulk import inside `WorkflowSuppression::scope()` silently drops dispatches. With B2, a
per-workflow flag `aggregate_suppressed_events: true` lets an aggregated workflow keep
*accumulating* while suppression drops per-entity workflows — the import storm becomes exactly
one "12,431 products were updated by import" digest. Default **off**, for two reasons:
suppression's contract is "nothing happens" (opting a workflow into "something happens" must be
explicit), and cost — today the suppressed path is nearly free (a static flag check and an
immediate return, `Dispatcher.php:86–91`), while accumulating replaces that with per-event
membership evaluation + an insert. Bounded and worthwhile for the workflows that want it, but a
trade, not a freebie. Implementation is small (one branch at the suppression guard) and it
directly converts the engine's biggest operational hazard into its own reporting.

## 7. Quality, maintainability, reliability

- **Reliability:** accumulation is one snapshot-phase evaluation plus one idempotent insert per
  event (no hydration, no execution row, no queue round-trip) — far cheaper than today's
  per-entity pipeline under storm load, though the *synchronous* per-event cost rises slightly
  (§4). Flush uses the established claim idiom (conditional UPDATE) with write-before-publish
  idempotency on the batch row; a missed sweep flushes late, never twice, never silently
  dropped. Executor changes are limited to tolerating entity-less executions.
- **Correctness honesty:** every cap surfaces (`count`, `overflow`, truncation warnings); the
  restricted definition profile turns would-be runtime surprises (a wait step that can never
  wake) into save-time errors.
- **Maintainability:** B1 rides `QueryRunner`; B2 adds two tables, one sweeper, one dispatcher
  branch. The restricted-profile validation concentrates batch-awareness in one save-time
  definition-profile check rather than scattering `if (batch)` through the executor and every
  action.
  Collection formatters extend an existing whitelist mechanism with existing tests.
- **Scale posture:** batch tables are insert-heavy/short-lived — flushed batches prune with the
  TTL cron; indexes mirror the debounce table's shape. Item caps bound both row size and flush
  memory (flush loads items paged, same 500-page idiom as everything else).
- **Testability:** window policies and flush claims are pure-ish (unit tests under the shim
  harness); a storm test (10k inserts → one execution) and a timezone test per window policy are
  the keystone cases. Dry-run ([dry-run.md](dry-run.md)) gains a synthetic-batch mode: fabricate
  N sample items and walk the graph — no accumulator needed.

## 8. Sequencing & effort

Independent of the cross-referencing/fan-out track; the definition-profile check plugs into the
save-time validation seam [branching.md](branching.md)'s `GraphValidator` establishes (sequence
after it).

| Order | Item | Effort |
|---|---|---|
| 1 | Batch context shape + restricted-profile validation (definition-profile check, `supportsBatch`, `AttributeClassifier` wiring for the snapshot-only membership constraint) | ~1 wk |
| 2 | **B1** collected-mode scheduler dispatch + grid/plain-language rendering of batch executions | ~1–1.5 wk |
| 3 | Collection formatters (`count/pluck/join/table/json`) + email/webhook paths + tests | ~1 wk |
| 4 | **B2** accumulator tables, dispatcher branch, flush sweeper, window policies (schedule + interval), TTL pruning, ops-guide updates | ~2.5–3 wk |
| 5 | Suppression synergy flag + storm test | ~0.5 wk |

B1 milestone ≈ 3–3.5 wks; full B2 ≈ 6–7 wks cumulative.

## 9. Open questions

1. Window keys for `schedule` mode across stores: one batch per workflow (store-agnostic window
   in the workflow's primary store timezone) or per store? Leaning per workflow — batch
   workflows declare an explicit timezone/store like schedules do; per-store batching doubles the
   table semantics for an unproven need.
2. Should B1 collected mode share the 5k match cap or get its own (digest of 5k rows is a big
   email)? Proposal: separate `batch_item_cap` default 500 with `overflow`, while the query still
   watermarks past the full match set so nothing is skipped, only elided from `items`.
3. `min_items` to fire (don't send an empty/1-item digest)? Cheap and probably wanted:
   `min_items` default 1; a window closing under the minimum carries items into the next window
   (interval mode) or drops with a debug log (schedule mode). Decide during B2 design.
4. Is there demand for batch context in *branch conditions* beyond count/overflow (e.g. "any
   item with qty 0")? That's per-item predicates over the collection — if real, it becomes an
   `ItemsFound`-style Trigger Data extension, not a new engine concept. Wait for evidence.
