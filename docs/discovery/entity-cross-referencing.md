# Discovery — Data Hydrators & Entity Cross-Referencing

**Status:** Discovery / evaluation · **Track:** capability enhancements (beyond Phase 3)
**Related:** [06 — Conditions](../06-conditions.md) · [18 — Known Boundaries](../18-limitations.md) · [fan-out.md](fan-out.md) · [batch-aggregation.md](batch-aggregation.md)

---

## 1. The gap, by example

> *"When an order is placed as a guest, check whether the email already exists as a customer —
> if it does, nudge them to log in next time; if not, send a registration invite."*

Today this is impossible, and the reason is precise: cross-entity traversal exists but is
**hardwired to integer foreign keys**. The Order/Quote condition trees expose a Customer subtree
(`Condition/Customer/Combine.php`), but `resolveCustomer()` reads `customer_id` off the model
and calls `HydrationProvider::getEntity('customer', (int)$id)` — a guest order
(`customer_id <= 0`) resolves to `null` and the subtree simply validates `false`
(`Customer/Combine.php:62–86`). There is no way to *look up* an entity by anything other than
its primary id, and no way to express "no such entity exists" as a positive condition.

Adjacent flows blocked by the same wall:

- Guest repeat-buyer detection: "this email has ≥ 2 prior orders" (order → sibling orders by email).
- "Customer's *other* open orders" conditions (customer → orders, a to-many relation; only the
  fixed order-history aggregates exist today).
- Quote → customer-by-email on `quote.abandoned` (guest cart, known email).
- Any future fan-out target ("act on each open order of this customer" —
  [fan-out.md](fan-out.md)) needs exactly the same "resolve related entities" primitive.

## 2. What exists (the pieces to build on)

Verified against source — the machinery is closer than the docs suggest:

| Piece | What it gives us | Where |
|---|---|---|
| Cross-entity traversal primitive: `propagateHydrationKeys()` re-points a condition subtree at a different (type, id) with fresh/provider flags intact | The evaluation side of cross-referencing is done | `Model/Rule/Condition/AbstractWorkflowCombine.php:106–116` |
| Two worked examples of the pattern: Order/Quote → Customer (by `customer_id`), order items → Product (by `product_id`, with ANY/ALL semantics in `ItemsFound`) | The condition-UX template, including existential FOUND/NOT FOUND value semantics | `Condition/Customer/Combine.php`, `Condition/Order/ItemsFound.php:73–139` |
| Per-execution identity map in `HydrationProvider` (`"type:id"` → DataObject, memoized misses) | Repeat references cost one query | `Model/Rule/HydrationProvider.php:33, 61–77` |
| `EntityDataConverter` flattening (EAV/custom/extension attributes lifted to top level) | Related entities get full attribute coverage for free | `Model/Rule/Hydrator/EntityDataConverter.php` |
| `CustomerAggregateProvider` — derived data computed by a keyed query at hydration time | Precedent for "computed lookups" living in the hydrator layer | `Model/Rule/Hydrator/CustomerAggregateProvider.php` |
| Snapshot-first short-circuiting: nested combines are deferred behind in-snapshot leaves | Cross-entity subtrees are automatically evaluated last / skipped under an early `false` | `AbstractWorkflowCombine::sortForShortCircuit()` (58–71) |

What's genuinely missing: (a) **alternate-key lookup** (email → customer, scoped correctly),
(b) **to-many relations** beyond the hardcoded order-items case, (c) an **existence** condition
(the guest check is "NOT FOUND", not "attributes of null fail"), and (d) any *registry* of
relations that other features (fan-out, variables, UI pickers) can enumerate.

## 3. Approaches

### R1 — Extend the existing pattern ad hoc

Add `Condition/Order/CustomerByEmail/Combine.php` cloning the `Customer\Combine` shape but
resolving via a `CustomerRepository::get($email, $websiteId)` lookup; similar one-off classes per
future relation.

- ✅ Smallest possible diff for the flagship use case; perfectly consistent with existing style.
- ❌ Every relation is a new class + wiring in every parent combine; nothing is reusable by
  fan-out; the "which relations exist" question has no answer an endpoint or UI can give; the
  email-resolution logic (website scoping, guest semantics) gets copy-pasted into the quote twin.

### R2 — Relation registry + one generic related-entity condition (recommended)

Introduce a first-class **relation** concept, DI-registered like actions and hydrators:

```php
interface RelationInterface
{
    public function getCode(): string;              // "order.customer_by_email"
    public function getLabel(): string;             // "Customer matching the order email"
    public function getSourceEntityType(): string;  // sales_order
    public function getTargetEntityType(): string;  // customer
    public function getCardinality(): string;       // one | many
    /** @return int[] target entity ids (bounded; [] = none) */
    public function resolveIds(DataObject $source, RelationContext $ctx): array;
}
```

`RelationPool` (di.xml type-array, exactly the `ActionPool`/hydrator-pool pattern — this *is* the
extension surface for third parties). One new generic condition,
`Condition/RelatedEntity/Combine`, offered as a child by every root combine whose entity type has
registered relations:

- **Value semantics** borrowed from `ItemsFound`: `EXISTS` / `NOT EXISTS`. The guest check is
  literally: Order → *Related: Customer matching the order email* → **NOT EXISTS**.
- **Child conditions** on the target entity reuse the target's existing leaf classes (Customer
  `Attribute` with EAV auto-discovery and aggregates included — "…EXISTS and its `orders_count`
  ≥ 3" works day one). Resolution: `resolveIds()` → `getEntity(target, id)` →
  `propagateHydrationKeys()` — the existing primitive, now fed by the registry.
- **Cardinality many** gets `ANY` / `ALL` / `NONE` match modes over a capped id list (default cap
  100, config; over-cap logs and evaluates the first N — same honesty rule as the scheduler's
  match cap).

Hydration API extension: none required for the interface above — relations resolve *ids*, then
reuse `getEntity()` and its identity map. Resolvers that need a query (email→id, customer→open
order ids) run it themselves via repositories/`SearchCriteria`, memoized in a small per-execution
`RelationContext` map keyed `(relation_code, source_id)` so a relation referenced by three
conditions costs one lookup.

- ✅ One concept powers conditions **and** [fan-out](fan-out.md) targets **and** (later)
  variable exposure and UI pickers; relations are enumerable (metadata endpoint for the canvas).
- ✅ Guest example ships as configuration of the framework, not as the framework.
- ⚠️ More upfront design than R1 — cardinality, caps, scoping rules must be decided once, well.

### R3 — Generalized join/graph engine (conditions declare arbitrary traversals)

Arbitrary attribute-to-attribute joins declared in the condition tree ("where order.field X
matches customer.field Y"). Rejected: it converts merchant conditions into a query language,
explodes the evaluation-cost analysis that two-phase evaluation exists to control
([06](../06-conditions.md#two-phase-evaluation-the-eav-at-scale-answer)), and every concrete use
case anyone has named fits a *curated, named* relation. Named relations are also the only shape
a merchant UI can render honestly.

**Recommendation: R2**, seeded with a small curated set (§4). R1 is what R2 degenerates into if
we skip the registry — same classes, no reuse.

## 4. Seed relations (v1 content)

| Code | Source → target | Cardinality | Resolution | Flagship use |
|---|---|---|---|---|
| `order.customer` | order → customer | one | existing `customer_id` FK, re-expressed as a relation (the current subtree keeps working; it becomes the first registry entry) | formalizes the existing pattern |
| `order.customer_by_email` | order → customer | one | `customer_email` → customer, **website-scoped** (§5) | the guest check |
| `quote.customer_by_email` | quote → customer | one | same, from quote email | guest abandoned-cart |
| `order.orders_by_email` | order → orders | many | same email, excluding self; capped, newest-first | guest repeat-buyer |
| `customer.open_orders` | customer → orders | many | `customer_id`, state in {new, processing, holded} | conditions now, [fan-out](fan-out.md) target later |

Each ships with unit tests and a conformance-style fixture. Third-party packs register more
(B2B: company → users; RMA modules: order → returns) via `di.xml` — no core change.

## 5. Semantics that must be decided once, correctly

- **Website scoping of email lookups.** Magento customer accounts are global or per-website
  (`customer/account_share/scope`). The resolver must honor it: per-website installs scope the
  lookup by the execution's store → website; global installs search globally. Getting this wrong
  produces false "customer exists" on multi-site installs — the resolver reads the config, and
  the tests cover both modes. Case-insensitivity follows Magento's own email semantics.
- **Missing/none:** `resolveIds() = []` → `EXISTS` is false, `NOT EXISTS` is true, child
  conditions are not evaluated (there's nothing to evaluate them against). Resolver *errors*
  (repository exception) evaluate the subtree to `false` and log — fail-toward-false, consistent
  with the aggregate-absence rule in [06](../06-conditions.md#entity-roots-and-attribute-coverage);
  they never fail the execution.
- **Phase discipline:** relation subtrees are **always phase-2** (they need the DB by
  definition). The save-time classifier marks any tree containing a `RelatedEntity` combine as
  `needs_hydration`; snapshot-only workflows keep their zero-query fast path untouched, and
  short-circuit ordering already defers these subtrees behind cheap leaves.
- **`revalidate_entity` interplay:** the `fresh` flag propagates through `RelationContext` — a
  post-delay branch with `revalidate_entity: true` re-resolves the relation (the customer may
  have registered *during* the delay — which is exactly the guest-flow point: "wait 3 days; if
  the email **now** exists as a customer, skip the invite").
- **Scheduler mapping:** relation subtrees are never expressible as `SearchCriteria`;
  `ConditionToSearchCriteria::convert()` already returns null on nested combines, falling back to
  load-and-filter — no scheduler change needed, but document the cost (a schedule whose
  conditions hinge on relations evaluates per candidate).
- **Trust boundary unchanged:** relations are code-registered, not definition-supplied — a
  definition references a relation by code the way it references an action by code; unknown codes
  fail validation at save/import ([10 — Security](../10-security.md#import-is-untrusted-input)).

## 6. Explicitly deferred (with the seam named)

- **Related data in variables** (`{{ related.customer_by_email.firstname }}`): valuable
  (personalize the nudge email) but touches the resolver's restricted-roots contract
  ([07 §Variable resolution](../07-actions.md#variable-resolution)) and raises "when is it
  resolved and is it persisted into context?" questions. Seam: a `flow.hydrate_related` action
  (config: relation code + field allowlist) that writes selected fields into
  `steps.<key>.*` — reuses the existing step-output mechanism, keeps the resolver untouched, and
  makes the PII persistence explicit and auditable. Design in a follow-up once conditions ship.
- **Relations as condition *roots*** (trigger on order, conditions rooted at the related
  customer): unnecessary — subtrees cover it.
- **Cross-entity write actions** ("update the matched customer"): actions receive the trigger
  entity today; acting on a *related* entity is [fan-out](fan-out.md)'s job (one execution per
  target, correct guards), not a hydration feature.

## 7. Quality, maintainability, reliability

- **Performance:** bounded and memoized — one resolver query per (relation, source) per
  execution, identity-mapped entity loads, subtrees deferred by short-circuit ordering, to-many
  caps. The zero-query snapshot path is provably untouched (classifier + phase discipline).
- **Reliability:** resolvers fail toward false and never abort executions; no new persistence,
  no new queue interactions, no executor changes — this is entirely inside the condition/
  hydration layer.
- **Maintainability:** one interface + one pool + one generic combine; new relations are data
  (a resolver class + di.xml line), mirroring the action SDK. The registry gives the canvas and
  REST metadata endpoints ([canvas.md §2](canvas.md)) an enumerable source instead of
  reflection.
- **Testability:** resolvers are pure-ish repository wrappers (unit-testable under the shim
  harness); the combine's EXISTS/ANY/ALL semantics get table-driven tests like `ItemsFound`;
  website-scoping gets explicit dual-mode tests.

## 8. Sequencing & effort

| Order | Item | Effort |
|---|---|---|
| 1 | `RelationInterface` + `RelationPool` + `RelationContext` (memoization, caps, scoping) | ~1 wk |
| 2 | `RelatedEntity\Combine` (EXISTS/NOT EXISTS, one/many, child-tree wiring) + classifier hook | ~1–1.5 wk |
| 3 | Seed relations incl. website-scoped email resolvers + tests + fixture | ~1 wk |
| 4 | UI surfacing (child-select entries, plain-language rendering: "if no customer account matches the order email") + docs | ~0.5–1 wk |

Total ≈ 3.5–4.5 wks. No dependencies on Phase-3 items; **[fan-out.md](fan-out.md) depends on
this** (the registry is its target resolver), which is why this doc sequences first among the
three enhancement tracks.

## 9. Open questions

1. Should `EXISTS` subtrees with child conditions distinguish "no entity" from "entity exists
   but children fail"? Current proposal: no — `EXISTS` + children means "exists and matches",
   `NOT EXISTS` ignores children (validated at save: children under NOT EXISTS = warning).
2. Cap behavior for `ALL` over a truncated to-many list: evaluate-first-N-and-warn vs fail
   toward false. Leaning fail-toward-false with a logged warning ("ALL over truncated set is
   unknowable") — correctness over convenience.
3. Does `order.orders_by_email` count archived/canceled orders? Proposal: exclude canceled
   (consistent with `CustomerAggregateProvider`), make state list part of the relation's
   definition, not merchant-configurable in v1.
