# 18 — Known Boundaries: Flows the Engine Does *Not* Support (Yet)

A companion to [17 — Use Cases](17-use-cases.md). Where that document catalogs what
merchants and agencies *can* build, this one maps the walls they'll hit — ~60 flows a
typical (or atypical) merchant might reasonably want that the engine currently would
**not** support, each with the architectural reason why.

This is reference material for future analysis, scoping, and roadmap input — not a
backlog commitment. Many entries trace to explicit v1 non-goals ([01 — Overview
§Non-goals](01-overview.md#non-goals-for-v1)) or already-deferred scope ([13 — Delivery
Plan](13-delivery-plan.md), [16 — Capability Roadmap](16-capability-roadmap.md)); those
are noted so this doubles as a gap-to-roadmap cross-reference.

## The five structural through-lines

Most individual gaps below trace back to one of these root constraints. Read these
first; the per-flow "why" clauses reference them.

1. **Async, post-event — never synchronous.** The engine reacts *after* a Magento event
   fires. It can't sit inline in a live storefront session or checkout. → kills the
   entire class of real-time / checkout / storefront decisioning.
2. **No loops, no fan-out, no sub-workflows.** The executor walks a single
   `next`/`branch` graph per execution ([08 — Execution Model](08-execution-model.md));
   iteration over collections is an explicit non-goal. → kills per-item, per-collection,
   and reusable-journey patterns.
3. **Four condition roots + `async_events.xml` event coverage.** Roots are
   `sales_order`, `customer`, `quote`, `catalog_product`; triggerable events equal what
   is declared in async-events XML. → anything outside those (wishlist, RMA, reward
   points, CMS, subscriptions) is invisible.
4. **Restricted variable resolver.** Dot-path access plus a fixed formatter whitelist
   (`upper/lower/trim/number/date/default`), no expressions ([07 — Actions §Variable
   resolution](07-actions.md#variable-resolution)). → no computed values; anything
   needing math or conditional content must be pushed to an external webhook.
5. **Webhook-only, outbound-only egress.** The single external integration surface is
   the sync-POST webhook action; triggers are Magento events / schedule / manual. → no
   inbound triggers, no async callbacks, no non-HTTP transports.

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

- **Loop over order line items and act per item** — iterators over collections are an explicit v1 non-goal.
- **Fan out from one trigger to N related entities** (e.g., act on every open order of a customer) — no collection fan-out.
- **Run steps in parallel and join** — the graph is a linear walk with branches; no parallel/fork-join.
- **Reuse one workflow as a sub-routine of another** — there's no sub-workflow/invoke primitive.
- **Maintain a long-lived per-customer journey with a goal/exit condition** across many events — executions are per-event; there's no persistent multi-trigger journey state (the AutomateWoo/Klaviyo model).
- **Wait until a customer does X *or* Y across different entities** — `wait` parks on one named event for the *same* entity only ([04 — Definition Format](04-definition-format.md)).
- **Batch an event storm into one action** ("email me everything that stocked out today") — aggregate/batch triggers are deferred to Phase 2 ([07 — Actions §Bulk-operation suppression](07-actions.md#loop-prevention-storms-and-circuit-breaking)); v1 is strictly per-entity.
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
- **Conditional text inside a message** ("if VIP say X else Y") — no in-template conditionals; must be modeled as separate branch steps.
- **Compare or normalize across currencies** — totals are in store currency with no conversion, so cross-currency thresholds are apples-to-oranges.
- **Condition on aggregate catalog facts** ("if category X has < 5 in-stock SKUs") — conditions evaluate one entity; the only cross-entity aggregates are the fixed customer order-history set ([06 — Conditions](06-conditions.md)).
- **Use external data directly in a trigger condition** — external data can only enter mid-flow via a webhook's captured response, not at trigger time.

## External integration

*Root cause: through-line 5 (webhook-only, outbound-only).*

- **Let an external system start a workflow by calling in** — there's no inbound trigger endpoint; the REST API covers CRUD and execution *reads*, not execution *starts*.
- **Integrate over anything but HTTP POST** — no GET/PUT, no SOAP/GraphQL client, no SFTP/file, no direct DB or queue egress.
- **Consume a message off a queue/topic to trigger work** — no arbitrary pub/sub consumer.
- **Two-way sync with conflict resolution** against an external system — the engine is one-directional fire-and-capture.
- **Trigger from a nightly file/SFTP drop with per-row logic** — no file-based trigger and no per-row iteration.
- **Consume Adobe I/O Events / App Builder events** — Adobe I/O interop is an explicit non-goal.

## Human-in-the-loop

*Root cause: approval-chain UI is an explicit non-goal; the only human touchpoint is a notification.*

- **Multi-step approval chains with an approver UI** (sign-offs, reassignment) — explicitly out of scope.
- **Assign a task to a specific admin with accept/reject/reassign** — no task surface beyond a broadcast admin-inbox notification.
- **First-class SLA timers with tiered escalation and breach tracking** — approximable with delay+notify, but there's no native SLA/escalation construct.

## Channels, marketing depth & intelligence

- **Send SMS or push natively** — channels are transactional email, webhook, and admin inbox; SMS/push need an external gateway via webhook.
- **A/B tests or holdout groups** on an automation — no experimentation/variant framework.
- **Honor a central multi-channel consent/preference center** — only newsletter subscribe/unsubscribe is modeled.
- **Predictive/ML segmentation** (churn, propensity, next-best-offer) — conditions are deterministic rules; scoring only exists if you call an external service via webhook.
- **Trigger on trends/time-series** ("revenue down 15% WoW") — conditions match one entity's current state, not store-wide trends over time.
- **Identity resolution / merging guest orders into a profile** — no identity graph; customer aggregates key off existing customer records only.

## Deferred platform & B2B capabilities

*Root cause: named deferred scope ([13](13-delivery-plan.md), [16](16-capability-roadmap.md), [12](12-b2b.md)).*

- **Anything in the B2B pack** (PO approvals, negotiable-quote routing, company credit, requisition lists) — the pack is deferred, not shipped.
- **A drag-and-drop canvas** for non-technical authors of complex branching — v1 is forms + a JSON editor; the canvas is deferred.
- **A template gallery / one-click recipe install** — deferred to Phase 3.
- **A safe interactive dry-run/preview UI** — shadow mode exists at the engine level, but the dry-run *UI* is deferred.

---

## How to read this against the roadmap

- **Explicit non-goals** (storefront, loops/iterators, approval-chain UI, Adobe I/O interop) are deliberate scope boundaries, not oversights — revisiting them is a strategy decision, not a bug fix.
- **Deferred scope** (B2B pack, canvas, template gallery, dry-run UI, aggregate triggers) is already on the roadmap; those flows unlock as that scope lands.
- **Structural limits** (through-lines 1–5) are the load-bearing ones: each blocks a whole *class* of flows, so the highest-leverage roadmap questions are about those, not any single bullet.
</content>
