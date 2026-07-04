# Discovery — Entity-Grid Visibility (Workflows Surfaced on Native Grid Pages)

**Status:** Implemented (July 2026) · **Track:** discoverability · **Origin:** [issue #5](https://github.com/rhoerr/magento-workflows/issues/5)
**Related:** [11 — Admin UI](../11-admin-ui.md) · [02 — Package Decomposition](../02-packages.md) · [09 — Scope, ACL & Observability](../09-scope-acl-observability.md)

---

## 1. The gap

Everything the engine offers today lives under **Marketing → Workflows**. A merchant staring at
the Orders grid — the page where "when an order is created…" automations *matter* — gets no
signal that the workflow feature exists, no signal that three workflows are already firing on
every row they're looking at, and no path shorter than four navigation hops to create one.

[Issue #5](https://github.com/rhoerr/magento-workflows/issues/5) proposes surfacing workflows on
the related native grid pages (Orders, Customers, Products, …): show which workflows exist for
that entity type, plus an entry point to create a new one. Requirements from the issue thread:

- A **new addon module**, with an option to disable.
- It should feel like a **seamless, inherent part of the system** anywhere it shows up — not a
  promo banner.
- Goals: make the feature easier to *find*, and make it easy to see *when workflows are set up*
  for a given entity type.

## 2. Assessment — is this a good idea?

**Yes.** It is cheap, low-risk, architecturally already paid for, and attacks the one problem no
engine feature solves: adoption. Three facts make it nearly free:

1. **`entity_type` is already first-class.** Every workflow row carries it
   (`src/module-workflows/etc/db_schema.xml` — `mageos_workflow.entity_type`), every trigger
   declares its entity in `workflow_triggers.xml`, and the authoritative entity-type catalogue is
   DI-registered on `Model\Webapi\EntityTypeMetadataProvider`
   (`src/module-workflows/etc/di.xml`). "All workflows for Orders" is one `getList` call filtered
   by `entity_type` — no schema change, no new concept.
2. **The addon convention already exists.** `workflows-import-suppression` is the template: a
   micro-module sequenced on core, appending a single Yes/No field to the shared
   `mageos_workflows` config section. The disable requirement is a solved pattern.
3. **The grid table is tiny.** A store has dozens of workflows, not millions; the per-page-load
   cost is one cheap query, trivially cacheable.

The honest costs, and why they're acceptable:

- ⚠️ **Touching native admin pages is new territory.** Nothing in the module set today modifies
  a core Magento page — all layout handles are our own. This is exactly why it must be an
  optional addon (blast radius contained; uninstall/disable restores stock pages byte-for-byte),
  and why the injection mechanism must be the least invasive one available (§3).
- ⚠️ **Grid pages are a contested surface.** Third-party grid replacements (Amasty Grid, etc.)
  swap the ui_component wholesale. Any design that grafts onto the *grid component itself*
  inherits that fragility; a design that renders *beside* the grid does not.
- ⚠️ **One more page-load dependency on core admin routes.** Mitigated by ACL gating (renders
  nothing without `MageOS_Workflows::view`), the config toggle, and a cached count.

Not a reason to hesitate, but worth naming: this feature ships **zero engine capability**. It is
pure discoverability. That's fine — AutomateWoo, Klaviyo, and every automation product that
merchants actually adopt invest in contextual entry points, and the engine's docs
([17 — Use Cases](../17-use-cases.md)) already bet on merchants self-serving. Discoverability is
the missing rung of that ladder.

## 3. Approaches

### V1 — Summary strip beside the grid, via native layout handles — recommended

A small addon module ships layout XML for each native grid handle
(`sales_order_index.xml`, `customer_index_index.xml`, `catalog_product_index.xml`, …), each
injecting one shared block into the standard `page.main.actions` container with an
`entity_type` argument. The block renders a compact, admin-styled strip:

> **Workflows:** 3 active for Orders · **View** · **Create workflow**
>
> — or, when none exist yet (the discoverability case that motivates the feature):
>
> **Workflows:** none yet for Orders · **Create one**

- No ui_component surgery, no plugins on core blocks, no JS mixins. `page.main.actions` is a
  stable, documented container present on every admin listing page; grid-replacement extensions
  don't touch it.
- The block degrades to *rendering nothing* when: the module toggle is off, the admin lacks
  `MageOS_Workflows::view`, or the count lookup fails for any reason. A layout handle for a
  module that isn't installed (e.g. `review_product_index` on a build without reviews) simply
  never fires.
- "Seamless and inherent": plain admin typography in the actions toolbar area, no icons-with-
  gradients, no dismissible banner state to manage. The config toggle *is* the dismissal.

### V2 — Extend the native grid ui_components (column / toolbar button) — rejected

Shipping `sales_order_grid.xml` etc. to add a toolbar button or column merges into the core
component definition. Rejected: per-row data is wrong for this feature (workflows relate to the
entity *type*, not to individual rows — a per-row column would have to show the same value on
every row or pivot to *executions*, which is a different feature), and component merging is the
exact surface grid-replacement extensions break. Highest cost, most fragile, no added value
over V1.

### V3 — Menu/dashboard-only promotion — rejected

A dashboard widget or menu badge avoids native pages entirely but doesn't answer the issue: the
point is meeting the merchant *on the page where the entity lives*. Doesn't satisfy "when there
are workflows set up for a given entity type" either, since the merchant isn't looking at the
entity when they see it.

### Per-row / entity-view surfacing — deferred, not rejected

"Anywhere it shows up" naturally extends to entity **view** pages later: a *Run workflow* button
on the order view (manual triggers + `MageOS_Workflows::manual_run` already exist), or "recent
executions for this order" on the view page (execution log already stores `entity_id`). Both are
genuinely useful and more invasive (per-entity queries, order-view button pools). They belong to
a follow-up scope once the grid strip proves the pattern — see §6.

## 4. Recommended design

**New module:** `mage-os/workflows-admin-extension` / `MageOS_WorkflowsAdminExtension`
(`src/module-workflows-admin-extension/`), following the `workflows-import-suppression` shape:

- `module.xml` sequence: `MageOS_Workflows`, `MageOS_WorkflowsAdminUi`, `Magento_Backend`.
  Composer requires only `mage-os/workflows`, `mage-os/workflows-admin-ui`,
  `magento/framework` — the entity modules (Sales, Customer, Catalog, Review) are *soft*
  dependencies: their handles no-op when absent, so no hard `require`.
- **Grid map** (DI-registered array on the view model, same pattern as the entity-type
  catalogue): layout handle → entity type. v1 coverage:

  | Handle | Entity type |
  |---|---|
  | `sales_order_index` | `sales_order` |
  | `customer_index_index` | `customer` |
  | `catalog_product_index` | `catalog_product` |
  | `review_product_index` | `review` |
  | `sales_invoice_index` | `invoice` |
  | `sales_shipment_index` | `shipment` |
  | `sales_creditmemo_index` | `creditmemo` |

  (`quote` has no native grid — the abandoned-cart *report* is not a ui listing; skip in v1.)
  Third parties extend coverage by adding a layout file + one DI array entry.
- **`WorkflowCountProvider`** (addon-owned): `WorkflowRepositoryInterface::getList` filtered by
  `entity_type` + enabled status; returns `{enabled, total}`. Result cached in the default cache
  backend keyed by entity type, invalidated by an addon-owned plugin on repository
  `save`/`delete`/`deleteById`. Entity-type labels come from
  `EntityTypeMetadataProviderInterface` — no duplicated label list.
- **Links:**
  - *View* → `mageos_workflows/workflow/index` pre-filtered to the entity type via the
    listing's server-side `filters_modifier` URL mechanism (the same one core modal grids use;
    works with the generic DataProvider, no listing changes). Known caveat: the filter doesn't
    render as a removable chip — acceptable for a deep link, note in the doc.
  - *Create workflow* → `mageos_workflows/workflow/edit?entity_type=sales_order`, shown only
    with `MageOS_Workflows::manage`.
- **Config toggle:** `mageos_workflows/general/expose_on_entity_grids` (Yes/No, default **Yes**
  in the addon's `config.xml`), appended to the core section exactly like
  `suppress_bulk_imports`. Plus, being an addon, `bin/magento module:disable` removes it
  entirely.
- **ACL:** strip hidden without `MageOS_Workflows::view`; when the count is zero *and* the admin
  lacks `::manage`, render nothing (nothing actionable to show).

**Two small enabler changes in `module-workflows-admin-ui`** (additive, useful independently):

1. `Model/Workflow/DataProvider.php` seeds the new-workflow form from a validated `entity_type`
   request param (checked against `EntityTypeMetadataProviderInterface`; ignored if unknown).
   The entity-type select then defaults correctly on the "create from Orders grid" path.
2. While in there, close the flagged v1 shortcut in `Model/Source/EntityType.php` (its docblock
   already calls this out): source the options from `EntityTypeMetadataProviderInterface`
   instead of the hardcoded list, so the addon, the form, and the REST catalogue can never
   disagree.

## 5. Implementation plan

Landed as four PR-sized stages, each shipped independently green. The standalone test runner
auto-discovers `Test/Unit` in any `src/module-*` directory, so no runner changes were needed.

| Stage | Scope | Est. |
|---|---|---|
| **S1 — Admin-UI enablers** | `entity_type` preselect param in the form DataProvider (validated against the metadata provider); `Source\EntityType` re-based onto the metadata provider. Unit tests for both. | ~1–2 d |
| **S2 — Addon skeleton + count provider** | Module scaffold (`registration.php`, `module.xml`, `composer.json`, `acl` none — reuses core resources), `WorkflowCountProvider` + cache + invalidation plugin, config toggle (`system.xml` append + `config.xml` default). Unit tests: count filtering, cache invalidation on save/delete, toggle short-circuit. | ~2–3 d |
| **S3 — Strip block + native handles** | View model (grid map, ACL + toggle gating, link builder incl. `filters_modifier` deep link), block + `.phtml` styled to the admin actions area, seven layout handle files. Unit tests: link builder, gating matrix (toggle × ACL × count). | ~2–3 d |
| **S4 — Docs & polish** | New row in [02 — Packages](../02-packages.md); §entry-points subsection in [11 — Admin UI](../11-admin-ui.md); README module table; i18n `en_US.csv`; this doc's status flipped to Implemented. | ~1 d |

**Total: ~1.5–2 weeks** senior-M2 effort including tests — the smallest feature in the discovery
set, with the highest adoption leverage per day spent.

## 6. Deferred follow-ups (explicitly out of v1)

- **Entity view pages**: *Run workflow* button on the order/customer view (composes with manual
  triggers + `manual_run` ACL), and "executions for this entity" panel (execution log already
  keys by `entity_id`). Natural v2 once the strip pattern is proven.
- **Abandoned-cart report** surfacing for `quote` workflows (non-ui-listing page, bespoke).
- **Per-row execution indicators** on native grids — different feature (execution observability,
  not workflow discoverability), and it *would* require ui_component surgery; re-evaluate only
  with concrete merchant demand.
