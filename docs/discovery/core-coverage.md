# Discovery — Magento Core Coverage Map (triggers · conditions · actions)

**Status:** Analysis / backlog input. **Scope:** Magento Open Source core entities only — no B2B
(deferred per [12](../12-b2b.md)), no Commerce-only features (RMA, gift cards, store credit,
reward points, customer segments, content staging).

This document answers one question: **what would near-comprehensive coverage of Magento core look
like, per entity, compared to what ships today** — where "coverage" means the data changes that
happen in a store's normal operations become triggers, the entity state a merchant reasons about
becomes conditions, and the safe back-office mutations become actions. It ends in a flat,
agent-sized [implementation backlog](#the-backlog).

## Method and ground rules

For each core entity we ask three questions:

1. **Triggers** — what changes about this entity during normal operations (orders flowing,
   customers registering, catalog upkeep, moderation), and is each change observable as a trigger?
2. **Conditions** — can a workflow interrogate the state of this entity (and its cheap
   aggregates/relations) that a merchant would naturally condition on?
3. **Actions** — can a workflow perform the back-office mutations an admin would perform on this
   entity by hand?

Constraints carried over from the architecture (the [five structural
through-lines](../18-limitations.md#the-five-structural-through-lines)) — items that would violate
them are *excluded*, not listed as gaps:

- **No storefront/synchronous surface** — no checkout gating, no live-cart mutation, no
  login/search behavioral triggers.
- **No create-from-scratch actions** (create order/customer/quote) and no in-graph iteration —
  the pool mutates existing single entities.
- **Webhook-only egress** — SMS/push/ESP stay behind the webhook action.
- **"Expressible already" is not a gap.** Where a flow is reachable through an existing trigger +
  Trigger Data condition (e.g. *order canceled* = `sales.order.status_changed` +
  `to_status = canceled`), we don't add a redundant trigger; the map notes the recipe instead.
  Redundant convenience triggers are UI sugar, and each one is another publisher to maintain.

**Also deliberately out of scope** (not merchant automation, or not entity-state changes):
admin-user/security auditing, system-config changes, indexer/cron health, URL rewrites, sitemap,
carrier tracking webhooks (inbound), ESP engagement events.

## Current surface (July 2026)

- **Triggers (12 events + schedule + manual):** order created/updated/status-changed,
  invoice/shipment/creditmemo created, customer created/updated/group-changed, review submitted,
  stock threshold crossed, cart abandoned. Two scheduler detectors (abandoned cart, stock
  threshold). Fan-out and batch aggregation at the dispatch layer.
- **Condition roots (4):** `sales_order` (25 flat attributes + items ANY/ALL + customer subtree),
  `customer` (EAV auto-discovery + 5 order-history aggregates), `quote` (12 flat attributes),
  `catalog_product` (EAV auto-discovery + attribute-set/category specials). Plus the generic
  Trigger Data leaf, relative dates, and 5 seed relations
  (`order.customer`, `order.customer_by_email`, `order.orders_by_email`,
  `quote.customer_by_email`, `customer.open_orders`).
- **Actions (22):** 8 order lifecycle, 4 customer, 5 product, coupon generation, email / webhook /
  admin-inbox, set-variable; plus delay/wait/branch/switch/approval flow steps.

The shape of what's missing: **the sales pipeline and customer lifecycle are deep but not
complete; the catalog side is action-rich but trigger-poor; and four entity families that appear
in every store's normal operations — newsletter subscribers, reviews-as-data, wishlists, sales
documents as first-class entities — are only partially visible or invisible.**

---

## Coverage map by entity

### 1. Order (`sales_order`)

The deepest entity today, and still the one with the most valuable remaining gaps — all in the
*payment* and *fulfillment-detail* dimensions.

| Dimension | Have | Gap → backlog | Expressible already (no gap) |
|---|---|---|---|
| Triggers | created, updated, status_changed (from→to) | **paid** (ORD-T1), **comment added** (ORD-T2) | canceled / completed / holded / unholded = `status_changed` + Trigger Data on `to_status` |
| Conditions | 25 flat attrs incl. addresses, totals, payment/shipping method, coupon; items ANY/ALL; customer subtree; 3 relations | **lifecycle flags** `can_invoice`/`can_ship`/`can_creditmemo`, `is_virtual`, invoice/shipment counts (ORD-C1); **applied cart-price rules** (ORD-C2); **hours in current status** (ORD-C3) | totals/status/date math via relative dates |
| Actions | comment, status, cancel, hold/unhold, invoice, shipment, full offline creditmemo | **add tracking** (ORD-A1), **resend/notify email** (ORD-A2), **partial creditmemo** (percent/fixed, ORD-A3) | — |

Why these: `sales.order.paid` is the anchor for every post-payment flow (fulfillment kickoff,
"paid but not shipped in 48h" SLAs) and is *not* reliably a status change on all payment methods.
The lifecycle flags are the guard conditions that make the existing document-creation actions safe
to author ("if can_ship → create shipment"). Hours-in-status is what turns scheduled workflows
into SLA monitors.

### 2. Sales documents (invoice / shipment / credit memo)

Today these are triggers only, hydrating the **order** — the document itself is reachable solely
through whatever the trigger payload carries. Near-comprehensive means the document is a
queryable thing.

| Dimension | Have | Gap → backlog |
|---|---|---|
| Triggers | invoice.created, shipment.created, creditmemo.created | **invoice paid** (online capture completes — DOC-T1), **shipment tracking added** (DOC-T2) |
| Conditions | order-root conditions on the parent order; document fields only if present in payload | **payload audit + Trigger Data recipes** for document fields (DOC-C1); **order→documents relations** (`order.invoices`, `order.shipments`, `order.creditmemos` EXISTS/count — DOC-C2) |
| Actions | order.create_invoice / create_shipment / create_creditmemo | (add-tracking lives on the order — ORD-A1) |

Full document condition *roots* (`sales_invoice` etc.) are deliberately **not** proposed: the
three triggers keep `entity=sales_order` (changing that is breaking), and the payload +
relations route covers the observed merchant asks (carrier, memo total, document counts) at a
fraction of the cost of three new roots. Revisit only if Trigger Data proves insufficient.

### 3. Customer (`customer`)

| Dimension | Have | Gap → backlog | Excluded |
|---|---|---|---|
| Triggers | created, updated, group_changed (from→to) | **deleted** (CUS-T1, verify upstream first), **address changed** (CUS-T2), **birthday upcoming** (scheduler detector — CUS-T3) | logged-in / failed-login (behavioral, through-line 1) |
| Conditions | full EAV auto-discovery, 5 order-history aggregates, open_orders relation | **newsletter status leaf** (CUS-C1), **default billing/shipping address fields** (CUS-C2), **reviews_count / wishlist_items_count aggregates** (CUS-C3), **days_until_birthday** (CUS-C4) | segment membership (Commerce) |
| Actions | assign_group, set_attribute, newsletter sub/unsub, anonymize | **initiate password reset email** (CUS-A1) | delete customer (destructive; anonymize is the sanctioned path) |

Why these: newsletter status as a *condition* is the single most-requested marketing guard
("…and is subscribed"); today it's only an action. The birthday detector is the classic
lifecycle automation and is a straight clone of the abandoned-cart detector pattern (query +
yearly dedupe watermark).

### 4. Newsletter subscriber (`newsletter_subscriber`) — new root

Guests subscribe without customer accounts; today they are completely invisible. This is the one
genuinely **new entity root** proposed (small: flat table, no EAV).

| Dimension | Gap → backlog |
|---|---|
| Triggers | **subscribed / unsubscribed / status changed** — one event with from→to status, covering guests and customers (SUB-T1) |
| Conditions | minimal root: email, status, store, is-linked-to-customer (SUB-C1) |
| Actions | covered — `customer.newsletter` for account holders; welcome/win-back flows are `notify.email` on the trigger |

### 5. Quote / cart (`quote`)

| Dimension | Have | Gap → backlog | Excluded |
|---|---|---|---|
| Triggers | abandoned (detector) | — | created/updated (checkout-session noise; abandoned + order.created bracket the useful moments), live-cart events (through-line 1) |
| Conditions | 12 flat attributes incl. totals, coupon, items counts, guest flag; customer_by_email relation | **items ANY/ALL subtree** (QTE-C1) — the direct clone of order ItemsFound; "abandoned cart contains SKU/brand X" is a flagship recipe today blocked | — |
| Actions | — | — | any cart mutation (storefront-live, through-line 1) |

### 6. Product (`catalog_product`)

Action-rich, trigger-poor. Normal catalog operations — price maintenance, enable/disable,
imports, stock movement — are mostly invisible as events.

| Dimension | Have | Gap → backlog | Expressible already |
|---|---|---|---|
| Triggers | review_submitted, stock_threshold_crossed | **created / updated** (PRD-T1, verify upstream; import-suppression module already handles the storm case), **price changed** (from→to, PRD-T2), **status changed** (enabled/disabled, PRD-T3), **deleted** (PRD-T4, snapshot-only caveat) | special-price windows & new-from dates = scheduled trigger + relative-date conditions |
| Conditions | full EAV auto-discovery + attribute_set/category specials | **stock leaves**: qty, is_in_stock, salable_qty MSI-aware (PRD-C1); **review aggregates**: rating percent, approved-review count (PRD-C2); **website membership** (PRD-C3) | — |
| Actions | set_attribute (scoped), set_status, set_stock (MSI), set_categories, set_special_price | **assign/unassign websites** (PRD-A1), **product links** related/cross/up-sell (PRD-A2), **tier prices** (PRD-A3) | base price = `set_attribute` on `price` (verify + document, PRD-D1) |

### 7. Inventory (MSI-aware)

| Dimension | Have | Gap → backlog |
|---|---|---|
| Triggers | stock_threshold_crossed (with hysteresis flag table) | **back in stock** — the detector's flag table *already computes* the recovery transition; publish it (INV-T1). **out of stock** — zero-crossing as a distinct event (INV-T2) |
| Conditions | — (product qty not conditionable) | covered by PRD-C1 |
| Actions | product.set_stock (legacy + MSI source) | — |

INV-T1 is the highest value-to-effort item in this document: the state transition is already
tracked, it just isn't published. Combined with the `product.wishlisted_customers` relation
(WSH-C1) and fan-out, it unlocks "wishlist back-in-stock" end to end.

### 8. Review (`review`)

Moderation is a normal daily operation and today the engine sees only the submission instant.

| Dimension | Have | Gap → backlog |
|---|---|---|
| Triggers | review_submitted (rides on product entity, review_* payload keys) | **review status changed** (approved/rejected, from→to — REV-T1) |
| Conditions | Trigger Data on review_* payload keys | sufficient for v1 — a review root is *not* proposed; rating/status arrive in the payload, and "customers with 3+ reviews" is CUS-C3 |
| Actions | — | **set review status** (approve/reject — REV-A1); with REV-T1 this closes the auto-moderation loop ("5-star from repeat buyer → auto-approve; 1-star → notify support") |

### 9. Wishlist

| Dimension | Have | Gap → backlog |
|---|---|---|
| Triggers | — | **item added** (WSH-T1; entity = product, customer in payload) |
| Conditions | — | **product → wishlisted-customers relation** (WSH-C1) — powers price-drop / back-in-stock via fan-out; customer-side count is CUS-C3 |
| Actions | — | none proposed (wishlist flows are notify/fan-out compositions) |

### 10. Category, CMS, promotions — the thin long tail

| Entity | Proposed | Not proposed (and why) |
|---|---|---|
| Category | **created/updated/deleted ops-notification triggers** (CAT-T1, tier 3) | condition root & actions — per-product `set_categories` covers the useful direction; bulk category reprice is a storm risk by design |
| CMS page/block | **saved/deleted ops-notification triggers** (CMS-T1, tier 3) | root/actions — needs entity-less or new-root execution support; content scheduling has native staging alternatives; revisit on demand |
| Cart price rule / coupon | **deactivate coupon action** (MKT-A1, tier 3) | rule-CRUD triggers (admin audit, not merchant automation); coupon *usage* = order trigger + existing `coupon_code` condition |

---

## The backlog

Flat list, agent-sized. Each item is deliberately shaped like the existing patterns so an agent
can clone a neighbor:

- **Event trigger** = observer (or plugin) + `async_events.xml` entry + `workflow_triggers.xml`
  metadata + unit tests. Clone: `OrderStatusChangeObserver`.
- **Detector** = scheduler cron model + published event + dedupe/watermark + config. Clone:
  `StockThresholdDetector` / `AbandonedCartDetector`.
- **Condition enrichment** = attribute list + input-type mapping (+ hydrator/aggregate provider
  entry when `needs_hydration`). Clone: `Condition/Order/Attribute`, `CustomerAggregateProvider`.
- **Relation** = one `RelationInterface` resolver + `RelationPool` di entry. Clone:
  `Relation/Resolver/CustomerOpenOrders`.
- **Action** = one class (metadata + execute + guards + simulate) + `ActionPool` di entry. Clone:
  `Action/Order/Hold` (simple) or `Action/Product/SetCategories` (config-rich).

Sizes: **S** = pattern clone (≤ ~half day), **M** = new pattern variant (1–2 days),
**L** = small subsystem. Every item includes unit tests in the standalone runner and an i18n pass;
new triggers must also state their loop-guard/debounce interaction in the class docblock.

### Tier 0 — verify first (blocks scoping of several items)

| ID | Item | Size | Notes |
|---|---|---|---|
| VER-1 | Audit `mageos-common-async-events` actual declarations against a checkout of that repo: which of customer.deleted, customer address events, product created/updated/deleted already exist; what each service class hydrates (esp. whether invoice/shipment/creditmemo events deliver the *document* or the order) | S | Where an event exists upstream, the matching trigger item collapses to metadata-only (one `workflow_triggers.xml` line + tests). Feeds CUS-T1, CUS-T2, PRD-T1, PRD-T4, DOC-C1 |

### Tier 1 — sales & lifecycle depth (highest merchant value, mostly clones)

| ID | Kind | Item | Size | Depends on |
|---|---|---|---|---|
| ORD-T1 | Trigger | `sales.order.paid` — observer on order-payment pay; payload `total_paid`, method | S | — |
| DOC-T1 | Trigger | `sales.invoice.paid` — invoice pay event (online capture path) | S | — |
| INV-T1 | Trigger | `inventory.back_in_stock` — publish on the stock-flag recovery transition already tracked by `StockThresholdDetector` | S | — |
| SUB-T1 | Trigger | `newsletter.subscription_changed` — observer on subscriber save, from→to status, guest-safe | M | SUB-C1 |
| SUB-C1 | Root | Minimal `newsletter_subscriber` condition root + hydrator (email, status, store, linked-customer flag) | M | — |
| CUS-T1 | Trigger | `customer.deleted` | S | VER-1 |
| CUS-T3 | Detector | Birthday-upcoming detector (`customer.birthday_upcoming`, N-days-ahead config, yearly dedupe) | M | — |
| CUS-C1 | Condition | Customer `newsletter_status` hydrated leaf | S | — |
| ORD-C1 | Condition | Order lifecycle flags: `can_invoice`, `can_ship`, `can_creditmemo`, `is_virtual`, `invoice_count`, `shipment_count` (hydrated) | M | — |
| QTE-C1 | Condition | Quote items ANY/ALL subtree (clone of Order `ItemsFound`) | S | — |
| PRD-C1 | Condition | Product stock leaves: `qty`, `is_in_stock`, `salable_qty` (MSI-aware, legacy fallback — reuse the detector's source-resolution approach) | M | — |
| ORD-A1 | Action | `order.add_tracking` — carrier + number (+ title), latest-shipment default, guarded | S | — |

### Tier 2 — catalog operations, moderation, wishlist

| ID | Kind | Item | Size | Depends on |
|---|---|---|---|---|
| PRD-T1 | Trigger | `catalog.product.created` / `catalog.product.updated` (two metadata rows, one observer; document interplay with import suppression + debounce) | M | VER-1 |
| PRD-T2 | Trigger | `catalog.product.price_changed` — orig-data compare, from→to + store scope in payload | M | — |
| PRD-T3 | Trigger | `catalog.product.status_changed` (enabled/disabled, from→to) | S | PRD-T2 (shares observer) |
| INV-T2 | Trigger | `inventory.out_of_stock` — zero-crossing publication from the threshold detector | S | — |
| REV-T1 | Trigger | `review.status_changed` — approved/rejected, from→to + rating in payload | S | — |
| REV-A1 | Action | `review.set_status` (approve / reject / pending) | S | — |
| WSH-T1 | Trigger | `wishlist.item_added` (entity = product; customer_id, wishlist share info in payload) | M | — |
| WSH-C1 | Relation | `product.wishlisted_customers` (capped, fan-out-eligible) | M | — |
| CUS-T2 | Trigger | `customer.address_changed` (create/update/delete with `change_type` payload key) | M | VER-1 |
| CUS-C2 | Condition | Customer default billing/shipping address leaves (country/region/postcode, hydrated) | M | — |
| CUS-C3 | Condition | Customer aggregates: `reviews_count`, `wishlist_items_count` (clone aggregate-provider pattern) | S | — |
| ORD-C2 | Condition | Order `applied_rule_ids` multiselect (option source already exists: `CartPriceRuleOptionSource`) | S | — |
| ORD-C3 | Condition | Order `hours_in_current_status` aggregate (from status history; enables SLA schedules) | M | — |
| ORD-T2 | Trigger | `sales.order.comment_added` (comment text, status, notified flag in payload) | S | — |
| ORD-A2 | Action | `order.send_email` — resend confirmation / notify with latest comment | S | — |
| ORD-A3 | Action | `order.create_creditmemo` partial mode — percent-of-total or fixed amount computed in-action (config-static, resolver untouched) | M | — |
| DOC-T2 | Trigger | `sales.shipment.tracking_added` | S | — |
| DOC-C1 | Docs/Cond | Document-field access audit: ensure invoice/shipment/creditmemo trigger payloads carry the document; publish Trigger Data recipes (carrier, memo totals) in docs 17 | S | VER-1 |
| DOC-C2 | Relation | `order.invoices` / `order.shipments` / `order.creditmemos` (EXISTS/ANY over documents) | M | — |
| PRD-C2 | Condition | Product review aggregates: `rating_percent`, `reviews_count` (approved only) | S | — |
| PRD-C3 | Condition | Product `website_ids` membership (special attribute alongside `category_ids`) | S | — |
| PRD-A1 | Action | `product.assign_websites` (add/remove) | S | — |
| PRD-A2 | Action | `product.set_product_links` (related/cross-sell/up-sell; add/remove/replace) | M | — |

### Tier 3 — long tail & conveniences (demand-driven)

| ID | Kind | Item | Size | Notes |
|---|---|---|---|---|
| PRD-T4 | Trigger | `catalog.product.deleted` — snapshot-only (no post-delete hydration; payload must carry identity fields; conditions restricted to in-snapshot, enforced at save) | M | VER-1; first snapshot-only trigger, sets the pattern for `customer.deleted` payloads too |
| PRD-A3 | Action | `product.set_tier_prices` (replace semantics) | M | — |
| PRD-D1 | Docs | Verify + document `product.set_attribute` on `price`/`special_price` scoped behavior as the sanctioned "set price" recipe | S | — |
| CUS-A1 | Action | `customer.initiate_password_reset` | S | security-sensitive; ACL-gated |
| CUS-C4 | Condition | Customer `days_until_birthday` computed aggregate | S | pairs with CUS-T3 |
| MKT-A1 | Action | `marketing.deactivate_coupon` (expire a named/generated code) | S | — |
| CAT-T1 | Trigger | `catalog.category.saved` / `.deleted` ops-notification triggers | M | needs decision: minimal category root vs. snapshot-only |
| CMS-T1 | Trigger | `cms.page.saved` / `cms.block.saved` ops-notification triggers | L | blocked on entity-less (or new-root) execution decision; lowest priority |

### Explicitly not on the list

Checkout/storefront decisioning, live-cart mutation, order/customer/quote *creation*, in-graph
iteration and fork-join, order item mutation, category-wide bulk repricing, SMS/push channels,
inbound triggers, admin-user/security auditing, and everything Commerce/B2B — per the
through-lines and non-goals ([18](../18-limitations.md), [01](../01-overview.md#non-goals-for-v1)).

## Suggested execution order for agent runs

1. **VER-1 alone** — it re-scopes up to six items to metadata-only.
2. **Tier 1 in any order** — items are independent except SUB-T1→SUB-C1; conditions and actions
   are pure clones and parallelize cleanly across agents.
3. **Tier 2 grouped by file adjacency**, not by ID: (PRD-T2 + PRD-T3), (REV-T1 + REV-A1),
   (WSH-T1 + WSH-C1), (ORD-T2 + ORD-A2), (DOC-C1 + DOC-C2) pair naturally into single agent
   tasks; the rest are independent singles.
4. **Tier 3 on demand.**

Every new trigger should land with: loop-guard note, debounce interaction, at least one Trigger
Data recipe in [17 — Use Cases](../17-use-cases.md), and a template-gallery candidate noted where
the flow is a classic (birthday, back-in-stock, auto-moderation, win-back).

Rough totals if fully executed: **+15 triggers** (12 → ~27 event triggers + 2 detectors),
**+1 entity root** and ~14 condition/relation enrichments, **+9 actions** (22 → 31). That is
"near-comprehensive" for Open Source core normal operations: every entity a merchant touches
daily is then observable at its state transitions, queryable in its merchant-meaningful state,
and actionable for its safe back-office mutations.

---

## Packaging: where the backlog lives

Dumping the backlog into the existing packages would make every Magento core domain a hard
dependency of the shared packs — `workflows-actions-core` already requires seven
`magento/module-*` packages, and the backlog would add Wishlist, Review, Newsletter, Cms and
Inventory to that list. This section defines the target organization so the backlog lands
cleanly instead.

### The current state is already dishonest

Audit of `use Magento\…` imports vs. declared composer requires (July 2026):

| Package | Declares | Actually imports |
|---|---|---|
| `mage-os/workflows` (engine) | framework, store, rule, quote | + **Sales (19 files), Customer (15), Catalog (7)**, Eav, Shipping, SalesRule, Payment, Email — all undeclared |
| `workflows-triggers-core` | framework, async-events | + Sales, Customer, **Review** — all undeclared |
| `workflows-actions-core` | sales, customer, catalog, catalog-inventory, newsletter, sales-rule, email | matches (the honest one — and therefore the one that shows the problem: one pack = every domain mandatory) |
| `workflows-scheduler` | sales, customer, catalog, quote, catalog-inventory, cron | matches — because `QueryRunner` hardwires the four repositories and both detectors live here |

It compiles everywhere because every Open Source install ships all of these modules. But the
engine's condition roots, hydrators and relations are entity bindings living in the
entity-agnostic package, and the metadata lies about it.

### Recommended organization: vertical domain packs

**Slice by Magento domain module, not by layer.** A domain's triggers, conditions, relations,
actions and detectors all share the same underlying dependency (that domain's
`magento/module-*`), so splitting triggers from actions doubles the module count and buys zero
optionality. The unit of optionality is the domain.

This is architecturally free: every entity-specific artifact registers through seams that are
already additive — `workflow_triggers.xml` and `async_events.xml` merge across modules; the
`ActionPool`, combine map, condition-leaf map, `RelationPool` and hydrator map are DI
type-arrays. A first-party domain pack is just a connector ([07 — Actions](../07-actions.md)
calls the pattern the connector SDK); this proposal dogfoods it.

| Package | Contents (existing → moves in, plus backlog IDs) | Hard requires (beyond `mage-os/workflows`) |
|---|---|---|
| `mage-os/workflows` (engine) | Rule machinery, evaluator/classifier, executor, queues, variable resolver, secrets, Trigger Data leaf, generic RelatedEntity combine, flow steps + `flow.set_variable`. **Out:** all four entity roots, hydrators, seed relations | framework, rule, store, async-events (drops `module-quote`) |
| `workflows-triggers-core` | Notifier binding, subscription lifecycle/ownership only. **Out:** all observers and gap-fill event declarations | async-events |
| `workflows-actions-core` | Entity-agnostic actions only: `notify.email`, `notify.webhook`, `notify.admin` | email, guzzle |
| `workflows-scheduler` | Generic cron + query runner with a **DI-contributed entity-adapter map**. **Out:** both detectors | cron |
| **`workflows-sales`** | Order + documents + quote/cart + coupon. Roots `sales_order`, `quote`; order/quote relations; abandoned-cart detector; all `order.*` actions + `marketing.*` coupon actions; **contributes the order-history aggregates to the customer root**. Backlog: ORD-\*, DOC-\*, QTE-C1, MKT-A1 | sales, sales-rule (quote, payment, shipping arrive transitively via sales) |
| **`workflows-customer`** | Customer root, customer triggers, `customer.assign_group` / `set_attribute` / `anonymize`. Backlog: CUS-T1..T3, CUS-C2, CUS-C4, CUS-A1 | customer, eav |
| **`workflows-catalog`** | Product root, product/category triggers, catalog actions. Backlog: PRD-T1..T4, PRD-C3, PRD-A1..A3, PRD-D1, CAT-T1 | catalog, eav |
| **`workflows-inventory`** | Stock detectors + `inventory.*` events, stock condition leaves on the product root, `product.set_stock`. Backlog: INV-T1, INV-T2, PRD-C1 | catalog-inventory; *suggests* MSI (existing runtime-guard pattern in `set_stock`) |
| **`workflows-review`** (new, born with its first item) | REV-T1, REV-A1, existing `review_submitted` observer; contributes rating aggregates to the product root (PRD-C2) and `reviews_count` to the customer root (half of CUS-C3) | review |
| **`workflows-wishlist`** (new) | WSH-T1, WSH-C1; contributes `wishlist_items_count` to the customer root (other half of CUS-C3) | wishlist |
| **`workflows-newsletter`** (new) | Subscriber root + trigger (SUB-T1, SUB-C1), `customer.newsletter` action (moves from actions-core), newsletter-status leaf on the customer root (CUS-C1), and the newsletter-unsubscribe half of `customer.anonymize` as a plugin | newsletter, customer |
| `workflows-cms` (deferred) | CMS-T1, pending the entity-less execution decision | cms |
| **`mage-os/workflows-suite`** (metapackage) | Requires engine + ui + scheduler + all domain packs — the batteries-included install path the README recommends | everything above |

### Composition rules (what keeps it clean)

1. **A pack owns a root iff it owns the entity's Magento module.** Other packs *contribute*
   leaves, aggregates and relations to that root through the DI pools. Contributions flow toward
   the owner of the **source data**: sales contributes order-history aggregates to the customer
   root (they query `sales_order`); review contributes rating aggregates to the product root.
   Never the reverse — `workflows-customer` must not import `Magento\Sales`.
2. **Packs never require each other**, with one sanctioned exception: when a behavior genuinely
   spans two domains (newsletter-unsubscribe inside `customer.anonymize`), it lives in the pack
   owning the *secondary* domain as a plugin, and that leaf pack declares both requires — the
   standard Magento bridge-module pattern, confined to leaves.
3. **`composer.json` must match `use` statements.** Compile-safety comes from requires (an
   absent package breaks `setup:di:compile` for any class referencing it; runtime `class_exists`
   guards only help when the package is present but disabled). Add a CI check that greps each
   module's imports against its declared requires so the honesty is enforced, not aspirational.
4. **`module.xml` `<sequence>` entries are free** — Magento's module loader ignores sequence
   references to absent modules, so ordering hints across optional packs cost nothing.
5. Reserve the runtime-guard trick (as `product.set_stock` does for MSI) for **intra-pack**
   optionality only.

Honest caveat: every one of these Magento modules ships in every Open Source install, so the
split changes nothing at install time *today*. What it buys: slim/headless builds that
`module:disable` Newsletter/Review/Wishlist/Cms don't carry dead observers and dead picker
options; the metadata stops lying; each pack's test surface is small; every backlog item has an
unambiguous home; and the SDK story is demonstrated by first-party code. The cost is ~6 new
small packages — bounded, and this repo already operates a 10-module monorepo with shared CI.

### Migration items (prepend to the backlog)

Pre-alpha, no live installs ([README §Status](../../README.md)) — classes move without BC shims.

| ID | Item | Size |
|---|---|---|
| PKG-0 | Engine enablers: pool-ify `CustomerAggregateProvider` (aggregate providers per root become a DI map so review/wishlist/sales can contribute); make scheduler `QueryRunner`'s entity→repository wiring a DI-contributed adapter map; verify the leaf/child-condition/hydrator maps accept cross-module contribution (they are DI arrays already — confirm with a test) | M |
| PKG-1 | Create `workflows-sales`; move order+quote roots, hydrators, relations, observers, order/coupon actions, abandoned-cart detector | M |
| PKG-2 | Create `workflows-customer`; move customer root, hydrator, observers, customer actions (minus newsletter halves) | M |
| PKG-3 | Create `workflows-catalog`; move product root, hydrator, review-submitted observer stub, catalog actions | M |
| PKG-4 | Create `workflows-inventory`; move stock detector, `set_stock` | S |
| PKG-5 | Slim engine / actions-core / triggers-core / scheduler composer.json + module.xml to their honest remainders; add the import-vs-require CI honesty check | S |
| PKG-6 | `workflows-suite` metapackage + README / [02 — Packages](../02-packages.md) update | S |

PKG-0 must land first; PKG-1..4 are independent of each other and agent-parallelizable
(worktree isolation recommended — they all touch the shared di.xml files they're shrinking);
PKG-5/6 close. The Tier 1–3 backlog then executes against the new layout, and the three small
packs (review, wishlist, newsletter) are simply created by their first backlog item.
