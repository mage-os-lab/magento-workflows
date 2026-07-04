# Discovery — Fan-Out (One Trigger → N Related Executions)

**Status:** Discovery / evaluation · **Track:** capability enhancements (beyond Phase 3)
**Related:** [18 — Known Boundaries §Flow control](../18-limitations.md#flow-control--orchestration) · [08 — Execution Model](../08-execution-model.md) · [entity-cross-referencing.md](entity-cross-referencing.md) · [branching.md](branching.md) · [batch-aggregation.md](batch-aggregation.md)

---

## 1. Reframing the gap

[18 — Known Boundaries](../18-limitations.md#flow-control--orchestration) lists "fan out from one
trigger to N related entities" as unsupported. The examples that matter:

- Customer moved to group *Blocked* → hold **each of their open orders**.
- Product discontinued → add a comment to **every pending order containing it**.
- Company credit hold (B2B, later) → act on **each open quote of the company**.

The reframing that makes this tractable: **fan-out already exists in this engine — at the
dispatch layer.** The scheduler's `QueryRunner` dispatches one execution per matched entity
(paged 500, capped 5000, watermarked — `src/module-workflows-scheduler/Model/QueryRunner.php:114–158`),
and both detectors publish one event per detected row. Every child is an ordinary single-entity
execution with the full guard stack (debounce, scope, suppression). What's missing is only the
*event-triggered, relation-driven* variant: "when X happens to entity A, dispatch per entity in
relation R(A)".

What must **not** happen: fan-out inside the executor. In-execution iteration is the loops
non-goal ([01](../01-overview.md#non-goals-for-v1)), and multi-token executions were evaluated
and rejected in [branching.md §B2](branching.md) — those constraints are load-bearing
(single-`current_step` crash safety). Fan-out belongs where it already lives: **before**
executions exist.

Two other candidate shapes get dispositioned against neighboring docs:

- *"Fan out into a summary"* is [batch-aggregation.md](batch-aggregation.md) (N inputs → 1
  execution — the inverse of this doc).
- *"Fan out and wait for all branches"* is fork/join, rejected in
  [branching.md §B2](branching.md); everything here is join-free by design.

## 2. Approaches

### F1 — Trigger-level fan-out (relation-driven dispatch expansion) — recommended

A workflow declares, alongside its trigger, an optional fan-out clause:

```
trigger:  customer.group_changed  (source entity: customer)
fan_out:  relation = customer.open_orders   →  workflow entity_type = sales_order
          cap = 100 (config default; per-workflow override ≤ global max)
```

Dispatch flow (all existing seams, verified against `Model/Engine/Dispatcher.php`):

1. `WorkflowNotifier` delivers the event payload as today.
2. A `FanOutExpander` is invoked **from the notifier** (not merely in front of
   `Dispatcher::dispatch()` — the notifier is the only place the source event object, and hence
   its async-events trace UUID, is in hand; `dispatch()` receives payload only): it resolves the
   relation (the `RelationInterface` registry from
   [entity-cross-referencing.md §3](entity-cross-referencing.md) — same pool, cardinality
   `many`) against the source entity snapshot, hydrates each target to build its trigger
   snapshot (the same flat shape the target's own events produce, via the existing hydrators),
   and calls `dispatch()` once per target.
3. Each child is a **normal execution**: `entity_id` = target id, `entity_type` = the workflow's
   entity type (= relation target type, enforced at save), conditions evaluate against the
   *target*, actions act on the *target*. Debounce applies per `(workflow, target)` — a storm of
   group-change events for one customer collapses exactly like any other storm.
4. The expander injects an `origin` key (`{event, entity_type, entity_id, trace_uuid,
   payload-summary}`) into each child's dispatch payload, which lands in `context.trigger` via
   `createExecution` — so conditions (the Trigger Data leaf resolves dot-paths against the
   payload root, i.e. `origin.event`, not `trigger.origin.event`) and interpolation
   (`{{ trigger.origin.event }}`) can reference why the child exists ("held due to customer
   group change"). Caveat inherited from Trigger Data's snapshot-only design: after a
   `revalidate_entity: true` branch, the freshly hydrated entity carries no `origin` — so
   origin-based gating works at the root and pre-delay only.

Why this shape wins: zero executor changes, zero new execution states, every existing guard and
observability surface applies per child unchanged, and the workflow remains honest — its entity
type *is* what its conditions and actions operate on, so the whole condition/action/ACL
machinery needs no special cases.

- ⚠️ Cost: a new dispatch-side concept (expansion), one more save-time validation dimension
  (trigger source type vs relation source, relation target vs workflow entity type), and UI copy
  that makes "this workflow runs once **per open order** of the customer" unmissable —
  plain-language rendering does the heavy lifting.

### F2 — Mid-flow `fan_out` step (dispatch a workflow per related entity)

A terminal-ish step: after some conditions/delays on the source entity, dispatch a *target
workflow* once per relation member — fire-and-forget, no join:

```json
{"type": "fan_out", "config": {"relation": "customer.open_orders", "workflow_id": 42, "cap": 50}}
```

This is really two deferred concepts (sub-workflows + iteration) fused into one step, made safe
by three properties: children are full executions of an ordinary workflow (own guards, own logs);
there is **no join** (the parent continues via `next` immediately, recording dispatched count +
UUIDs as step output); and the loop guard finally earns its keep — children dispatch with
`chain_depth + 1`, which is fully plumbed but today never incremented (verified: no caller
passes non-zero depth), so runaway workflow-triggers-workflow chains hit `loop_guard_depth` and
log `loop_suppressed` exactly as designed ([07 §Loop prevention](../07-actions.md#loop-prevention-storms-and-circuit-breaking)).

- ✅ Unlocks "wait 1h, *then* if still blocked, hold every open order" (fan-out after mid-flow
  state), and composition (the target workflow is reusable, dry-runnable, individually
  disableable).
- ⚠️ Real costs: a schema bump (new step type — ride the next schema revision, don't burn one
  alone); authorization semantics (authoring a fan-out step must require authoring rights over
  the *target* workflow's actions — re-run `authorizeActionCodes` against the target at save,
  and re-check target status at run time: disabled target = step failure, not silent skip);
  cross-workflow observability (parent step output ↔ child origin UUIDs); dry-run must render
  "would dispatch ~N children" with a sample, not recurse.

### F3 — In-execution iteration (`foreach` over a collection with per-item steps)

Rejected, unchanged: it's the loops non-goal, it breaks the single-token execution model, and
F1/F2 cover the demand with per-item executions that are individually logged, retried, and
guarded. Per-item behavior *within one action* stays the action author's job (the pattern the
order-items conditions and MSI-aware stock action already follow).

**Recommendation: F1 now; F2 as a fast-follow once F1 demand proves out** (F2 reuses F1's
expander, relation plumbing, and guards nearly wholesale — it's mostly step-type + authorization
work at that point).

## 3. Guards & storm math (the part that decides if this is safe to ship)

Fan-out multiplies: `events/sec × relation size`. The guard stack (the expander's cap fires
first; inside each child dispatch the existing order is status → chain-depth → suppression →
scope → debounce, per `Dispatcher::dispatch()`):

| Guard | Mechanism | Status |
|---|---|---|
| Per-fan-out cap (expander, pre-dispatch) | relation `resolveIds()` bounded; default 100, global ceiling config; **over-cap = truncate + warn + admin-visible marker on the source event log entry** (silent truncation forbidden) | new, cheap |
| Chain depth | children carry `chain_depth + 1` (F2) / source depth (F1 — expansion is not a chain hop, the event caused them directly) | plumbed, finally used |
| Bulk suppression | `WorkflowSuppression` checked per child dispatch; a suppressed import storm suppresses the children too | exists |
| Per-child debounce | existing atomic `(workflow_id, entity_id, time_bucket)` unique-key insert (`Dispatcher.php:233–261`) | exists, applies unchanged |
| Circuit breaker | per-workflow **consecutive**-failure trip → suspend (resets on any success): an all-children-fail workflow trips after ~10 dispatched children; interleaved successes can stave it off, and already-queued children still run — the residual blast radius is bounded by the fan-out cap, not the breaker | exists |
| Match-cap precedent | scheduler's 5k cap + honesty logging is the model for cap semantics | exists as pattern |

One decision worth pinning: F1 expansion happens inside the notifier consumer's message handling
— if it crashes mid-expansion, redelivery re-expands, and the per-child debounce is what makes
that safe (already-dispatched children collapse). That's the same at-least-once + idempotent-gate
posture the whole engine takes ([08 §Crash safety](../08-execution-model.md#crash-safety-and-delivery-semantics));
no new transactional machinery needed, but the debounce window must comfortably exceed
worst-case redelivery delay — document that coupling in the ops guide.

## 4. Observability & merchant comprehension

- **Origin correlation:** children record the source event's async-events trace UUID plus a
  `fan_out` marker in context (`origin` in the trigger payload, §2 point 4 — note this is
  plumbed by the expander from the notifier; execution UUIDs themselves are freshly generated
  per execution and are *not* the event trace UUID); the execution grid gains an "origin" filter
  so "show me everything that customer-group change caused" is one query. No schema change
  strictly required (context JSON carries it), but an indexed `origin_uuid` column is cheap and
  makes the grid filter real — recommended.
- **Plain language:** *"When a customer's group changes, for **each open order** of that
  customer (up to 100): if …, then hold order."* The per-target phrasing is the single most
  important comprehension detail — a merchant who reads this as "runs once" will be surprised in
  the worst way. Render the cap.
- **Dry-run:** [dry-run.md](dry-run.md) gains a small extension: resolve the relation live,
  report "would dispatch N executions (showing first 3 targets)", and optionally walk one
  sample target through the graph. Cheap because dry-run already hydrates entities.
- **Manual mass-run overlap:** the existing grid mass-action + `matched-count preview + 1k cap +
  audit` ([10 §Manual mass-run](../10-security.md#manual-mass-run)) is the *manual* cousin of
  F1 — reuse its confirmation UX vocabulary for enabling a fan-out workflow ("this may run
  against up to N entities per trigger").

## 5. Quality, maintainability, reliability

- **Reliability:** no executor, queue-topology, or resumption changes; children are
  indistinguishable from ordinary executions downstream of dispatch. The novel surface
  (expansion) is a bounded loop over repository-resolved ids with per-iteration guards, and its
  crash story reduces to existing redelivery + debounce.
- **Performance:** expansion cost is one relation query + N hydrations at dispatch time. For
  snapshot-only workflows (the common case) the expander's hydration output *is* the child's
  trigger snapshot, so it's the only entity load in the child's life; workflows with phase-2
  conditions re-hydrate in the executor's own process (the expander's identity map doesn't cross
  the consumer boundary), i.e. two loads per child — bounded, but not "reused". Caps bound N;
  consumers scale horizontally as today.
- **Maintainability:** one new class (`FanOutExpander`), one workflow-level config block, one
  save-time validator extension; the relation registry does the semantic heavy lifting and is
  shared with [entity-cross-referencing.md](entity-cross-referencing.md). F2 later adds a step
  type through the same schema/versioning discipline as `switch` ([branching.md](branching.md)).
- **Security:** relation codes are code-registered (unknown code = save/import failure); F1
  children execute the workflow the admin authored under its own ACL gates, nothing escalates;
  F2's cross-workflow authorization is the one genuinely new check and is specified above.
- **Testability:** expander unit tests (cap/truncation/debounce interplay via the existing shim
  harness); an integration-shaped test per seed relation; a storm test (1 event × 100 targets ×
  debounce) in the engine suite.

## 6. Sequencing & effort

Depends on the relation registry ([entity-cross-referencing.md](entity-cross-referencing.md)
items 1 + 3) — sequence after it.

| Order | Item | Effort |
|---|---|---|
| 1 | `FanOutExpander` + workflow fan-out config (storage: new nullable `fan_out` JSON column on `mageos_workflow`) + save-time type validation | ~1 wk |
| 2 | Guards & caps + `origin_uuid` column/filter + plain-language + ops-guide notes | ~1 wk |
| 3 | Dry-run extension + admin UI (fan-out fieldset, cap display, confirmation copy) + tests | ~1 wk |
| — | F2 (`fan_out` step): step type, target-workflow authorization, chain-depth wiring, dry-run rendering | ~2–3 wk, fast-follow |

F1 total ≈ 3 wks on top of the registry.

## 7. Open questions

1. F1 trigger/relation source alignment: allow relations whose source is the trigger's *payload*
   rather than its entity (e.g. fan out from a raw event with only an email)? Leaning no for v1 —
   relations resolve from the source entity snapshot; payload-sourced relations become a
   registry extension later if needed.
2. Should F1 children skip the workflow's *root* conditions (they were authored against the
   target entity — presumably no) or is there demand for source-side pre-conditions ("only fan
   out if the customer is Wholesale")? Proposal: root conditions evaluate against the target
   (consistent: entity_type = target); source-side gating uses a Trigger Data leaf on
   `origin.*` paths (§2 point 4). Validate with the first real use cases.
3. Cap breach policy: truncate-and-warn (proposed) vs refuse-entirely (safer for "hold orders"
   style actions where partial application is confusing). Possibly per-workflow choice; decide
   with merchant feedback during beta.
