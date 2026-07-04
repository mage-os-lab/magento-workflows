# 06 — Condition Engine

## Generalizing `Magento\Rule\Model`

`salesrule`/`catalogrule` hardcode their condition classes. We introduce an entity-keyed pool:

```php
class WorkflowRule extends \Magento\Rule\Model\AbstractModel
{
    public function getConditionsInstance()
    {
        return $this->conditionPool->getCombine($this->getEntityType());
        // sales_order  => Condition\Order\Combine
        // customer     => Condition\Customer\Combine
        // quote        => Condition\Quote\Combine (hydrates via CartRepositoryInterface)
        // catalog_product => reuse patterns from CatalogRule Product condition
    }
}
```

Per-entity condition classes follow the `AbstractCondition` contract:

- `loadAttributeOptions()` introspects EAV metadata (attribute repository) + selected flat columns
- `validate(AbstractModel $model)` evaluates

**Custom EAV attributes appear automatically** — the differentiator vs. every payload-JSON engine, and it costs nothing because CatalogRule's Product condition already demonstrates the pattern.

### Cross-entity traversal

Via child combines: an Order combine exposes a "Customer" subtree (hydrates via `order.customer_id → CustomerRepository`) and an "Items" subtree with ANY/ALL semantics over `OrderItemInterface` (the pattern exists in `SalesRule\Model\Rule\Condition\Product\Found`).

### Related-entity conditions (the relation registry)

Beyond the hardcoded `customer_id` FK, cross-entity traversal is generalized through a
**relation registry** (`RelationInterface` + `RelationPool`, DI-registered exactly like actions —
[entity cross-referencing](discovery/entity-cross-referencing.md)). One generic child condition,
`Condition/RelatedEntity/Combine`, is offered under every root combine whose entity type has a
registered relation (Order, Quote, Customer in the seed set — **not** Product, which sources
none):

- **Value = EXISTS / NOT EXISTS** (borrowed from the order-items `FOUND`/`NOT FOUND` pattern). The
  flagship guest check is literally: Order → *a customer account matching the order email* →
  **NOT EXISTS**.
- **Child conditions** run against the resolved target entity, reusing the target's own leaf
  classes (Customer `Attribute` with EAV auto-discovery and order-history aggregates included), so
  "…EXISTS **and** its `orders_count` ≥ 3" works day one. Resolution is `resolveIds()` →
  `getEntity(target, id)` → `propagateHydrationKeys()` — the existing primitive, fed by the
  registry.
- **Cardinality `many`** adds `ANY` / `ALL` / `NONE` over the resolved id list (`customer.open_orders`,
  `order.orders_by_email`).
- **NOT EXISTS forbids children** — a **hard save error**. The two operators stop being complements
  once children exist ("exists but children fail" makes *both* false), so "NOT EXISTS a customer
  with `orders_count` ≥ 3" would silently never match whenever any unqualified customer exists.
  Express the negation as `NONE` under `EXISTS` instead.

**Seed relations:** `order.customer` (the FK, re-expressed), `order.customer_by_email`,
`quote.customer_by_email` (both website-scoped per `customer/account_share/scope`),
`order.orders_by_email` (self + canceled excluded, newest-first), `customer.open_orders` (state in
{new, processing, holded}). The pool is enumerable at `GET /V1/workflows/meta/relations`.

**Cap & honesty (`mageos_workflows/guards/relation_cap`, default 100):** a to-many relation is
capped at resolution. `ANY`/`NONE` evaluate the first N and log — a match in the first N is a real
match. `ALL` over a *truncated* set is unknowable and **fails toward false with a warning**
(correctness over convenience). Resolver errors and unresolvable website scope also fail toward
false and never abort the execution.

**Phase discipline:** a `RelatedEntity` node needs the DB by definition, so any tree containing one
classifies `needs_hydration` — the `AttributeClassifier` gained node-type awareness precisely
because a childless `NOT EXISTS` references zero attributes and would otherwise read as zero-query.
Relation subtrees are never expressible as a `SearchCriteria`; scheduled workflows whose conditions
hinge on a relation fall back to load-and-filter (evaluated per candidate). `revalidate_entity: true`
after a delay re-resolves the relation — the guest may have registered *during* the wait.

### Entity roots and attribute coverage

- **Four roots**: `sales_order`, `customer`, `quote`, `catalog_product`. Quote is a first-class root (`Condition\Quote\Attribute` + `Combine`, `QuoteHydrator` via `CartRepositoryInterface`) — "cart total > $100" on `quote.abandoned` needs no workaround.
- **Order attributes** include addresses (`billing_` / `shipping_` country, region, postcode, city), `order_currency_code`, `discount_amount`, `total_paid`, `total_refunded`, and `customer_is_guest` alongside the totals/status basics.
- **Customer order-history aggregates**: `orders_count`, `lifetime_sales`, `avg_order_value`, `last_order_at`, `days_since_last_order`. These are never in the trigger snapshot — `CustomerAggregateProvider` computes them from `sales_order` on demand during the Phase-2 hydration pass (the save-time classifier marks them `needs_hydration` automatically), so they cost zero queries unless referenced. When an aggregate value is absent, only the negative operators (`!=`, `!{}`, `!()`) can match — fail-toward-false; a customer with zero orders has `orders_count = 0` but *no* `last_order_at` / `days_since_last_order` (there is no "days since" of nothing).
- **Trigger Data (advanced)**: a generic leaf matching any dot-path into the raw trigger payload — `from_status` / `to_status` on `sales.order.status_changed`, `from_group_id` / `to_group_id` on `customer.group_changed`, nested paths like `payment.method` or `items.0.sku`. Snapshot-only *by design* (transition metadata exists only in the payload; re-hydrating the entity could never produce it); a missing path resolves to null, matched only by the negative operators.
- **Relative date values**: date-type conditions accept expressions like `'-30 days'`, resolved against *now* at evaluation time — never frozen at save. The two canonical readings: `created_at <= '-30 days'` = created at least 30 days ago; `created_at >= '-30 days'` = created within the last 30 days.

## Two-phase evaluation (the EAV-at-scale answer)

The identified primary risk is hydration cost per event. Mitigation is structural, not tuning:

**Phase 1 — snapshot pass (cheap, no DB):** evaluate against the trigger payload wrapped in a `DataObject`. At workflow save time, statically analyze the condition tree: every attribute it references is classified `in_snapshot` or `needs_hydration` (the trigger's declared service class defines the snapshot shape). If all attributes are in-snapshot — the common case: totals, status, group id, SKUs — evaluation completes with **zero queries**.

**Phase 2 — hydration pass (lazy, scoped):** only if the tree references out-of-snapshot attributes, hydrate exactly the entities needed via the trigger's `resolver` (repository-backed, per-execution identity map). Short-circuit combinator evaluation ordering: in-snapshot conditions first, hydration-requiring conditions last, so an early `false` under ALL never touches the DB.

**Workflow index:** active workflows keyed by event name are held in a config-style cache (invalidated on save), so the dispatcher's "any workflows for this event?" check is an array lookup, not a query.

> A store firing 50k events/day with three workflows on `sales.order.created` does three snapshot evaluations per order and typically zero hydrations.

## Delay semantics

After a delay, the world has moved. Each post-delay branch/step carries `revalidate_entity: bool`:

| `revalidate_entity` | Behavior | Correct for |
|---|---|---|
| `true` | Re-hydrate fresh and re-evaluate (AutomateWoo's "validate before send") | "Email 1h after abandonment *if still abandoned*" |
| `false` | Evaluate against the frozen trigger snapshot | "Log what it looked like at order time" |

Exposed as a checkbox; **defaults to `true` on branches following delays**. The form assembler applies that default (a branch row whose preceding row is a `delay` gets `revalidate_entity: true` unless the row sets it explicitly); a post-delay `branch`/`switch` left at `false` raises the `GRAPH_POST_DELAY_STALE` warning in the save-time validation pipeline ([Execution Model §Static graph validation](08-execution-model.md#static-graph-validation)) — non-blocking, but usually a mistake. `switch` carries one shared `revalidate_entity` for the whole step: one hydration, N case evaluations.
