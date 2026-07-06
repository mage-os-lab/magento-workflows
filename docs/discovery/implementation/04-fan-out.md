# 04 — Fan-Out: Implementation Plan

**Discovery:** [fan-out.md](../fan-out.md) · **Foundations used:** F5, F8 (`fan_out`, `origin_uuid`), F2 (type-alignment check)
**Modules touched:** `module-workflows`, `module-workflows-triggers-core`, `module-workflows-admin-ui`

## Intent

F1 from discovery only (trigger-level fan-out): expansion happens *before* executions exist, in
the notifier consumer, producing N ordinary single-entity executions. The executor is unchanged;
the dispatcher gains exactly **one** addition — when the payload carries `origin.trace_uuid`,
stamp it onto the new `origin_uuid` column in `createExecution` (the `origin` *context* key
needs no dispatcher change: `createExecution` already writes the trigger payload into context
verbatim). The mid-flow `fan_out` step (discovery F2) is explicitly out of this plan — it gets
its own plan if/when F1 demand proves out, and would ride the next schema revision.

## Components

| Component | Home | Intent |
|---|---|---|
| `fan_out` column (F8) | `mageos_workflow` | Nullable JSON `{relation, cap}`; null = today's behavior; no flag needed beyond the column itself |
| `FanOutExpander` | `Model/Engine/` (core), invoked from `WorkflowNotifier` (triggers-core — which already depends on core; correct dependency direction, verified) | Lives at the notifier because that's where the source event object — and its trace UUID — exists. **Implementation caveat:** the trace-UUID accessor on `AsyncEventDisplayInterface` is not verifiable in this repo (the interface isn't vendored) — pin the exact accessor during implementation in the same place `WorkflowNotifier`'s docblock already pins its other async-events API assumptions; fallback if the installed version lacks it: omit `origin_uuid`, keep the rest of `origin`. Resolves the relation via `RelationContext::resolve()` (F5), hydrates each target via the existing hydrators into the child trigger snapshot, injects `origin` (`{event, entity_type, entity_id, trace_uuid, via: "fan_out"}`), dispatches per target. Caps: per-workflow `cap` clamped to `mageos_workflows/guards/fan_out_cap` (default 100); truncate + warn + admin-visible marker (never silent) |
| Mid-expansion failure policy | in the expander | Each child's hydrate+dispatch is individually try/caught: a child that throws is **logged and skipped, expansion continues** (fail-open per child). The notifier reports SUCCESS once the relation resolved — redelivery is reserved for *pre-expansion* failures (relation resolution itself threw), where re-expansion is safe because per-child debounce collapses the already-dispatched. The single `NotifierResult`'s response data records `{dispatched, skipped, truncated}` counts |
| Crash posture | — | Redelivery re-expands; per-child debounce collapses duplicates (existing atomic insert). Ops guide documents the debounce-window ≥ redelivery-delay coupling |
| Type-alignment check | F2 `Check/` | At save: trigger's entity type == relation source; relation target == workflow `entity_type`. Root conditions/actions therefore author naturally against the target |
| `origin_uuid` column (F8) | `mageos_workflow_execution` | Indexed; written by the dispatcher when the payload carries `origin.trace_uuid`; grid filter "caused by" |
| Plain language + UI | admin-ui | Fan-out fieldset (relation select from `meta/relations`, cap); rendering leads with per-target phrasing: "for **each open order** of that customer (up to 100)"; enable-confirmation copy borrows the manual-mass-run vocabulary |
| Dry-run extension | `Model/DryRun/` | Trace node: resolve the relation live, "would dispatch N executions (first 3: …)", optionally walk one sample target |
| Scheduler note | — | Schedule-type workflows with `fan_out` are rejected at save in v1 (the scheduler already fans out over its query; stacking relation fan-out on top is cap-multiplication nobody asked for — revisit on demand) |

## Stages

| # | Stage | Notes / done-when |
|---|---|---|
| 1 | Column + expander + notifier wiring + type-alignment check + storm/debounce tests | Core behavior, inert until a workflow sets `fan_out`. Done when: the storm test (1 event × 100 targets × duplicate delivery → exactly 100 executions) and the mid-expansion-throw test (child k throws → k skipped, N−1 dispatched, counts recorded) pass |
| 2 | `origin` payload convention + `origin_uuid` column + dispatcher stamp + grid filter | Convention documented in docs/04 per F8. Done when: grid "caused by" filter returns all children of a source trace UUID |
| 3 | UI fieldset + plain language + confirmation copy | Done when: rendering leads with per-target phrasing incl. the cap |
| 4 | Dry-run trace extension + ops-guide (docs/15) updates | |

## Tests

Expander: cap/truncation marker, empty relation (zero children, logged), redelivery re-expansion
collapsed by debounce (storm test: 1 event × 100 targets × duplicate delivery), suppression and
scope guards observed per child, snapshot shape parity (expander-hydrated child snapshot ==
what the target's own event would carry — via `EntityDataConverter`). Save check: all three
type-mismatch permutations rejected with readable messages. Origin: Trigger Data leaf matches
`origin.event`; post-revalidate absence documented by a test asserting the hydrated model lacks
it (pins the caveat).

## Compatibility notes

- Workflows without `fan_out` take a single early-exit branch in the notifier — no measurable
  dispatch-path cost.
- Children are indistinguishable from ordinary executions downstream (grid, retries, circuit
  breaker) except for the `origin` context and `origin_uuid` — deliberately so.
- Depends on 02 stages 1–3 (registry + `customer.open_orders` seed relation) being merged.
