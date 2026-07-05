# Exploration — Sub-Workflows, Entity-Creation Actions, Long-Spanned Multi-Gate Workflows

**Status: exploratory, pre-discovery.** This document maps what three candidate capabilities
would mean in this architecture, what the codebase already provides, what would be net-new, and
where the hard problems are. It deliberately stops short of the discovery-doc bar
([README](README.md)) — no committed recommendation, no PR-staged plan. If any of the three
advances, it should graduate into its own discovery doc with approach evaluation and estimates.

All three features are already catalogued as explicit boundaries in
[18 — Known Boundaries](../18-limitations.md):

| Feature | Boundary entry | Root constraint |
|---|---|---|
| Sub-workflows | "Reuse one workflow as a sub-routine of another — there's no sub-workflow/invoke primitive" | Through-line 2 (single-graph walk) |
| Entity-creation actions | "Create an order, quote, or customer from scratch — every action mutates an existing entity" | Action-library root cause: "no create/iterate/compute" |
| Long-spanned multi-gate | Human-in-the-loop section (approval chains an explicit non-goal); "wait parks on one named event for the same entity only" | Through-lines 2 & 5 + v1 non-goal |

A recurring finding: the July-2026 wave left seams pointing at all three. The `chain_depth` /
`loop_guard_depth` columns are fully plumbed but never incremented; `FanOutExpander` is a
working reference for dispatching child executions from inside the engine; the `wait` step
already models "park until something external happens, or timeout." Much of what follows is
about *finishing* staged mechanisms rather than inventing new ones.

---

## 1 — Sub-workflows

### What "sub-workflow" could mean (three distinct features)

The term covers three designs with very different cost/semantics profiles:

**S1 — Fire-and-forget invoke step (dispatch a child, don't wait).**
A `call_workflow` step type: resolve config (target `workflow_id`, an input map), call
`Dispatcher::dispatch(targetId, resolvedInputs, triggerType, parentChainDepth + 1)`, record the
child execution UUID as step output, follow `next` immediately. No join, no return values.

**S2 — Invoke-and-await (child completion resumes the parent).**
The parent parks after dispatching the child; child completion wakes it, optionally merging
child outputs back into the parent's `context.steps.<key>`.

**S3 — Inline fragments (compile-time composition, no runtime invocation).**
Reusable step-graph fragments spliced into the parent's `steps` map at **save time** (keys
namespaced), so the executor sees one flat graph and needs zero runtime changes.

### What already exists

The architecture is visibly pre-staged for **S1**:

- **The loop guard is plumbed but dormant.** `DispatcherInterface::dispatch()` carries
  `int $chainDepth = 0`; the Dispatcher rejects `chainDepth > loop_guard_depth`
  (`Dispatcher.php:80-87`); `mageos_workflow.loop_guard_depth` (default 1) and
  `mageos_workflow_execution.chain_depth` exist in the schema. Every current caller passes 0 —
  [fan-out.md](fan-out.md) notes chain-depth is "fully plumbed but today never incremented."
  A sub-workflow step is the intended first consumer.
- **`FanOutExpander` is a reference implementation** of "dispatch child executions from inside
  the engine": target resolution, per-child trigger snapshot, `origin` provenance injection,
  cap with truncate+warn, per-child fail-open try/catch (`Model/Engine/FanOutExpander.php`).
- **Fan-out's deferred F2 (mid-flow `fan_out` step) *is* S1**, already sketched in
  [fan-out.md §F2](fan-out.md) — the doc itself calls it "two deferred concepts (sub-workflows
  + iteration) fused into one step" and lists the required checks (target-ACL re-authorization
  at save, run-time target-status re-check, cross-workflow observability, dry-run rendering).
- **`switch` (schema 3) is the template for adding a step type**: type constant + shape
  validator + `getStepEdges()` branch in `Definition.php`, a handler + routing case in
  `Executor.php`, the published `spec/` schema bump, save-time validation, canvas palette.
- **The `origin` provenance convention** (`{event, entity_type, entity_id, trace_uuid, via}`,
  indexed `origin_uuid`, grid "Caused by" filter) extends naturally to
  `via: sub_workflow` + `parent_execution_id`.

### What would be net-new

| Piece | S1 | S2 | S3 |
|---|---|---|---|
| Schema bump (new step type) | schema 4 | schema 4 | none (authoring-layer expansion) |
| Executor change | one handler (small) | new park state + resume path keyed on child completion | none |
| Return channel (child outputs → parent context) | n/a | **new mechanism** — nothing crosses executions today | n/a |
| Save-time validation | target-ACL re-auth, target existence/status | same + await semantics | fragment repository, key namespacing, cycle check over expanded graph |
| Loop containment | `chain_depth + 1` (existing, dormant) | same | none needed (flattened at save) |

**S2's genuinely new engine mechanism:** the parent must park like `wait` does, but keyed on a
child execution id rather than `(waiting_event, entity_id)`; `Executor::completeExecution()`
already fires a `workflow_execution_complete` event — the natural hook. It also needs an
orphan/failed-child sweeper and the return-channel merge. Single-child await is tractable;
**multi-child join is the fork-join problem already rejected** in
[branching.md](branching.md) and fights the single-`current_step` crash-safety model.

### Hard problems

- **Cross-workflow recursion (A→B→A).** Save-time `GRAPH_CYCLE` DFS only sees one graph.
  Mitigation is the chain-depth guard — but note the default `loop_guard_depth = 1` forbids
  *any* chaining; enabling sub-workflows means deliberately raising it per workflow. Also note
  §2's finding that chain-depth does **not** survive the async-events round-trip — direct
  parent→child dispatch propagates fine, but a child whose *actions* fire events restarts at
  depth 0. The propagation fix is shared prerequisite work (see §4).
- **Child versioning.** Executions pin `definition_snapshot` at dispatch, so a child dispatched
  later runs the child's *current* definition — parent and child can run different vintages,
  and editing the child changes future invocations but not in-flight ones. S3 freezes the
  fragment into the parent snapshot (most predictable, least flexible).
- **ACL.** Authoring an invoke step must re-authorize the acting admin against the **target
  workflow's actions** (reuse the existing `authorizeActionCodes` re-auth at save and on
  import); at run time a disabled/suspended target should be a step failure, not a silent skip.
- **Dry-run** must render "would dispatch workflow X" without recursing (the rule
  fan-out.md already specifies). S2 dry-run is worse — it must stub the child's return.
- **Scope policy** for a parent in one website invoking a child scoped to another (the child's
  own `matchesScope()` guard runs, but the author-facing semantics need defining).

### Reading

S1 is the cheap, pre-staged one — a schema-4 step type reusing the dormant guard, the fan-out
dispatch pattern, and the provenance convention; fan-out.md already scoped its shape. S3 is the
cheapest of all and best matches "reusable fragments," but is composition at authoring time,
not invocation. S2 is where real cost lives (park-on-child + return channel) and is only worth
it pulled by concrete use cases that need child *outputs* — and it partially overlaps the
approval-gate machinery in §3, which wants the same "park until an external completion"
primitive.

---

## 2 — Entity-creation actions

### What already exists

- **The action SDK is genuinely minimal**: one class implementing
  `ActionInterface::execute(ctx, config)` + one `di.xml` array entry into `ActionPool`.
  `SimulateableActionInterface` (all 22 core actions implement it) powers shadow mode and
  dry-run.
- **Creation precedents, each instructive:**
  - `order.create_invoice` / `create_shipment` / `create_creditmemo` — already *create*
    entities, but their idempotency is **state-guard-based**: a redelivery finds
    `canInvoice()`/`canShip()`/`canCreditmemo()` false and returns `skipped`. That works only
    because the created child flips the *parent order's* derived state.
  - `marketing.generate_coupon` — creates a coupon **with no dedupe guard at all**: a queue
    redelivery generates a second coupon. It is the live proof that the framework does not
    protect creation.
  - `notify.email` — the other pattern: a check-and-set cache guard on
    `sha1(dedupeKey)` *before* SMTP, released on transient failure. Best-effort (cache, 7-day
    TTL), not durable.
- **Constraint scaffolding**: group-level authoring ACL, circuit breaker (consecutive
  *failures* → suspend), dispatch debounce on `(workflow_id, entity_id)`, bulk-import
  suppression, fan-out/manual-run caps, per-attribute **denylists** (deny-by-default,
  di.xml-extendable) on the `set_attribute` actions, and the restricted resolver's
  "interpolation supplies values, never structure" invariant.

### What "reasonable enforced constraints" would require (net-new)

1. **A durable idempotency ledger.** The executor guarantees at-least-once (state persisted
   before side effects; failures rethrow for redelivery) and enforces no dedupe generically —
   `ctx->getDedupeKey(stepKey)` exists but only 2 of 22 actions consult it. From-scratch
   creation has no parent state to guard it, so it needs a check-and-set **in the same
   transaction as the create** (a small `(dedupe_key → created_entity_id)` table), with the
   created id re-surfaced into step output on redelivery.
2. **Loop containment that actually holds.** The documented chain-depth propagation ("via a
   registry flag on the publisher", [07 §guards](../07-actions.md)) is **not implemented on
   the live path**: `WorkflowNotifier::notify()` dispatches with `chainDepth` defaulting to 0,
   and the created entity's event round-trips through the async-events queue in a separate
   consumer process, where no request-scoped registry survives. Today, a workflow triggering
   on `order.created` that creates an order would loop:
   - debounce doesn't catch it (the new order has a different `entity_id`),
   - the circuit breaker doesn't (successful creations never fail),
   - the loop guard doesn't (depth resets to 0 every round-trip).
   Closing this — e.g. threading depth/provenance through the event payload the way `origin`
   already stamps fan-out children, or a create-provenance marker the notifier suppresses on —
   is **prerequisite work**, and it is the same fix sub-workflows need (§4).
3. **A creation budget.** Nothing counts *successful* creations; a per-workflow
   "max N creates per window" guard (alongside debounce and the circuit breaker in the
   Dispatcher/Executor guard stack) is net-new.
4. **Allowlist field-map validation.** Current actions take flat scalar config validated ad hoc
   in `execute()`; there is no declarative schema and no nested-structure validation. A create
   action takes a merchant-authored *field map* whose **keys must be a static allowlist**
   (inverting the denylist model — the denylist's blocked fields `email`, `group_id`,
   `website_id`, `store_id` are exactly the identity/scope fields a create must instead
   require and validate), with per-field type coercion, because values are
   attacker-influenceable (e.g. captured webhook responses). EAV entities add required
   attributes, attribute-set and website/store scoping — no precedent in the pool.
5. **Honest `simulate()` + documented non-compensation.** `simulate()` must run full validation
   and return a placeholder output (the `SIMULATED-COUPON` pattern) so downstream
   interpolation works, and must never create (dry-run invariant: no `simulate()` performs I/O
   beyond entity reads). And the execution model has **no rollback/compensation**: a workflow
   that creates an entity then fails downstream leaves it orphaned. That is a design
   constraint to document per action, not to solve.

### Scope shaping

The constraint burden differs sharply by entity. Creations **anchored to an existing entity**
(the trigger entity supplies identity and most required fields) inherit a natural guard and a
natural dedupe scope: RMA-from-order, store-credit/gift-card issuance, customer-from-guest-order
(the relation registry's flagship already *detects* that case), reorder-draft-quote-from-order.
"Order from scratch" sits at the far end: full EAV/address/payment validation, no anchoring
state, maximal loop exposure. A staged pool — anchored creations first, each with the ledger +
budget + allowlist — matches how the July-2026 wave relaxed through-lines at seams rather than
removing them.

---

## 3 — Long-spanned multi-gate workflows

### What already exists

The park/resume spine is solid and mostly reusable:

- **Two parking primitives** — `delay` (parks with `resume_at`, `current_step` advanced past
  it) and `wait` (schema 2: parks *on* the step with `waiting_event`, races event-match vs
  timeout; both wake paths claim atomically via conditional `waiting → pending` UPDATE and
  converge on the `workflow.resume` consumer).
- **Re-checks after a gate** — `revalidate_entity` re-hydrates the entity fresh at resume
  ("if still unpaid"), with the `GRAPH_POST_DELAY_STALE` save-time warning guarding the
  footgun.
- **Long-span durability** — executions pin `definition_snapshot` (a parked execution resumes
  into the exact graph it started in, even if the workflow was edited or deleted);
  missing-entity at resume is `skipped`, never an error retry; retention pruning keys on
  `completed_at IS NOT NULL` and never touches parked rows.
- **Per-hop ceiling** — `resume_at` is clamped to `max_delay_days` (default 365). Hops chain,
  so multi-gate flows can span years across steps; each single park is capped at ~1 year.

### What's missing, and the plausible shape of each piece

**G1 — Human approval gate.** *Graduated to its own discovery doc:
[approval-gate.md](approval-gate.md).* Today's only human touchpoint is a broadcast admin notification —
not a gate (nothing parks, no accept/reject, no assignee); approval-chain UI is an explicit v1
non-goal. But the `wait` step already models the semantics, so the design is a near-clone:

- a gate step (or `wait` variant) that parks with SLA timeout as `resume_at`;
- a `mageos_workflow_approval_task` table (execution_id, step_key, assignee, status,
  decided_by/at, note) written at park — the analog of `waiting_event`;
- an Approvals grid + approve/reject controller calling a `Dispatcher::resumeApproval()`
  modeled on `resumeWaiting()` (atomic claim, write `{resolution: approved|rejected, by,
  note}` into the step result, publish resume);
- `ResumeConsumer` already routes on the step result's `resolution` — extend to
  `on_approved` / `on_rejected` / `on_timeout` edges. **SLA + escalation falls out of the
  existing timeout path** (the wait's `resume_at` *is* the breach clock; `on_timeout` = the
  escalation branch; tiers = chained gate steps).

Reversing the non-goal is a strategy decision, but the engine cost is modest; the real cost is
the admin surface (below).

**G2 — Multi-event / cross-entity waits.** `wait` matches one
`(workflow_id, waiting_event, entity_id)` tuple. Generalize `waiting_event` into a child
subscription table (N rows per parked execution: event name + entity id); first match wins the
same atomic claim. Keeps one execution, one park, single-graph walk intact.

**G3 — Inbound callback wait.** The park mechanism needs nothing; the missing piece is an
inbound surface (through-line 5 is currently outbound-only by design). A minimal signed
`POST /V1/workflows/executions/:uuid/callback` keyed on the execution UUID would close
"continue after a vendor's async callback."

**G4 — Persistent per-customer journeys** (goal/exit conditions across many trigger events —
the AutomateWoo/Klaviyo model). This is the genuinely large one: it breaks the per-event
execution model, and the executor hard-forbids cycles, so a journey cannot be "one execution
that loops." It needs a journey-state subsystem keyed by `(workflow, customer)` that the
dispatcher consults — a new pillar, not a reshuffle. Out of scope for a first multi-gate wave.

### Hard problems at long horizons

- **The RabbitMQ parking path is the liability, not the recommendation.** DLX+TTL parking was
  designed for retry backoff (seconds–minutes); a message parked for months–a year is exposed
  to broker restarts, queue migrations, and message-store growth. The DB sweeper
  (`resume_at` row + 1-minute cron) is architecturally correct for long parks — the docs
  currently recommend AMQP. A span threshold above which resumption always rides the
  DB-scheduled path (regardless of backend) is probably the right call.
- **Snapshot staleness.** `revalidate_entity` makes re-hydrated conditions trustworthy, but any
  condition or interpolation against frozen `context.trigger.*` can be months stale, with no
  staleness signal to the author. A save-time warning (long waits + snapshot-mode conditions
  downstream) is cheap; a general answer is not.
- **No migration for parked executions.** The pinned-snapshot invariant means a fixed
  definition never reaches the thousands of executions parked on the buggy one — and there is
  currently **no cancel/nudge/manual-continue surface at all** (`STATUS_CANCELLED` exists on
  the interface; nothing in the engine sets it). Bulk inspect/cancel of parked cohorts is a
  prerequisite for month-long flows, independent of any gate feature.
- **Observability of parked fleets.** `workflow:stats` counts waiting steps and the
  `overdue_resumes` health check detects a *stuck resume path* — neither distinguishes 10k
  healthily-parked executions from a broken workflow. A parked-execution grid (filter by
  `waiting_event`/`resume_at`/assignee, per-row "what is this waiting on / when does it wake")
  and the approvals inbox are **prerequisites, not fast-follows**, for a park-heavy product.
- **PII retention while parked.** Context rows carry PII and legitimately outlive the 90-day
  retention window while parked; GDPR erasure for in-flight executions is already a documented
  GA blocker and gets worse with month-long spans.

---

## 4 — Cross-feature observations

- **One shared prerequisite: chain-depth/provenance propagation across the async-events
  boundary.** Sub-workflows (§1) rely on the guard for A→B→A containment; entity creation
  (§2) needs it to contain self-triggering creation loops. It is one fix consumed by both —
  the natural candidate for a `00-foundations`-style shared stage if either advances.
- **S2 (invoke-and-await) and G1 (approval gates) want the same new primitive** — "park until
  an external completion resumes me with a payload" — differing only in who resolves it (a
  child execution vs an admin decision vs an inbound callback in G3). If more than one of
  these advances, they should share the park/claim/resume-with-result mechanism rather than
  grow three variants.
- **The features compose**: approval gate + creation action ("create the credit memo only
  after a human approves"), sub-workflow + creation (a reusable "issue goodwill credit"
  routine), long-span + wait ("net-30 dunning with escalation"). The composition argument may
  justify infrastructure (the ledger, the parked-execution grid) that no single feature
  justifies alone.
- **Cheapest credible slice per feature**: S1 fire-and-forget invoke (fan-out F2, already
  scoped ~2–3 wk there); one anchored creation action (e.g. customer-from-guest-order) carried
  by the ledger + budget + allowlist infrastructure; G1 approval gate with its inbox. Each
  relaxes a different through-line at a seam the architecture already points to.
