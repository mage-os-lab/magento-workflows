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
| Sales | add order comment · change order status² · hold/unhold · create invoice (capture online/offline) · create shipment³ · create credit memo (offline full refund)³ · add shipment tracking⁷ · send order email⁷ · cancel order |
| Customer | assign group · set custom attribute · subscribe/unsubscribe newsletter · anonymize⁴ · add to segment (Commerce) |
| Catalog | set attribute value (scoped) · enable/disable product · set stock status/qty (MSI source-aware⁵) · set categories (add/remove/replace) · set special price (from/to dates; `clear: true` removes it) |
| Marketing | generate coupon from cart price rule⁷ · apply customer tag attribute |
| Notify | send email (transactional template + context vars, or ad-hoc `subject` + `body`⁶)⁷ · **call webhook** · admin notification (inbox) |
| Flow | delay · stop · set context variable |

² Status transitions are validated against the state machine — reuse `Magento\Sales\Model\Order` guards; an invalid transition = step failure, not silent corruption.

³ Same guard philosophy: `order.create_shipment` (`ShipOrderInterface`) is gated by `canShip()`, `order.create_creditmemo` (`RefundOrderInterface`) by `canCreditmemo()` — but the two outcomes differ. An *illegal transition* (invalid target state) is a step failure; a *not-applicable* order (nothing left to ship, already fully refunded/invoiced) returns `skipped` with an explanatory message. Skipped-not-failed is what makes the actions safe under at-least-once delivery: a redelivered creditmemo step must not fail the execution because the refund already happened on the first delivery. Pinned by `CreateShipmentBehaviorTest`, `CreateCreditmemoBehaviorTest`, and `CreateInvoiceTest`.

  A state guard is **not** a redelivery guard, though, and `order.create_creditmemo` says so explicitly: its `percent`/`fixed` modes issue adjustment-only refunds that leave the order still creditmemo-able, so `canCreditmemo()` would wave a redelivered partial refund straight through. Every memo it creates therefore carries the standard execution-UUID + step-key dedupe marker ([08 — Execution Model](08-execution-model.md#crash-safety-and-delivery-semantics)) on a credit-memo comment — invisible on front, never a notified customer note — written inside the refund's own transaction, and every run scans the order's existing memos for its own marker before refunding. Pinned by `CreateCreditmemoDedupeTest`.

⁴ `customer.anonymize` is a GDPR assist: scrambles PII fields to an RFC 2606 marker email and unsubscribes newsletter. It requires an explicit `confirm: true` in the step config (refuses to run otherwise) and is idempotent — re-running an already-anonymized customer is a no-op.

⁵ `product.set_stock` takes an optional `source_code` routing through MSI `SourceItemsSave` (requires `qty`; a `source_code` on an install without MSI is a terminal step failure, not a retry). Without `source_code` the legacy default-source path is unchanged. Full MSI-aware configuration remains an open question ([Risks](14-risks.md)).

⁶ Ad-hoc mode (`subject` + `body`, rendered through a bundled pass-through template) is mutually exclusive with `template_id`; variables work in both, and interpolated values in the ad-hoc body are HTML-escaped.

⁷ **Per-action redelivery guards for the remaining side effects** (the executor's resume-past-complete guard covers the ordinary case; these cover a crash *inside* a step, after the effect landed but before the step row was marked `complete`):

  - `order.add_tracking` uses **natural idempotence** — the `(carrier_code, track_number)` pair IS the parcel's identity, so before appending it scans *every* shipment on the order for that pair and returns `skipped` (naming the shipment that holds it) when it is already there. No marker, no extra column, and it also suppresses an honest authoring duplicate that a per-execution marker could not see. Comparison is trimmed and case-insensitive on both halves. Pinned by `AddTrackingTest`.
  - `marketing.generate_coupon` derives the coupon **code itself** from the execution-UUID + step-key dedupe key (`WF` + 12 base32 chars of a SHA-256 of it), so the association cannot be lost in the crash window: a redelivery re-derives the code, finds the coupon through the repository, and hands the same code back as `skipped` — with `coupon_code` still in the step output for downstream interpolation. If two consumers race past that read, `salesrule_coupon`'s `UNIQUE(code)` decides it and the loser recovers the winner's coupon. A code that somehow exists on a *different* rule is a terminal failure, never a silently borrowed discount. Pinned by `GenerateCouponTest` (unit + integration). This replaced an unconditional `CouponGenerator` call, whose whole job — inventing a random code — was what leaked a second live discount per redelivery.
  - `notify.email` and `order.send_email` take a **durable send claim** before the send: an INSERT into `mageos_workflow_send_log` (`UNIQUE(claim_key)`) keyed on action code + dedupe key, via the shared `SendOnceGuard`. A duplicate key means somebody already claimed the send, so the step skips. Email is the one effect that can be neither undone nor detected afterwards, so the trade runs the other way from the money actions: a crash between claiming and confirming leaves a `claimed` row, and the redelivery skips a message that *may never have gone out* — the skip reason says exactly that. A failure from the send call itself keeps the claim and fails the step terminally rather than retrying into a possible second copy; only failures that provably precede the send (bad config, a transport that could not even be built) release the claim. Pinned by `EmailIdempotencyTest`, `SendEmailTest`, `SendOnceGuardTest`, `SendClaimStoreTest`.

## Template packs (SDK)

The same one-class-one-registration extension shape the `ActionPool` uses applies to the [template gallery](11-admin-ui.md#template-gallery-marketing--workflow-templates). A template pack is a **data-only** module: `*.json` files in a `templates/` directory, registered by adding the module name to `BundledTemplateSource::packDirectories` via `di.xml`:

```xml
<type name="MageOS\Workflows\Model\Template\BundledTemplateSource">
    <arguments>
        <argument name="packDirectories" xsi:type="array">
            <item name="acme" xsi:type="string">Acme_WorkflowTemplates</item>
        </argument>
    </arguments>
</type>
```

Each file is a `mageos-workflow-template/1` envelope ([`spec/workflow-template.schema.json`](../spec/workflow-template.schema.json)): `template{code, title, description, category, version, requires, parameters}` plus a `workflow{…}` node carrying the export envelope's fields. The pack ships **no PHP** — the catalog is data. The first-party pack is `mage-os/workflows-templates`; third-party packs are peers (see its `templates/README.md` for the authoring rules and the CI fixture-test pattern that keeps a pack honest as the action/trigger pools evolve). A future signed remote feed implements `TemplateSourceInterface` behind a default-off toggle without any gallery/installer change.

## Webhook action with response capture

Sync HTTP POST (Guzzle), JSON body rendered from context, HMAC-SHA256 signature header (same convention as the async-events HTTP notifier so receivers verify identically), configurable timeout (default 5s, cap 30s), `capture_as` key storing the parsed JSON response into `context.steps.<key>`.

First-class auth: `auth_type` (`bearer` | `basic`) plus `auth_secret` naming a stored secret — the value is resolved at send time and never appears in the definition or logs. An explicit `Authorization` header in the step's headers wins over `auth_type`.

Subsequent branch conditions can reference the response: `{{ steps.fraud.response.score }} > 80`.

Failure honors `retryable` — 5xx/timeout retries via queue redelivery; 4xx fails the step terminally.

**This action is an SSRF vector and must ship hardened, not hardenable** — the full hardening posture (private-range denial, DNS pinning, redirect re-validation, response caps, trust boundary for captured responses) is specified in [Security §SSRF hardening](10-security.md#ssrf-hardening-the-webhook-action). An optional response JSON Schema per step turns schema mismatch into step failure, keeping garbage out of downstream branches.

## Variable resolution

A **restricted mustache-style resolver** over the context bag: `trigger.*`, `steps.*`, `workflow.*`, `secrets.*`.

- **Not** `Magento\Framework\Filter\Template` — no directive execution, no method calls, dot-path array access only.
- **Whitelisted formatters**: `{{ path|filter }}` / `{{ path|filter:'arg' }}`, chainable (`{{ trigger.email|lower|trim }}`), from a fixed list — `upper`, `lower`, `trim`, `number[:decimals]`, `date[:'format']`, `default:'fallback'` (e.g. `{{ trigger.grand_total|number:2 }}`). Aggregated (batch) workflows add collection formatters over `trigger.items` — `count`, `pluck:'field'`, `join:', '`, `table:'f1,f2'`, `json` ([Definition Format §Variables](04-definition-format.md)). Unknown filters are ignored; the no-code-execution stance is unchanged.
- Secrets are config-encrypted values referenced by key, never stored in definitions, redacted in logs ([Security §Secrets](10-security.md#secrets)).
- Interpolation supplies *values*, never *structure*: captured webhook responses are usable in conditions and interpolation but never as action identifiers or attribute codes ([Security §Trust boundary](10-security.md#ssrf-hardening-the-webhook-action)).

## Loop prevention, storms, and circuit breaking

Actions mutate entities; mutations fire events; events trigger workflows. Guards:

- **Chain depth:** executions carry `chain_depth`, propagated when an action's mutation causes a dispatch within the same request via a registry flag on the publisher — exceeding `loop_guard_depth` (default 1) skips dispatch and logs `loop_suppressed`.
- **Debounce, done atomically:** the same `(workflow_id, entity_id)` within N seconds collapses to one execution. A SELECT-then-INSERT check is racy under concurrent consumers — enforce with a unique key on `(workflow_id, entity_id, time_bucket)` and treat duplicate-key as debounced. No lock-service dependency.
- **Circuit breaker:** N consecutive step failures (default 10) or failure rate > X% over a window auto-pauses the workflow (`status = suspended`), fires an admin notification + email digest, and requires explicit re-enable. A misconfigured webhook must not silently burn the retry queue for days. Suspension binds the *executor*, not just the dispatcher: an execution already queued (or parked, and resuming later) under a suspended workflow is failed **terminally** before it walks, error naming the suspension — otherwise the in-flight backlog keeps failing and being redelivered, which is exactly the burn the breaker is for. It follows that re-enabling does not resume what was failed while suspended; see [Operations §Circuit-breaker recovery](15-operations.md#circuit-breaker-recovery). *(The consecutive-failure counter itself is still cache-backed, so a cache flush resets it to zero — a known gap, stated rather than papered over.)*
- **Bulk-operation suppression:** imports and mass-actions firing 100k `product.saved` events will detonate any per-entity engine. Ship a suppression API (`WorkflowSuppression::scope(callable)` + honored `bin/magento` flag + config toggle for known bulk paths like `catalog_product_import`; packaged as the optional `workflows-import-suppression` module). *Aggregate triggers* — "fire once per batch with the matched collection" — have since been implemented (pending live-install verification), turning the storm problem into a feature (e.g., "email me a summary of all products that went out of stock today"): see [Execution Model §Aggregated (batch) workflows](08-execution-model.md) and [discovery/batch-aggregation.md](discovery/batch-aggregation.md).
