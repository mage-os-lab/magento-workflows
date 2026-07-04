# 07 — Action Framework

## Contract

```php
interface ActionInterface
{
    public function execute(ExecutionContext $ctx, array $config): ActionResult;
    // ActionResult: status, output (merged into context as steps.<key>), retryable flag
}

interface ActionMetadataInterface   // drives UI form generation
{
    public function getCode(): string;          // "order.add_comment"
    public function getLabel(): string;
    public function getGroup(): string;         // Sales / Customer / Catalog / Notify / Flow
    public function getApplicableEntities(): array;
    public function getConfigForm(): array;     // declarative field defs -> dynamicRows fieldset
    public function getAclResource(): ?string;  // gate who may *author* this action
}
```

Registered via `di.xml` type-array into `ActionPool`. A third-party module = one class + one `di.xml` entry + optional `workflow_triggers.xml`. **That *is* the connector SDK** — no new plumbing concept required.

**Relation packs (same shape).** Cross-entity *relations* extend the same way: implement
`RelationInterface` (`getCode`/`getLabel`/`getSourceEntityType`/`getTargetEntityType`/`getCardinality`/`resolveIds`)
and add one line to the `RelationPool` type-array in `di.xml`. The relation then appears
automatically in every root combine of its source entity, in the classifier's hydration-forcing
set, and at `GET /V1/workflows/meta/relations` — no core change. Examples: B2B `company → users`,
an RMA module's `order → returns`. Resolve via repositories/`SearchCriteria`; `resolveIds()` is
called only through `RelationContext` (which supplies memoization, website scoping, the resolution
cap, and fail-toward-false), so never call it directly. See
[entity cross-referencing](discovery/entity-cross-referencing.md).

An optional `simulate()` interface is added to the contract in v1 (as an optional interface) so the core library is ready for shadow mode and dry-run ([Admin UI §Shadow mode](11-admin-ui.md#shadow-mode-v1-nearly-free)).

## Core library (v1, `workflows-actions-core`)

| Group | Actions |
|---|---|
| Sales | add order comment · change order status² · hold/unhold · create invoice (capture online/offline) · create shipment³ · create credit memo (offline full refund)³ · cancel order |
| Customer | assign group · set custom attribute · subscribe/unsubscribe newsletter · anonymize⁴ · add to segment (Commerce) |
| Catalog | set attribute value (scoped) · enable/disable product · set stock status/qty (MSI source-aware⁵) · set categories (add/remove/replace) · set special price (from/to dates; `clear: true` removes it) |
| Marketing | generate coupon from cart price rule · apply customer tag attribute |
| Notify | send email (transactional template + context vars, or ad-hoc `subject` + `body`⁶) · **call webhook** · admin notification (inbox) |
| Flow | delay · stop · set context variable |

² Status transitions are validated against the state machine — reuse `Magento\Sales\Model\Order` guards; an invalid transition = step failure, not silent corruption.

³ Same guard philosophy: `order.create_shipment` (`ShipOrderInterface`) is gated by `canShip()`, `order.create_creditmemo` (`RefundOrderInterface`) by `canCreditmemo()` — a non-shippable/non-refundable order is a step failure, not silent corruption.

⁴ `customer.anonymize` is a GDPR assist: scrambles PII fields to an RFC 2606 marker email and unsubscribes newsletter. It requires an explicit `confirm: true` in the step config (refuses to run otherwise) and is idempotent — re-running an already-anonymized customer is a no-op.

⁵ `product.set_stock` takes an optional `source_code` routing through MSI `SourceItemsSave` (requires `qty`; a `source_code` on an install without MSI is a terminal step failure, not a retry). Without `source_code` the legacy default-source path is unchanged. Full MSI-aware configuration remains an open question ([Risks](14-risks.md)).

⁶ Ad-hoc mode (`subject` + `body`, rendered through a bundled pass-through template) is mutually exclusive with `template_id`; variables work in both, and interpolated values in the ad-hoc body are HTML-escaped.

## Webhook action with response capture

Sync HTTP POST (Guzzle), JSON body rendered from context, HMAC-SHA256 signature header (same convention as the async-events HTTP notifier so receivers verify identically), configurable timeout (default 5s, cap 30s), `capture_as` key storing the parsed JSON response into `context.steps.<key>`.

First-class auth: `auth_type` (`bearer` | `basic`) plus `auth_secret` naming a stored secret — the value is resolved at send time and never appears in the definition or logs. An explicit `Authorization` header in the step's headers wins over `auth_type`.

Subsequent branch conditions can reference the response: `{{ steps.fraud.response.score }} > 80`.

Failure honors `retryable` — 5xx/timeout retries via queue redelivery; 4xx fails the step terminally.

**This action is an SSRF vector and must ship hardened, not hardenable** — the full hardening posture (private-range denial, DNS pinning, redirect re-validation, response caps, trust boundary for captured responses) is specified in [Security §SSRF hardening](10-security.md#ssrf-hardening-the-webhook-action). An optional response JSON Schema per step turns schema mismatch into step failure, keeping garbage out of downstream branches.

## Variable resolution

A **restricted mustache-style resolver** over the context bag: `trigger.*`, `steps.*`, `workflow.*`, `secrets.*`.

- **Not** `Magento\Framework\Filter\Template` — no directive execution, no method calls, dot-path array access only.
- **Whitelisted formatters**: `{{ path|filter }}` / `{{ path|filter:'arg' }}`, chainable (`{{ trigger.email|lower|trim }}`), from a fixed list — `upper`, `lower`, `trim`, `number[:decimals]`, `date[:'format']`, `default:'fallback'` (e.g. `{{ trigger.grand_total|number:2 }}`). Unknown filters are ignored; the no-code-execution stance is unchanged.
- Secrets are config-encrypted values referenced by key, never stored in definitions, redacted in logs ([Security §Secrets](10-security.md#secrets)).
- Interpolation supplies *values*, never *structure*: captured webhook responses are usable in conditions and interpolation but never as action identifiers or attribute codes ([Security §Trust boundary](10-security.md#ssrf-hardening-the-webhook-action)).

## Loop prevention, storms, and circuit breaking

Actions mutate entities; mutations fire events; events trigger workflows. Guards:

- **Chain depth:** executions carry `chain_depth`, propagated when an action's mutation causes a dispatch within the same request via a registry flag on the publisher — exceeding `loop_guard_depth` (default 1) skips dispatch and logs `loop_suppressed`.
- **Debounce, done atomically:** the same `(workflow_id, entity_id)` within N seconds collapses to one execution. A SELECT-then-INSERT check is racy under concurrent consumers — enforce with a unique key on `(workflow_id, entity_id, time_bucket)` and treat duplicate-key as debounced. No lock-service dependency.
- **Circuit breaker:** N consecutive step failures (default 10) or failure rate > X% over a window auto-pauses the workflow (`status = suspended`), fires an admin notification + email digest, and requires explicit re-enable. A misconfigured webhook must not silently burn the retry queue for days.
- **Bulk-operation suppression:** imports and mass-actions firing 100k `product.saved` events will detonate any per-entity engine. Ship a suppression API (`WorkflowSuppression::scope(callable)` + honored `bin/magento` flag + config toggle for known bulk paths like `catalog_product_import`). Phase 2: *aggregate triggers* — "fire once per batch with the matched collection" — turning the storm problem into a feature (e.g., "email me a summary of all products that went out of stock today").
