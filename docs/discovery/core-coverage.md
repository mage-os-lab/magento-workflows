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

### What soft dependencies can and cannot do in Magento

Decision input (July 2026): **soft dependencies are acceptable as long as neither
`setup:di:compile` nor runtime breaks when the target modules are missing.** That constraint has
precise mechanics, and they determine the design:

- **Present-but-disabled is the cheap case.** `module:disable Magento_Wishlist` leaves the
  package's classes on disk: everything still compiles, disabled modules' `di.xml`/`events.xml`
  are not merged, their events never fire. A binding in its own module needs *zero* in-code
  guards for this case.
- **Absent package is the hard case.** `setup:di:compile` reflects every class of every
  **enabled** module: an `extends`/`implements`/constructor type-hint against a class from an
  absent package is a compile failure, and so is any merged `di.xml` reference to one (plugin,
  preference, or an `xsi:type="object"` pool item whose class has such a constructor — our
  pools are wired `xsi:type="object"`, so registration is eager instantiation).
- **Disabled modules are excluded from compilation.** The compiler only scans enabled modules —
  so the *module enable state* is the natural absence-safety boundary. A domain binding isolated
  in its own Magento module is automatically compile-safe and runtime-safe when its target is
  absent (module stays disabled) or disabled (config merge skips it), with no guards at all.
- **Absence-safe code inside a shared module is possible but ugly.** `product.set_stock` shows
  the sanctioned style for MSI: no type-hints on optional classes, `interface_exists()` guard,
  lazy `ObjectManagerInterface` resolution at runtime. It works — and it forfeits DI wiring,
  fights the coding standard, and hides the dependency from every static tool. Acceptable for a
  single seam inside an otherwise hard-dep class; unacceptable as the pervasive style for a
  whole domain's observers, roots and actions.

So "soft dependency" resolves to a **placement rule, not a coding trick**: optional-domain
artifacts live in their own small module whose composer package hard-requires the target; the
module simply isn't installed/enabled where the target is missing. Guard-style soft references
stay reserved for single seams.

### Tier the domains by absence-realism

The compile constraint only *bites* where a module can realistically be missing:

| Tier | Modules | Treatment |
|---|---|---|
| **Never absent on a functioning store** — required by `magento/product-community-edition` and presupposed by the engine's purpose | Sales, Quote, Customer, Catalog, Eav, CatalogInventory, SalesRule, Email, Store, Rule | Plain hard requires, declared honestly. No isolation gymnastics — a workflow engine on a store without Sales or Catalog is meaningless |
| **Plausibly disabled, occasionally pruned** (headless/slim builds) | Newsletter, Review, Wishlist, Cms | Isolate at module granularity: one small workflows module per domain |
| **Genuinely removable package family** | MSI (`magento/module-inventory-*`) | Keep the shipped runtime-guard pattern (`set_stock`); stock condition leaves follow the same style or live behind an MSI-aware provider that degrades to legacy |

### Recommended layout (lean version)

The existing shared packs stay, get honest, and keep the never-absent tier as hard requires;
new small modules exist **only where absence is real**:

| Package | Change |
|---|---|
| `mage-os/workflows` (engine) | Composer honesty pass: declare sales, customer, catalog, eav (all never-absent) for the roots/hydrators/relations it already contains — or, optional hygiene, move the roots out (below). Either way the metadata stops lying |
| `workflows-triggers-core` | Declare sales + customer; **move the review observer out** to `workflows-review`. Backlog observers for never-absent domains (ORD-T\*, DOC-T\*, CUS-T1/T2, PRD-T\*) land here |
| `workflows-actions-core` | Keeps sales/customer/catalog/catalog-inventory/sales-rule/email requires (all never-absent). **Moves `customer.newsletter` out**; `anonymize`'s newsletter-unsubscribe becomes a plugin contributed by `workflows-newsletter` (or a runtime-guarded seam). Backlog actions for never-absent domains land here |
| `workflows-scheduler` | Unchanged requires (all never-absent). Birthday detector (CUS-T3) and the stock-event extensions (INV-T1/T2) land here |
| **`workflows-review`** (new, small) | Existing `review_submitted` observer moves in; REV-T1, REV-A1, PRD-C2, `reviews_count` half of CUS-C3. Requires `magento/module-review` |
| **`workflows-wishlist`** (new, small) | WSH-T1, WSH-C1, `wishlist_items_count` half of CUS-C3. Requires `magento/module-wishlist` |
| **`workflows-newsletter`** (new, small) | SUB-T1, SUB-C1, CUS-C1, `customer.newsletter` action, anonymize-unsubscribe plugin. Requires `magento/module-newsletter` |
| `workflows-cms` (deferred) | CMS-T1, pending the entity-less execution decision. Requires `magento/module-cms` |
| **`mage-os/workflows-suite`** (metapackage) | Batteries-included install: engine + ui + triggers/actions/scheduler + the three optional domain modules |

Net cost over today: **three small modules plus a metapackage** (CMS deferred), instead of the
six-to-seven-package vertical split. One module per composer package, per Magento convention —
`module:enable` dependency checking works through per-package composer metadata, and the
monorepo's publishing/CI already handles the multi-package layout.

**Optional hygiene, not required by the constraint:** the fuller vertical split
(`workflows-sales` / `-customer` / `-catalog` / `-inventory`, engine stripped of entity
bindings) remains the architecturally purest shape and becomes *worth doing* if Mage-OS's
module-removability direction makes the never-absent tier genuinely removable. Nothing in the
lean layout forecloses it: the same DI-pool seams carry either packaging, so the split can
happen later as pure file moves. Decide then, not now.

### Composition rules (what keeps it clean)

1. **Placement follows absence-realism.** Never-absent domain artifacts go in the shared packs
   with declared requires; plausibly-absent domain artifacts go in that domain's small module;
   single optional seams inside otherwise hard-dep classes use the `set_stock` runtime-guard
   style — and stay rare.
2. **Contributions flow toward the owner of the source data**, wherever the artifact lives:
   review contributes rating aggregates to the product root, wishlist/newsletter contribute
   leaves to the customer root — via the DI pools, never by patching the root's class. The
   shared packs must never import from Newsletter/Review/Wishlist/Cms.
3. **Optional modules may require shared packs, never each other.** Cross-domain behavior
   spanning two optional domains (none exists in this backlog) would get a leaf bridge module —
   the standard Magento pattern.
4. **`composer.json` must match compile-time references.** Add a CI check greping each module's
   `use`/`extends`/type-hints against declared requires, with an explicit allowlist annotation
   for sanctioned runtime-guarded references (`SetStock`'s `Magento\InventoryApi\*` strings) so
   the honesty is enforced, not aspirational.
5. **`module.xml` `<sequence>` entries are free** — the module loader ignores sequence
   references to absent modules, so ordering hints toward optional modules cost nothing.

### Migration items (prepend to the backlog)

Pre-alpha, no live installs ([README §Status](../../README.md)) — classes move without BC shims.

| ID | Item | Size |
|---|---|---|
| PKG-0 | Pool-ify `CustomerAggregateProvider`: aggregate providers per root become a DI map so review/wishlist (and future packs) can contribute customer-root aggregates; confirm with a test that the leaf/child-condition/hydrator maps accept cross-module contribution | M |
| PKG-1 | Composer honesty pass: add the missing never-absent requires to engine and triggers-core; add the import-vs-require CI check with the runtime-guard allowlist | S |
| PKG-2 | Create `workflows-review`: move the review observer + trigger metadata out of triggers-core (REV-T1/REV-A1/PRD-C2 then land here) | S |
| PKG-3 | Create `workflows-newsletter`: move `customer.newsletter` out of actions-core; convert anonymize's newsletter-unsubscribe into a plugin contributed by this module (SUB-T1/SUB-C1/CUS-C1 then land here) | M |
| PKG-4 | Create `workflows-wishlist` skeleton — or simply let WSH-T1 create it | S |
| PKG-5 | `workflows-suite` metapackage + README / [02 — Packages](../02-packages.md) update | S |

PKG-0 and PKG-1 land first (PKG-0 unblocks the cross-module aggregate contributions; PKG-1 makes
the baseline honest); PKG-2..4 are independent and agent-parallelizable; PKG-5 closes. The
Tier 1–3 backlog then executes against this layout — each item's home follows from the tier
table above.
