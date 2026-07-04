# Discovery — Dry-Run

**Status:** Discovery / evaluation · **Feeds:** Phase 3 planning ([13 — Delivery Plan](../13-delivery-plan.md))
**Related:** [11 — Admin UI §Shadow mode](../11-admin-ui.md#shadow-mode-v1-nearly-free) · [07 — Actions](../07-actions.md) · [08 — Execution Model](../08-execution-model.md) · [branching.md](branching.md) · [canvas.md](canvas.md)

---

## 1. What dry-run is (and how it differs from shadow mode)

Two confidence features share one simulation substrate but answer different questions:

| | Shadow mode (shipped, v1) | Dry-run (this doc) |
|---|---|---|
| Question | "What *would this have done* on live traffic over the past week?" | "What *would this do* to entity X, **right now**, before I enable it?" |
| Trigger | Real events, real dispatch, real queue | Explicit: a button on the edit form / CLI / REST |
| Time | Real time — delays park, waits park | **Compressed** — nobody waits an hour for a preview |
| State | Workflow `status = shadow` (status value 2) | Per-invocation; works on **unsaved** definitions too |
| Exists? | ✅ end-to-end | ❌ nothing — no flag, no UI, no CLI option |

The groundwork is unusually complete (verified against source):

- `SimulateableActionInterface` exists (`src/module-workflows/Api/SimulateableActionInterface.php`)
  and **all 22 core actions implement it**, validation-first: `simulate()` re-runs config/entity
  validation and returns `{simulated: true, would: "<human description>", …}` or a failure —
  so a simulated run genuinely catches config errors.
- Simulated outputs seed downstream interpolation (webhook → `{status_code: 0, response: {}}`,
  coupon → `SIMULATED-COUPON`), and the webhook's `simulate()` never touches the network or
  resolves auth secrets (`Action/Notify/Webhook.php:296–303`).
- The executor's simulation branch (`Executor::runActionStep`, ~241–252) and
  `ExecutionContext::isSimulation()` already exist — but the flag is derived **only** from
  `status === STATUS_SHADOW` (`Executor.php:100`). There is no per-execution simulation flag,
  no `--dry-run` on `workflow:run`, no REST surface.

So dry-run is not "build simulation" — it's **plumb a per-run simulation flag, decide the time-
compression semantics, and build the UX**.

## 2. Requirements

1. Run against a **specific entity** (order #1234) or a merchant-picked recent entity.
2. Work on the **definition as currently edited** (unsaved), not just the persisted version — the
   whole point is pre-save confidence.
3. **Zero side effects**, including: no entity mutation, no outbound HTTP, no email, no secrets
   resolved into anything observable, no real async-events subscriptions touched.
4. **Compressed time**: delays and waits annotate, never park.
5. Show a **step-by-step trace**: per step — condition results (which branch/case matched and
   why-ish), the `would` description, interpolated values, failures.
6. Usable by agencies headlessly (CLI + REST) — CI "does this definition still pass a smoke
   entity" is a real workflow-as-code use case.

## 3. The central design decision: which engine runs a dry-run?

### Approach A — Real pipeline with a simulation flag ("async dry-run")

Add `is_simulation` to `mageos_workflow_execution`; dispatcher accepts a simulate option; the
normal queue/executor runs it; UI polls the execution view.

- ✅ Perfect fidelity — the exact code path that will run in production runs the preview,
  including persistence, context building, resumption.
- ❌ **Delays and waits park for real.** A "wait 1 day then check" workflow dry-runs in… a day.
  Fast-forwarding inside the real executor means teaching `runDelayStep`/`runWaitStep` a
  simulation mode that *skips* parking — at which point the "real pipeline" is no longer real,
  and the fidelity argument evaporates while queue latency and polling UX costs remain.
- ❌ Requires the definition to be *saved* (executions pin `definition_snapshot` from the stored
  workflow), failing requirement 2 — or a "phantom save" hack.
- ❌ Wait steps have no event to receive; simulation would always take `on_timeout`, silently
  hiding the `on_event` path.

### Approach B — Synchronous `DryRunService` (in-process walker)

A dedicated service walks a `Definition` synchronously in the admin/REST/CLI request: evaluates
root conditions and branches via the real `ConditionEvaluator`, interpolates via the real
`VariableResolver`, calls the real `simulate()` on each action, **fast-forwards** delays
(annotates "would wait 1 business day, releasing ~Tue 09:00 store time" using the real
`DelayCalculator`) and waits (records both edges — see §4), and returns a trace object. No queue,
no execution rows required.

- ✅ Immediate (sub-second for typical graphs), synchronous UX; works on posted, unsaved JSON.
- ✅ Time compression is the *design*, not a hack bolted into the crash-safe executor.
- ✅ Can explore **both** wait/branch outcomes (impossible in the real pipeline).
- ❌ A second walker — divergence risk against `Executor::walk()`. This is the real cost of B
  and it must be managed structurally, not by discipline (see §5).

### Approach C — Refactor `Executor` to run in-process with pluggable persistence

Extract the walk loop so the same class runs either against DB-backed execution rows (production)
or an in-memory store (dry-run), with delay/wait handlers swapped for fast-forward versions.

- ✅ One walker, zero divergence.
- ❌ The executor's value *is* its persistence choreography — state-before-side-effect writes,
  claim timestamps, retry-vs-terminal routing, queue redelivery via thrown exceptions
  ([08 — Execution Model](../08-execution-model.md)). Abstracting that behind interfaces to
  support a mode that deliberately *doesn't want* crash safety, redelivery, or parking inverts
  the risk: we'd be destabilizing the production path (the thing that must not break) to serve a
  preview feature. The redelivery-by-exception contract in particular does not survive an
  in-process caller cleanly.

### Evaluation

| | A — async flag | B — sync service | C — refactored executor |
|---|---|---|---|
| Unsaved definitions | ❌ | ✅ | ✅ |
| Time compression | hack | native | invasive |
| Both wait paths visible | ❌ | ✅ | awkward |
| UX latency | queue + polling | immediate | immediate |
| Divergence risk | none | **managed** (§5) | none |
| Risk to production executor | low | **zero** | **high** |
| Effort | medium | medium | high |

**Recommendation: B**, with the §5 divergence controls, plus one element of A: dry-run traces
are *optionally* persisted (§6) for audit and for reuse of the execution-view UI. C is rejected
on the principle that the crash-safe production walker should not grow modes; A is rejected on
requirements 2 and 4.

Shadow mode remains untouched and remains the *fidelity* answer ("it ran through the real
pipeline for a week") — dry-run is the *immediacy* answer. They are complements, exactly as
[11 §Shadow mode](../11-admin-ui.md#shadow-mode-v1-nearly-free) anticipated.

## 4. Semantics decisions (the actual hard part)

- **Delays:** never park. Trace records the resolved resume time (real `DelayCalculator`, real
  store timezone, business-days/`at` honored, max-delay clamp noted if it fires) and continues.
- **Waits:** no event will arrive in-process. Default: follow **both** edges and render the trace
  as a tree from that step ("if `sales.order.created` fires within 4h → …; if not → …"). Cap
  total explored paths (e.g. 16) with the graph validator's help; beyond the cap, follow
  `on_timeout` and annotate. This both-paths exploration is dry-run's unique value over shadow
  mode — surface it prominently.
- **Branches/switches after compressed delays:** `revalidate_entity: true` re-hydrates against
  *now* — correct and honest ("as of this moment, the order is still `pending`, so the true edge
  runs"). Trace must state that post-delay evaluations reflect current state, not future state.
- **Branch condition visibility:** for each branch/switch, record the boolean/matched-case *and*
  the evaluated tree (attribute, operator, expected, actual where cheap). The rule model's
  `validate()` returns only a boolean; a first cut records the serialized tree + result, a later
  iteration can instrument leaf-level actual values. Don't block v1 on leaf instrumentation.
- **Failures don't stop the walk** (unlike production): a failed `simulate()` (bad config,
  missing entity) marks the step and — where an edge exists — continues, because the merchant
  wants *all* the problems in one pass. Production stops on terminal failure; the trace flags
  "production would stop here."
- **Missing entity:** trace-level error up front ("order 99999 not found") — mirrors the
  production skipped-on-missing-entity semantics.
- **Non-simulateable third-party actions:** same fallback the executor uses — record
  `would run <code>` with a visible "this action doesn't support simulation" badge. The SDK docs
  should push connector authors toward implementing `SimulateableActionInterface` (make it part
  of the connectors-program checklist).

## 5. Managing the two-walker divergence risk

This is the recommendation's main liability; controls, in order of leverage:

1. **Share every semantic component.** `DryRunService` must inject and use the production
   `ConditionEvaluator`, `VariableResolver`, `DelayCalculator`, `ActionPool`, `HydrationProvider`,
   and `Definition`. The only novel logic is walk order, time compression, and trace assembly.
   Divergence is then confined to *routing*, which is small and testable.
2. **Conformance fixtures run through both engines.** Extend `spec/fixtures/` with paired
   expectations: for each fixture + synthetic payload, the step *sequence* the executor produces
   (in shadow status, via the existing unit-test harness) must equal the path `DryRunService`
   reports. A new step type (e.g. `switch`, [branching.md](branching.md)) that lands in one
   walker but not the other fails this suite loudly.
3. **Single routing table.** Extract edge-selection (`type` → which config key names the next
   step) into a shared helper on `Definition` so "what edges does a branch have" is written once.
   This is a small, safe extraction — unlike extracting the executor's persistence choreography.

## 6. Architecture

```
Model/DryRun/DryRunService        walk + time compression + path fan-out (wait both-edges)
Model/DryRun/Trace / TraceStep    DTOs: step key, type, status, would, interpolated config,
                                  condition detail, resume-time annotations, path id
        reuses:  ConditionEvaluator · VariableResolver · DelayCalculator · ActionPool ·
                 SimulateableActionInterface · Definition (+ shared edge helper) · GraphValidator

Entry points
  Admin:  Controller Workflow/DryRun (POST: definition JSON as edited + entity_id) → JSON trace
          rendered in a form panel; entity picker backed by a small "recent matching entities"
          query per entity_type
  REST:   POST /V1/workflows/dry-run  {definition, conditions_serialized, entity_type, entity_id}
          (validate-and-simulate; also serves CI)   — plus
          POST /V1/workflows/:id/dry-run {entity_id} for the saved version
  CLI:    workflow:run --dry-run  → runs DryRunService, renders the trace as a console table
          (workflow:run without the flag keeps dispatching real manual executions, unchanged)

Persistence (optional, on by default in admin):
  execution row with new column mode ENUM('live','dry_run') (shadow stays a workflow status;
  live executions of shadow workflows remain mode='live'), status=complete, steps written from
  the trace → the existing execution view renders it; TTL-pruned aggressively (default 7 days,
  separate from the 90-day live retention in 10 — Security §PII containment)
```

**ACL:** a new `MageOS_Workflows::dry_run` resource, granted alongside `::manage` by default.
Dry-run *reads* entities and evaluates conditions against them — that's data access, and it must
not be reachable by someone who can only view workflows. It deliberately does **not** require
`::manual_run` (which gates real side-effectful dispatch).

**Security invariants** (tested, not assumed): no `simulate()` may perform I/O beyond entity
*reads* — add this to the SDK contract docs; secrets interpolation in dry-run resolves to a
redaction marker (`{{ secrets.x }}` → `***x***`), never the value, since traces render in the
browser and persist in trace rows; the webhook simulate path already conforms.

## 7. Quality, maintainability, reliability

- **Zero risk to the production path**: no executor changes at all in the recommended shape
  (the one shared extraction is the edge-routing helper on `Definition`). The queue, resumption,
  and crash-safety story is untouched.
- **Reliability of the feature itself:** synchronous walk bounded by graph size (validator caps
  cycles out), path fan-out capped, hydration per step bounded by the same identity-map used in
  production. Worst case is a slow admin request, never a stuck execution.
- **Testability:** `DryRunService` is pure-ish (DB only via hydration) — unit tests with the
  existing shim harness; the §5 dual-engine conformance suite is the keystone test.
- **Maintainability watch-item:** every new step type must land in walker + trace + renderer.
  Mitigated by the shared routing helper and the conformance suite; documented in the SDK notes
  for step-type authors (currently only core adds step types — the format is versioned).
- **UX quality:** trace rendering reuses the plain-language vocabulary
  (`PlainLanguageRenderer` labels) so the preview reads like the summary the merchant already
  saw; failure lines follow the merchant-readable + "technical details" expander pattern from
  [11 §Failure UX](../11-admin-ui.md#merchant-accessibility--openness).

## 8. Sequencing & effort

| Order | Item | Effort |
|---|---|---|
| 1 | `DryRunService` + trace DTOs + delay/wait compression + tests | ~1.5 wk |
| 2 | Dual-engine conformance fixtures (with [branching.md](branching.md) §2 validator landed first) | ~0.5 wk |
| 3 | CLI `workflow:run --dry-run` + REST endpoints + ACL | ~0.5–1 wk |
| 4 | Admin UI: form button, entity picker, trace panel; optional `mode` column + execution-view reuse | ~1.5–2 wk |

Total ≈ 4–5 wks. Depends on nothing else in Phase 3 (canvas *consumes* dry-run, not vice versa);
benefits from GraphValidator landing first. Ship before the template gallery so "install →
dry-run it → enable" is the gallery's default flow.

## 9. Open questions

1. Persist admin dry-runs by default, or opt-in? Leaning default-on with 7-day TTL — the audit
   value ("who previewed what against which customer's data") outweighs the storage.
2. Should REST dry-run accept a raw *synthetic* payload instead of an entity id (test fixtures in
   CI without production data)? Cheap to add (`trigger_payload` param bypassing hydration for
   snapshot-phase conditions); leaning yes, clearly labeled "snapshot-only fidelity".
3. Leaf-level condition instrumentation (expected vs actual per attribute) — v1.1. Requires a
   decorating validator around the rule conditions; valuable but not gating.
4. Does the both-paths wait exploration need a UI toggle ("assume event arrives" / "assume
   timeout" / "show both")? Prototype with "show both", decide from feedback.
