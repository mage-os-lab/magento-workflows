# 18 — Known Boundaries: Flows the Engine Does *Not* Support (Yet)

A companion to [17 — Use Cases](17-use-cases.md). Where that document catalogs what
merchants and agencies *can* build, this one maps the walls they'll hit — ~60 flows a
typical (or atypical) merchant might reasonably want that the engine currently would
**not** support, each with the architectural reason why.

This is reference material for future analysis, scoping, and roadmap input — not a
backlog commitment. Many entries trace to explicit v1 non-goals ([01 — Overview
§Non-goals](01-overview.md#non-goals-for-v1)) or already-deferred scope ([13 — Delivery
Plan](13-delivery-plan.md), [16 — Capability Roadmap](16-capability-roadmap.md)); those
are noted so this doubles as a gap-to-roadmap cross-reference. The entity-coverage subset of
these gaps (through-line 3 and the action-library list) is analyzed in depth — per core entity,
with an agent-sized implementation backlog — in
[discovery/core-coverage.md](discovery/core-coverage.md).

> **Revised July 2026.** A capability wave since the first draft — the wave 1–5 roadmap
> ([16](16-capability-roadmap.md)) plus the follow-on discovery-track build
> ([docs/discovery/](discovery/README.md)) — closed a number of the flows originally listed
> here: branching (`switch` + graph validation), entity cross-referencing, dry-run,
> trigger-level fan-out, batch aggregation, the template gallery, and the visual canvas.
> Those are collected under [Recently closed](#recently-closed-july-2026-capability-wave)
> and removed from the per-section gap lists below, which now describe only what remains
> genuinely unsupported.

## The five structural through-lines

Most individual gaps below trace back to one of these root constraints. Read these
first; the per-flow "why" clauses reference them.

1. **Async, post-event — never synchronous.** The engine reacts *after* a Magento event
   fires. It can't sit inline in a live storefront session or checkout. → kills the
   entire class of real-time / checkout / storefront decisioning.
2. **No loops, no fork-join, no sub-workflows — the executor walks one graph.** Each
   execution walks a single `next`/`branch`/`switch` graph ([08 — Execution
   Model](08-execution-model.md)); iteration over collections is an explicit non-goal.
   *Dispatch-layer* fan-out (one trigger → N single-entity executions) and batch
   aggregation (N events → one digest execution) landed in July 2026 and sit in front of
   the executor, but the executor itself still never loops, forks and joins, or invokes
   another workflow. → still kills in-graph per-item iteration, parallel/join, and
   reusable sub-routines.
3. **Four condition roots + `async_events.xml` event coverage.** Roots are
   `sales_order`, `customer`, `quote`, `catalog_product`; triggerable events equal what
   is declared in async-events XML. The July 2026 relation registry lets a condition
   cross-reference a *registered* related entity (e.g. a guest order → the customer account
   matching its email), but the trigger and root set is unchanged. → anything outside those
   roots (wishlist, RMA, reward points, CMS, subscriptions) is still invisible.
4. **Restricted variable resolver.** Dot-path access plus a fixed formatter whitelist
   (`upper/lower/trim/number/date/default`, plus `count/pluck/join/table/json` for
   collections in batch digests), no expressions ([07 — Actions §Variable
   resolution](07-actions.md#variable-resolution)). → no computed values; anything
   needing math or conditional content must be pushed to an external webhook.
5. **Webhook-only, outbound-only egress.** The single external integration surface is
   the sync-POST webhook action; triggers are Magento events / schedule / manual. → no
   inbound triggers, no async callbacks, no non-HTTP transports.

---

## Recently closed (July 2026 capability wave)

Flows this document originally listed as unsupported that have since been implemented — by
the wave 1–5 roadmap ([16](16-capability-roadmap.md)) and the follow-on discovery-track
build ([docs/discovery/](discovery/README.md)). They ship behind default-off flags where
they add runtime behavior and are shim-tested; live-install verification remains the GA gate
([16 §Still deferred](16-capability-roadmap.md#still-deferred)). The gap lists
below no longer include them.

- **Multi-way branching** — a `switch` step (schema 3, first-match-wins with a `default`
  fall-through) plus save-time graph validation (cycle / unreachable-step / dead-edge).
  [01 — Branching](discovery/implementation/01-branching.md). *Parallel fork-join is still
  not supported.*
- **Entity cross-referencing** — a DI-registered relation registry and a generic
  EXISTS / NOT-EXISTS related-entity condition (`order.customer`, `order.customer_by_email`,
  `order.orders_by_email`, `quote.customer_by_email`, `customer.open_orders`). Flagship: a
  guest order whose email has **no** customer account → send a registration invite.
  [02 — Cross-referencing](discovery/implementation/02-entity-cross-referencing.md).
- **Dry-run** — a synchronous, side-effect-free "what would this do to entity X now," over
  the production evaluator/resolver, via CLI (`--dry-run`), REST
  (`POST /V1/workflows/dry-run`), and an admin trace panel.
  [03 — Dry-run](discovery/implementation/03-dry-run.md).
- **Fan-out** — one trigger → N ordinary single-entity executions over a declared relation
  (e.g. every open order of a customer), capped and default-off, each child carrying the
  full guard stack. *Trigger-level only — no mid-flow fan-out step and no join/fan-in.*
  [04 — Fan-out](discovery/implementation/04-fan-out.md).
- **Batch aggregation** — N events → one digest execution (scheduler collected-mode and
  event-window accumulator), with `count/pluck/join/table/json` collection formatters —
  "email me everything that stocked out today."
  [05 — Batch aggregation](discovery/implementation/05-batch-aggregation.md).
- **Template gallery** — an admin gallery + CLI over 14 bundled recipes, installed
  (parameterized) in shadow/disabled mode on an install → dry-run → enable path. *A signed
  remote feed stays deferred.* [06 — Template gallery](discovery/implementation/06-template-gallery.md).
- **Canvas** — an optional React-Flow package: a read-only viewer with execution and
  dry-run overlays, plus a full drag-and-drop editor (palette, connect, config,
  undo/redo, validate + save). [07 — Canvas](discovery/implementation/07-canvas.md).
- **Workflows on native entity grids** — an addon surfacing per-entity workflow counts and
  view/create deep links on the Orders / Customers / Products grids
  ([issue #5](https://github.com/mage-os-lab/magento-workflows/issues/5)).
  [entity-grid-visibility](discovery/entity-grid-visibility.md).
- **Approval / decision gate** — an `approval` step (schema 4) that parks on the wait spine
  and routes on a human decision: `on_approved` / `on_rejected` / a required-timeout
  `on_timeout`. A decision comes from the admin approvals grid or an authenticated REST
  endpoint, carries an optional validated payload into step output (e.g. an approver-entered
  amount), and tiers of sign-off are modeled as chained gate steps. Packaged as a thin core
  seam + optional `mage-os/workflows-approvals` addon.
  [approval-gate](discovery/approval-gate.md).

What remains genuinely unsupported is below.

---

## Storefront & real-time / synchronous decisioning

*Root cause: through-line 1 (async post-event) + storefront is an explicit non-goal.*

- **Block or reject an order at checkout before payment** — the engine reacts after the order event; it can't gate placement inline.
- **Show/hide or reprice shipping methods by rule at checkout** — no checkout-time hook; storefront is out of scope.
- **Gate which payment methods appear** based on cart/customer rules — same reason: no synchronous checkout decisioning.
- **Add a free gift to the cart when the total crosses a threshold** — needs a live cart mutation on the storefront; there's no cart-write action.
- **Auto-apply a coupon to an in-session cart** — the engine can *generate* a coupon but can't apply it to a live cart.
- **Render a segment-specific banner or content block on-site** — the engine mutates back-office entities and sends messages; it has no storefront surface.
- **Personalize on-site product recommendations** — storefront personalization is explicitly out.
- **Browse-abandonment nudges** (viewed a product, never added) — depends on storefront behavioral tracking the engine doesn't collect; triggers are entity events only.
- **React to login, failed login, or on-site search / zero-result searches** — these aren't in the covered async-event set and aren't entity-state changes.

## Flow control & orchestration

*Root cause: through-line 2 (single-graph walk, no iteration) + per-event execution scope.*

- **Loop over order line items and act per item** — iterators over collections are an explicit v1 non-goal. (Trigger-level [fan-out](#recently-closed-july-2026-capability-wave) dispatches one execution *per related entity*, but there is still no in-graph iteration over an entity's own collection.)
- **Run steps in parallel and join** — the graph is a linear walk with branches (`branch`/`switch`); no parallel/fork-join. (Fan-out dispatches N independent executions but never joins their results.)
- **Reuse one workflow as a sub-routine of another** — there's no sub-workflow/invoke primitive.
- **Maintain a long-lived per-customer journey with a goal/exit condition** across many events — executions are per-event; there's no persistent multi-trigger journey state (the AutomateWoo/Klaviyo model).
- **Wait until a customer does X *or* Y across different entities** — `wait` parks on one named event for the *same* entity only ([04 — Definition Format](04-definition-format.md)).
- **Global frequency capping across all workflows** — debounce is per `(workflow, entity)`; there's no cross-workflow comms governor, so a customer can be hit by five workflows at once.
- **Continue only after a vendor's async callback returns** — `wait` resumes on Magento events, never on an inbound external call.

## Entity & domain coverage

*Root cause: through-line 3 (four roots + async-events coverage).*

- **Wishlist automations** (added, price-dropped, wishlist back-in-stock) — wishlist is neither a root nor a covered trigger.
- **Condition on reviews as data** ("customers with 3+ reviews") — review is a trigger only, not a queryable root.
- **Drive flows from CMS/content changes** — no CMS entity coverage.
- **React to category membership changes** — category isn't a trigger entity (only an action target).
- **Returns/RMA lifecycles** — Commerce-only and "if present"; not a first-class root or action set.
- **Store-credit and reward-points earn/burn rules** — no roots or actions for these Commerce features.
- **Gift-card issuance/redemption** — not in the action pool or roots.
- **Carrier tracking / delivery-status flows** — those are inbound carrier events, not Magento entity changes.
- **ESP engagement triggers** (email opens/clicks, link unsubscribes) — that data lives in the ESP, not in Magento events.
- **Subscription / recurring-order logic** — Magento Open Source has no subscription entity to watch or mutate.
- **Rental/booking or digital-license-delivery flows** — no booking entity and no license-key/fulfillment action for atypical models.

## Action-library gaps

*Root cause: the pool mutates ~22 aspects of *existing* single entities; no create/iterate/compute.*

- **Create an order, quote, or customer from scratch** — every action mutates an existing entity; there's no "create" action.
- **Add, remove, or swap order line items** — no order-item mutation (and no iteration).
- **Issue a partial or computed refund** (e.g., 10% goodwill) — credit-memo is *offline full refund only*, and the resolver can't compute an amount anyway.
- **Split a shipment across MSI sources by rule** — the shipment action is single-shot with no per-source allocation logic.
- **Reprice a whole category in one action** — pricing actions are per-product; bulk is a storm risk, not a native batch op.
- **Generate/attach a document or PDF** (custom packing slip, certificate) — no document-generation action.
- **Manage related/cross-sell/up-sell product links by rule** — no relation-management action.
- **Adjust tax, duty, or landed cost** on an order — no tax/adjustment action.
- **Re-order or clone a past order** for a customer — no order-creation/clone action.

## Computation & data shaping

*Root cause: through-line 4 (restricted resolver).*

- **Arithmetic in a value** ("total × 0.1", "days until expiry") — no math or expression language.
- **String transforms beyond the whitelist** (regex, split, concat logic) — not available in interpolation.
- **Conditional text inside a message** ("if VIP say X else Y") — no in-template conditionals; must be modeled as separate `branch`/`switch` steps.
- **Compare or normalize across currencies** — totals are in store currency with no conversion, so cross-currency thresholds are apples-to-oranges.
- **Condition on aggregate catalog facts** ("if category X has < 5 in-stock SKUs") — conditions evaluate one entity; the only cross-entity reach is the fixed customer order-history aggregates and the [relation registry](#recently-closed-july-2026-capability-wave)'s registered EXISTS/NOT-EXISTS lookups, not arbitrary aggregate queries ([06 — Conditions](06-conditions.md)).
- **Use *external* data directly in a trigger condition** — external data can only enter mid-flow via a webhook's captured response, not at trigger time. (A related *Magento* entity's data can now enter a trigger condition via the relation registry; external data still cannot.)

## External integration

*Root cause: through-line 5 (webhook-only, outbound-only).*

- **Let an external system start a workflow by calling in** — there's no inbound trigger endpoint; the REST API covers CRUD and execution *reads*, not execution *starts*. (An external tool *can* now **resume** an execution already parked on an [approval gate](#recently-closed-july-2026-capability-wave), by posting a decision to the authenticated `/V1/workflow-approvals/:uuid/decision` endpoint — but that advances an in-flight execution, it does not *start* a new one.)
- **Integrate over anything but HTTP POST** — no GET/PUT, no SOAP/GraphQL client, no SFTP/file, no direct DB or queue egress.
- **Consume a message off a queue/topic to trigger work** — no arbitrary pub/sub consumer.
- **Two-way sync with conflict resolution** against an external system — the engine is one-directional fire-and-capture.
- **Trigger from a nightly file/SFTP drop with per-row logic** — no file-based trigger and no per-row iteration.
- **Consume Adobe I/O Events / App Builder events** — Adobe I/O interop is an explicit non-goal.

## Human-in-the-loop

The [approval / decision gate](#recently-closed-july-2026-capability-wave) reverses what this
section used to say: a workflow can now *stop and branch on what a human decides*, not just
notify. What v1 ships, and what still doesn't exist, per
[approval-gate.md](discovery/approval-gate.md):

- **Single-gate sign-off with an approver UI** — the `approval` step type, an admin approvals
  grid (view / decide split by ACL), and a REST decision endpoint for external tools (Slack
  bots, middleware). *Ships.*
- **Tiered approval chains** — modeled as **chained gate steps**, each with its own
  timeout/escalation edge, not as a dedicated chain object. *Ships, as composition — there is
  no multi-tier "chain" construct to author in one step.*
- **A required SLA timer with a timeout branch** — `timeout` is mandatory on every gate (no
  indefinite parks); `on_timeout` is an ordinary edge, so escalation is "chain another gate" or
  "notify + stop," authored like any other branch. *Ships, at the single-gate granularity —
  there is still no native tiered-escalation *construct* (e.g. "escalate after 4h, then again
  after 24h" as one declared policy) beyond composing steps for it.*
- **Per-decider payload entering the flow** — a gate can declare `payload_fields`, allowlisted
  and type-coerced into the decision, so an approver-entered value (e.g. a partial refund
  amount) flows into a downstream action's config. *Ships* — the one sanctioned way a
  human-entered value enters a running execution.
- **Reassignment / delegation of an open task** — a task targets a role (`assignee_role`), not
  an individual, and there is no "hand this off to someone else" affordance. *Out of scope.*
- **Reminder pings before timeout** — a reminder is a delay+notify step the author can already
  build manually; there's no native "nudge the assignee at T-minus-N" construct. *Out of
  scope for v1 — revisit if merchants ask.*
- **A general task-management surface** — the task exists to resolve one parked step and
  nothing else: no ad-hoc tasks, no task list independent of a workflow, no due-date-only
  reminders unrelated to a gate. *Out of scope.*

## Channels, marketing depth & intelligence

- **Send SMS or push natively** — channels are transactional email, webhook, and admin inbox; SMS/push need an external gateway via webhook.
- **A/B tests or holdout groups** on an automation — no experimentation/variant framework.
- **Honor a central multi-channel consent/preference center** — only newsletter subscribe/unsubscribe is modeled.
- **Predictive/ML segmentation** (churn, propensity, next-best-offer) — conditions are deterministic rules; scoring only exists if you call an external service via webhook.
- **Trigger on trends/time-series** ("revenue down 15% WoW") — conditions match one entity's current state, not store-wide trends over time.
- **Identity resolution / merging guest orders into a profile** — the [relation registry](#recently-closed-july-2026-capability-wave) can now *detect* that a guest order's email matches an existing customer (and count that email's prior orders), but there is still no identity graph that *merges* guest activity into a unified profile; customer aggregates key off existing customer records only.

## Deferred platform & B2B capabilities

*Root cause: named deferred scope ([13](13-delivery-plan.md), [12](12-b2b.md)). The canvas,
template gallery, and dry-run UI listed here originally have since shipped — see
[Recently closed](#recently-closed-july-2026-capability-wave).*

- **Anything in the B2B pack** (PO approvals, negotiable-quote routing, company credit, requisition lists) — the pack is deferred, not shipped. No `module-workflows-actions-b2b` / `-triggers-b2b` exists; the only B2B artifact is a single *edition-gated* net-terms-reminder template that runs on generic `sales.invoice.created` + `notify.email` (its card greys out on Community).
- **A signed remote template feed** — the [template gallery](#recently-closed-july-2026-capability-wave) ships a bundled pack only; a `RemoteTemplateSource` with signature verification stays deferred behind the `TemplateSourceInterface` seam.
- **A packaged connectors program / connector marketplace** — a custom action is still one class plus a `di.xml` entry (the connector SDK); there is no curated connectors program.

---

## How to read this against the roadmap

- **Explicit non-goals** (storefront, loops/iterators, fork-join, approval-chain UI, Adobe I/O interop) are deliberate scope boundaries, not oversights — revisiting them is a strategy decision, not a bug fix.
- **Recently landed** (branching/`switch`, cross-referencing, fan-out, batch aggregation, dry-run, template gallery, canvas — see [Recently closed](#recently-closed-july-2026-capability-wave)) closed a swath of the original list; they are engine-complete behind default-off flags, with live-install verification the remaining GA gate ([16](16-capability-roadmap.md)).
- **Still deferred** (the B2B pack, a signed remote template feed, a connectors program) is on the roadmap; those flows unlock as that scope lands.
- **Structural limits** (through-lines 1–5) are the load-bearing ones: each blocks a whole *class* of flows, so the highest-leverage roadmap questions are about those, not any single bullet. The July 2026 wave *relaxed* two of them at their seams — cross-referencing widened root #3's reach to registered relations, and dispatch-layer fan-out/aggregation worked around #2 without touching the single-graph executor — rather than removing them.
</content>
