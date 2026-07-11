# 08 — Domain Packs: Implementation Plan

Decision record and staged plan for the **full vertical split** of entity bindings out of the
shared packages, chosen July 2026 (decision context:
[Core Coverage §Packaging](../core-coverage.md#packaging-where-the-backlog-lives)). This is the
prerequisite reorganization for executing the core-coverage backlog; it changes **no behavior**.

## Execution record

**Executed July 2026 — stages S0–S6 all landed** (see commit history for per-stage detail).
The six domain packs (`workflows-{sales,customer,catalog,inventory,review,newsletter}`) exist,
the shared packs are slimmed to their entity-agnostic remainders, and the `workflows-suite`
metapackage ships. Two things diverged from the plan as written below:

- **E5 was added mid-flight** (during the S3/S4 merge): the `ConditionLeafPool` +
  non-throwing `getCombineClass()` on the combine pool, resolving two lateral couplings the S3
  report surfaced (see the Enablers table). Placement rule 5 holds with zero lateral requires.
- **S5's anonymize handling diverged**: rather than `workflows-customer` requiring
  `workflows-newsletter`, the newsletter-unsubscribe step was extracted into a plugin on
  `workflows-customer`'s Anonymize that ships *in the newsletter pack* — so the dependency runs
  newsletter → customer (optional-domain-on-domain, rule 5), and `workflows-customer` dropped
  its newsletter require entirely.

S6 also declared the honest cross-pack requires the domain packs owe the shared infrastructure
(`workflows-sales`/`-customer`/`-catalog` → `workflows-scheduler` for their `QueryRunner` maps),
extended the honesty check to cover `MageOS\Workflows*` cross-pack references, moved the
`abandoned_hours`/`stock_threshold` system.xml fields to sales/inventory (paths unchanged), and
emptied the dependency-honesty baseline.

## Intent

Dissolve the order/customer/product/quote bindings out of `workflows` (engine),
`workflows-triggers-core`, `workflows-actions-core` and `workflows-scheduler` into **vertical
domain packs** — one Magento module + composer package per commerce domain, each carrying that
domain's triggers, condition roots/leaves, hydrators, relations, option sources, detectors and
actions together. After the split:

- the engine is entity-agnostic *in fact*, not just in intent (today it imports Sales in 19
  files, Customer in 15, Catalog in 7 — none declared);
- every package's composer metadata is honest and CI-enforced;
- every core-coverage backlog item has exactly one obvious home;
- installs can omit or disable a domain pack without compile or runtime breakage (module enable
  state is the absence-safety boundary — disabled modules are excluded from `setup:di:compile`
  and their config never merges);
- the first-party packs demonstrate the connector SDK at full scale.

**Invariants:** action/trigger/relation/option-source *codes* do not change, so stored
definitions, template envelopes (`requires` pins codes, not classes), `spec/` fixtures, REST
contracts and ACL resources are all untouched. Pre-alpha, no live installs — class FQCNs and
file locations move without BC shims.

## Target layout

| Package / module | Contents after the split | Requires (magento/* beyond framework; all packs require `mage-os/workflows`) |
|---|---|---|
| `mage-os/workflows` — `MageOS_Workflows` | Domain model, engine, queues, secrets, variable resolver, webapi + CLI, rule *machinery* (WorkflowRule, evaluator, classifier, `ConditionCombinePool`, `HydrationProvider`, `RelationPool`, aggregate pool), generic leaves (Trigger Data, RelatedEntity combine), `EntityDataConverter` | store, rule (+ eav only if residual imports remain after the moves — verify in S6) |
| `workflows-triggers-core` | Notifier binding, subscription lifecycle/ownership, `WorkflowSaveObserver` | — (async-events only) |
| `workflows-actions-core` | `notify.email` / `notify.webhook` / `notify.admin`, `flow.set_variable`, `EmailTemplateOptionSource` | email; guzzle |
| `workflows-scheduler` | Cron entry, `QueryRunner` (its repository/DTO maps are already DI arrays — entries relocate), `ConditionToSearchCriteria`, schedule-state table | cron |
| **`workflows-sales`** — `MageOS_WorkflowsSales` (new) | Order + quote roots (`Condition/Order/*` incl. `ItemsFound`, `Condition/Quote/*`), `OrderHydrator`/`QuoteHydrator`, `CustomerAggregateProvider` (order-history aggregates, contributed to the customer root via the aggregate pool), relations `order.customer`, `order.customer_by_email`, `quote.customer_by_email`, `order.orders_by_email` (+ `AbstractCustomerByEmail`), `OrderStatusChangeObserver` + `sales.order.status_changed` event, Sales + Cart trigger metadata (7 entries), `AbandonedCartDetector` + `quote.abandoned` + abandoned-flag table, all `Action/Order/*` (9) + `marketing.generate_coupon`, `OrderStatusOptionSource` + `CartPriceRuleOptionSource`, QueryRunner entries for `sales_order`/`quote` | sales, quote, sales-rule, customer |
| **`workflows-customer`** — `MageOS_WorkflowsCustomer` (new) | Customer root (`Condition/Customer/*`), `CustomerHydrator`, relation `customer.open_orders`, `CustomerGroupChangeObserver` + `customer.group_changed` event, Customer trigger metadata (3), `customer.assign_group` / `set_attribute` / `anonymize`, `CustomerGroupOptionSource`, QueryRunner `customer` entries | customer, eav, sales (open-orders relation + anonymize guards), newsletter → removed in S5 |
| **`workflows-catalog`** — `MageOS_WorkflowsCatalog` (new) | Product root (`Condition/Product/*`), `ProductHydrator`, `product.set_attribute` / `set_status` / `set_categories` / `set_special_price`, QueryRunner `catalog_product` entries | catalog, eav |
| **`workflows-inventory`** — `MageOS_WorkflowsInventory` (new) | `StockThresholdDetector`, `inventory.stock_threshold_crossed` event + trigger metadata, stock-flag table (moves from the **engine's** db_schema), threshold config (path preserved), `product.set_stock` | catalog-inventory, catalog; *suggests* MSI (runtime guard unchanged) |
| **`workflows-review`** — `MageOS_WorkflowsReview` (new, small) | `ReviewSubmittedObserver`, `catalog.product.review_submitted` event + trigger metadata | review, catalog |
| **`workflows-newsletter`** — `MageOS_WorkflowsNewsletter` (new, small) | `customer.newsletter` action (code unchanged), anonymize-unsubscribe plugin on `workflows-customer`'s Anonymize | newsletter, customer, `mage-os/workflows-customer` |
| `workflows-wishlist` | **Not created here** — born with its first core-coverage backlog item (WSH-T1) | — |
| **`mage-os/workflows-suite`** (new metapackage) | Batteries-included install: engine, admin-ui, triggers/actions/scheduler, sales/customer/catalog/inventory/review/newsletter | all of the above |

Placement rules (also the review checklist for future backlog items):

1. **Root ownership** — a pack owns a condition root iff it owns the entity's Magento module.
2. **Relations live with their source entity's pack** (where they appear in the UI): `order.*`
   and `quote.*` → sales; `customer.*` → customer.
3. **Aggregates live with the pack owning the queried data**, contributed to the target root via
   the aggregate pool: order-history aggregates on the customer root belong to sales.
4. **Option sources live with their consumer.**
5. **Packs may require any never-absent `magento/*` module freely** (Sales, Customer, Catalog,
   Quote, Eav, CatalogInventory, SalesRule, Email — required by `product-community-edition`).
   Workflows packs never require each other *laterally* (sales ↔ customer); domain packs may —
   and must, honestly — require the shared infrastructure packs they build on (`workflows`
   always; `workflows-triggers-core` when they publish through its `EventPublisher`, as the
   sales pack's order-status observer does). Only the small optional-domain packs may
   additionally require the domain packs beneath them (newsletter → customer).
6. The `set_stock` runtime-guard style stays reserved for genuinely removable package families
   (MSI) inside an otherwise hard-dep class.

## Enablers

| ID | Item | Why |
|---|---|---|
| E1 | `HydrationProvider`: today it merges the DI `hydrators` array **over constructor-defaulted concrete hydrators**. Remove the defaults; all four hydrators become di.xml contributions from their packs | The engine cannot type-hint classes that move out |
| E2 | Aggregate pool: `Condition/Customer/Attribute::AGGREGATE_ATTRIBUTES` (const) + the directly-wired `CustomerAggregateProvider` become a per-root DI map of aggregate providers supplying both attribute metadata (label, input type) and hydration | Lets sales contribute order-history aggregates to the customer root; review/wishlist add `reviews_count` / `wishlist_items_count` later (core-coverage CUS-C3) without touching workflows-customer |
| E3 | `workflows-admin-ui` declares `magento/module-sales` for its `OrderRecentEntityProvider` (dry-run picker) | Never-absent, one line — no pooling ceremony needed |
| E4 | CI dependency-honesty check: fail if a module's compile-time references (`use` on extends/implements/type-hints, di.xml object refs) name a Magento module absent from its composer requires; annotation-allowlist for sanctioned runtime-guarded strings (`SetStock`'s `Magento\InventoryApi\*`). Also refresh the stale root `composer.json` `extra.packages` list (missing canvas, templates, approvals, admin-extension, import-suppression) | Keeps the split honest permanently |
| E5 *(added during S3/S4 merge)* | `ConditionLeafPool` — engine map entity_type → leaf condition class (string-wired, ObjectManager-created, mirroring `ConditionCombinePool`), plus a non-throwing `getCombineClass()` on the combine pool. The S3 report surfaced two lateral couplings hidden inside the sales pack while their targets lived in the engine: `Order/ItemsFound` constructor-injected the *catalog* pack's product leaf, and the Order/Quote combines imported the *customer* pack's root combine for their Customer subtree. Both now resolve by entity-type string through the engine pools and degrade gracefully (no product-attribute children / no Customer subtree offered) when the owning pack is absent — placement rule 5 holds with zero lateral requires | Cross-pack child-condition offering without compile coupling |

## Stages

**All stages S0–S6 are done (July 2026)** — see the Execution record above.

Each stage is 1–2 PRs, leaves `main` shippable, and moves the `Test/Unit` tree with its classes
(the standalone runner auto-discovers `src/module-*`; CI needs no changes).

| Stage | Contents | Depends on |
|---|---|---|
| S0 | E1–E4, plus the **golden composition test**: a suite-level test snapshotting the merged surface (action codes, combine roots, relation codes, hydrator keys, option-source codes, trigger events) as a fixture; every later stage must leave it byte-identical | — |
| S1 | Create `workflows-sales`; move everything in its row; delete the moved di/events/async-events/trigger-metadata blocks from engine, triggers-core, actions-core, scheduler | S0 |
| S2 | Create `workflows-customer` (same shape) | S0 |
| S3 | Create `workflows-catalog` | S0 |
| S4 | Create `workflows-inventory` — includes the db_schema + whitelist move of `mageos_workflow_stock_flag` out of the engine, and the threshold config (path string preserved) | S0 |
| S5 | Create `workflows-review` + `workflows-newsletter`; extract anonymize's newsletter-unsubscribe into the newsletter pack's plugin; drop actions-core's newsletter require | S0 (S2 for the plugin target) |
| S6 | Honesty pass: slim the four shared packs' composer.json/module.xml to their true remainders (E4 check goes from warning to blocking); `workflows-suite` metapackage; update [02 — Packages](../../02-packages.md), README repo layout, root `extra.packages` | S1–S5 |

**Parallelization:** S1–S5 are logically independent but all *remove different blocks from the
same shared `etc/*.xml` files* — run them as parallel agents in isolated worktrees and merge
sequentially (S1 first, it's the largest), rebasing each on the previous merge; conflicts are
confined to adjacent deletions in di.xml/events.xml and resolve mechanically.

## Tests

- **Golden composition test (S0)** is the load-bearing safety net: a pure-move refactor is
  correct iff the merged pools, trigger metadata and hydrator/relation keys are identical
  before and after. It stays in the suite afterward as the composition-conformance test.
- Moved unit suites run unchanged from their new homes (runner discovery is by `src/module-*`
  glob; shims unaffected).
- E4's honesty check runs on every module including the new ones from day one.
- The integration-test lane ([20](../../20-integration-test-plan.md)) picks up the new modules
  by the same enabled-module list it already uses; no harness change expected — verify in S6.

## Compatibility notes

- **Definitions, templates, spec, REST, ACL:** untouched — codes are the contract and codes do
  not change. The 14 bundled template envelopes validate against the same pools.
- **DB:** two declarative-schema ownership transfers, same table names
  (`mageos_workflow_stock_flag` engine → inventory; `mageos_workflow_abandoned_flag` scheduler →
  sales). Declarative schema treats same-name redeclaration in another module as continuity —
  no drop/recreate; whitelist entries move with them. Verify on the live-install gate anyway.
- **Config:** `mageos_workflows/scheduler/stock_threshold` and friends keep their literal paths
  regardless of which module declares them.
- **Module enablement:** new modules must be enabled together with their movers in one
  `setup:upgrade`; the suite metapackage makes that the default install path.
- **`<sequence>`:** each domain pack sequences after `MageOS_Workflows` (and
  `MageOS_WorkflowsTriggersCore` where it declares async events); sequence references are free.
- **Estimate:** ~1.5–2 wk single-engineer equivalent; S1 is roughly half of it. As parallel
  agent work: S0 first, then S1–S5 concurrent in worktrees, S6 closes.
