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

Exposed as a checkbox; **defaults to `true` on branches following delays**.
