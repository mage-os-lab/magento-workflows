# 02 — Entity Cross-Referencing: Implementation Plan

**Discovery:** [entity-cross-referencing.md](../entity-cross-referencing.md) · **Foundations used:** F4, F5
**Modules touched:** `module-workflows`, `module-workflows-admin-ui`

## Intent

Stand up the relation registry (F5) with the condition layer as its first consumer, so
[fan-out](04-fan-out.md) later consumes a proven registry. Everything lives in the
condition/hydration layer — no executor, queue, or persistence changes at all.

## Components

| Component | Home | Intent |
|---|---|---|
| `RelationInterface` + `RelationPool` (F5) | `Api/`, `Model/Relation/` | The extension surface; di.xml type-array — mirror `Model/Action/ActionPool.php` |
| `RelationContext` (F5) | `Model/Relation/` | The pinned F5 surface (`resolve(code, $source)`, `resolveWebsiteId`, `isFresh`) — memoization with the `source_id > 0` guard, fresh-flag threading, website scoping (per-website vs global share mode; fail-toward-false + warn when indeterminable). Feature code never calls `RelationInterface::resolveIds()` directly |
| `RelatedEntity\Combine` | `Model/Rule/Condition/RelatedEntity/` | One generic combine: relation select, EXISTS/NOT EXISTS value, ANY/ALL/NONE for cardinality-many; resolves ids → `getEntity()` → `propagateHydrationKeys()` (the existing traversal primitive); children are the target root's existing leaf conditions |
| Cap semantics | in the combine | ANY/NONE evaluate first-N + warn; ALL over truncated = false + warn. Cap config: `mageos_workflows/guards/relation_cap`, default 100 (the existing guards convention) |
| Seed resolvers | `Model/Relation/Resolver/` | `order.customer` (formalizes the FK subtree), `order.customer_by_email`, `quote.customer_by_email`, `order.orders_by_email`, `customer.open_orders` — per discovery §4; state lists fixed in the resolver, not merchant-config |
| Classifier node-type extension (F4) | `Model/Rule/AttributeClassifier` | Registry of hydration-forcing node types; `RelatedEntity` is the first entry |
| Save-time rule | F2 check | Children under NOT EXISTS = **hard error** (the operators aren't complements once children exist — adversarial-review finding) |
| UI + rendering | admin-ui + core renderer | Child-select entries under each root combine (only where relations exist for that entity); plain language: "if no customer account matches the order email" |
| Metadata exposure | F6 endpoint | `GET /V1/workflows/meta/relations` lists the pool (canvas/gallery/CI consumers) |

## Stages

| # | Stage | Notes / done-when |
|---|---|---|
| 1 | F5 skeleton: interface, pool, `RelationContext` with scoping + memoization rules + tests | The multi-site correctness argument is made here, once. Done when: the share-mode × store-presence matrix tests pass |
| 2 | `RelatedEntity\Combine` + classifier node-type extension + NOT-EXISTS save rule | Wire into root combines **only where `RelationPool` has ≥1 relation with that source entity** — Order/Quote/Customer in the seed set, **not Product** (it sources no relations; the discovery rule is conditional by design). Done when: childless NOT-EXISTS classifies `needs_hydration`; Product's child-select options are asserted unchanged; NOT-EXISTS-with-children save is a hard error |
| 3 | Seed resolvers + per-resolver tests (dual share-mode, storeless dispatch, guest cases) + a conformance-style fixture (guest-nudge workflow) | The flagship ships here. Done when: the guest-nudge fixture evaluates correctly in both share modes |
| 4 | Plain language, UI child options, `meta/relations` endpoint, docs (06 update) | |

## Tests

`RelationContext`: share-mode matrix (global/per-website × store present/absent/0), memoization
(hit, `source_id=0` skip, fresh-flag bypass). Combine: EXISTS/NOT-EXISTS × one/many × cap
overflow table tests, children-evaluation only when resolved, resolver-exception →
fail-toward-false + log. Classifier: childless NOT-EXISTS tree classifies `needs_hydration`
(the exact case the review caught). Scheduler: a relation-bearing tree falls back to
load-and-filter (existing `ConditionToSearchCriteria` null path — assert, don't change).

## Compatibility notes

- Existing `Customer\Combine` FK subtree keeps working untouched; `order.customer` registry
  entry is additive, and any later migration of the old subtree onto the registry is optional
  cleanup, not required.
- Relation-free workflows provably keep the zero-query snapshot path (classifier change only
  *adds* a hydration trigger for trees containing the new node type).
- Third-party relation packs: one resolver class + one di.xml line; document in the SDK section
  of docs/07 alongside actions.
