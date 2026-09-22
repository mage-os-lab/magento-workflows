# 02 — Package Decomposition

Composer packages, mirroring the `mageos-async-events` family layout. The vertical
**domain-pack split** ([08 — Domain Packs](discovery/implementation/08-domain-packs.md))
executed July 2026: the bundled entity bindings moved out of the shared packs into one
pack per commerce domain, so the engine and the three shared infrastructure packs are now
entity-agnostic *in fact*. Every package's composer metadata is CI-enforced honest by
[`dependency-honesty-check.php`](../dev/tools/dependency-honesty-check.php).

**Engine + shared infrastructure** (entity-agnostic):

| Package | Contents |
|---|---|
| `mage-os/workflows` | Core engine: domain model, condition evaluation/machinery (rule pools, hydration/relation/leaf/combine pools, aggregate pool), execution, queues, secrets, webapi + CLI, generic leaves, the `email_templates` option source (+ its `entity:email_template` alias), and the `EntityOptionSourceRegistry` the packs register aliases into |
| `mage-os/workflows-admin-ui` | Grid + form UI, executions log UI, ACL, dry-run recent-entity picker |
| `mage-os/workflows-actions-core` | Entity-agnostic action library: `notify.email`/`notify.webhook`/`notify.admin`, `flow.set_variable`, and the bundled ad-hoc email template |
| `mage-os/workflows-triggers-core` | Async-events notifier binding, subscription lifecycle/ownership, and the `EventPublisher` the domain packs publish gap-fill events through |
| `mage-os/workflows-scheduler` | Scheduled-trigger infrastructure: cron entry, entity-agnostic `QueryRunner` + `ConditionToSearchCriteria`, schedule-state table (domain packs contribute their per-entity `QueryRunner` maps) |

**Domain packs** (one Magento module + composer package per commerce domain; each carries that domain's roots, hydrators, relations, option sources — including the `entity:*` template-parameter aliases those sources back, registered into core's `EntityOptionSourceRegistry` — detectors, triggers and actions together):

| Package | Contents |
|---|---|
| `mage-os/workflows-sales` | Order + quote condition roots, order/quote hydrators, `order.*`/`quote.*` relations, order-history aggregates on the customer root, the order-status + abandoned-cart triggers and `AbandonedCartDetector`, order + coupon actions, order-status/cart-price-rule option sources (+ their `entity:order_status` and `entity:salesrule` aliases), `sales_order`/`quote` `QueryRunner` maps, the cart-abandonment threshold config field |
| `mage-os/workflows-customer` | Customer condition root, customer hydrator, `customer.open_orders` relation, the customer-group-changed trigger, `customer.assign_group`/`set_attribute`/`anonymize` actions, customer-group option source (+ its `entity:customer_group` alias), `customer` `QueryRunner` map |
| `mage-os/workflows-catalog` | Product condition root, product hydrator, `product.set_attribute`/`set_status`/`set_categories`/`set_special_price` actions, the `websites` option source (+ its `entity:website` alias), `catalog_product` `QueryRunner` map |
| `mage-os/workflows-inventory` | `StockThresholdDetector`, the stock-threshold-crossed trigger, the stock-flag hysteresis table, `product.set_stock`, the stock-threshold config field; *suggests* MSI (runtime-guarded) |
| `mage-os/workflows-review` | `ReviewSubmittedObserver` + the `catalog.product.review_submitted` trigger |
| `mage-os/workflows-newsletter` | `customer.newsletter` action + the anonymize-unsubscribe plugin on `workflows-customer`'s Anonymize |
| `mage-os/workflows-wishlist` | `WishlistItemAddedObserver` + the `wishlist.item_added` trigger, the `product.wishlisted_customers` fan-out relation, and the `wishlist_items_count` aggregate on the customer root |

**Metapackage:**

| Package | Contents |
|---|---|
| `mage-os/workflows-suite` | Batteries-included install: engine, admin-ui, the three infrastructure packs, and all seven domain packs. The optional extras below stay opt-in and are required separately. |

**Optional extras** (opt-in, not in the suite):

| Package | Contents |
|---|---|
| `mage-os/workflows-canvas` | React Flow viewer + editor (reads/writes the same definition JSON) |
| `mage-os/workflows-templates` | Bundled gallery template content pack (data-only; the gallery UI lives in `workflows-admin-ui`) |
| `mage-os/workflows-import-suppression` | Suppresses dispatch during ImportExport CSV imports (keeps core free of a hard ImportExport dependency) |
| `mage-os/workflows-admin-extension` | ACL-gated summary strip + view/create deep links on native entity grids, layered on `workflows` + `workflows-admin-ui` |
| `mage-os/workflows-approvals` | Human-decision gate: the approval task table, decision service, REST endpoint, and admin grid/decision view behind the schema-4 `approval` step's core seam (see [Approval Gate discovery](discovery/approval-gate.md)) |

Module enable state, not in-code guards, is the absence-safety boundary: a domain pack can be omitted or disabled without compile or runtime breakage.

## Dependencies

- **Hard dependency of core:** `mage-os/mageos-async-events` — the engine rides its notifier seam, queue transport, retry, and tracing (see [Triggers](05-triggers.md) and [Execution Model](08-execution-model.md)).
- **Soft dependency (suggest):** `mageos-async-events-admin-ui` — useful for raw subscription debugging during development and support.

## Separation rationale

- The **core engine** stays UI-free so headless installs and CI pipelines can run it (definitions are installable via data patches and the import CLI).
- **Actions** and **triggers** ship as separate packages because they are pure *content on the pools* — the same extension mechanism third parties use. The bundled packages are reference implementations of the SDK, not privileged code.
- The **scheduler** is separate because query-based triggers (cron + condition-tree-as-query) carry their own operational weight (batching, watermarks, match caps — see [Triggers §Scheduled](05-triggers.md#scheduled-triggers-workflows-scheduler)) that pure event-driven installs don't need.
- The **canvas** is optional and purely presentational: it reads and writes the same [definition JSON](04-definition-format.md), so it can lag or be replaced without engine changes.

### Domain-pack placement rules

These are also the review checklist for placing future backlog items:

1. **Root ownership** — a pack owns a condition root iff it owns the entity's Magento module.
2. **Relations live with their source entity's pack** (where they appear in the UI): `order.*`/`quote.*` → sales; `customer.*` → customer.
3. **Aggregates live with the pack owning the queried data**, contributed to the target root via the aggregate pool (order-history aggregates on the customer root belong to sales).
4. **Option sources live with their consumer**, and a source's `entity:*` template-parameter
   alias is registered by the same pack (one DI entry into core's `EntityOptionSourceRegistry`),
   so an absent pack simply loses both together.
5. **Packs may require any never-absent `magento/*` module freely** (Sales, Customer, Catalog, Quote, Eav, CatalogInventory, SalesRule, Email — all shipped by `product-community-edition`). Workflows packs never require each other *laterally* (sales ↔ customer); domain packs may — and must, honestly — require the shared infrastructure packs they build on (`workflows` always; `workflows-triggers-core` when they publish through its `EventPublisher`; `workflows-scheduler` when they contribute a `QueryRunner` map). Only the small optional-domain packs may additionally require the domain packs beneath them (newsletter → customer).
6. The `set_stock`/MSI runtime-guard style stays reserved for genuinely removable package families inside an otherwise hard-dep class; enable state covers everything else.
