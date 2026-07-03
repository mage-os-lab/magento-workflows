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

An optional `simulate()` interface is added to the contract in v1 (as an optional interface) so the core library is ready for shadow mode and dry-run ([Admin UI §Shadow mode](11-admin-ui.md#shadow-mode-v1-nearly-free)).

## Core library (v1, `workflows-actions-core`)

| Group | Actions |
|---|---|
| Sales | add order comment · change order status² · hold/unhold · create invoice (capture online/offline) · cancel order |
| Customer | assign group · set custom attribute · subscribe/unsubscribe newsletter · add to segment (Commerce) |
| Catalog | set attribute value (scoped) · enable/disable product · set stock status/qty (MSI source-aware) |
| Marketing | generate coupon from cart price rule · apply customer tag attribute |
| Notify | send email (transactional template + context vars) · **call webhook** · admin notification (inbox) |
| Flow | delay · stop · set context variable |

² Status transitions are validated against the state machine — reuse `Magento\Sales\Model\Order` guards; an invalid transition = step failure, not silent corruption.

## Webhook action with response capture

Sync HTTP POST (Guzzle), JSON body rendered from context, HMAC-SHA256 signature header (same convention as the async-events HTTP notifier so receivers verify identically), configurable timeout (default 5s, cap 30s), `capture_as` key storing the parsed JSON response into `context.steps.<key>`.

Subsequent branch conditions can reference the response: `{{ steps.fraud.response.score }} > 80`.

Failure honors `retryable` — 5xx/timeout retries via queue redelivery; 4xx fails the step terminally.

**This action is an SSRF vector and must ship hardened, not hardenable** — the full hardening posture (private-range denial, DNS pinning, redirect re-validation, response caps, trust boundary for captured responses) is specified in [Security §SSRF hardening](10-security.md#ssrf-hardening-the-webhook-action). An optional response JSON Schema per step turns schema mismatch into step failure, keeping garbage out of downstream branches.

## Variable resolution

A **restricted mustache-style resolver** over the context bag: `trigger.*`, `steps.*`, `workflow.*`, `secrets.*`.

- **Not** `Magento\Framework\Filter\Template` — no directive execution, no method calls, dot-path array access only.
- Secrets are config-encrypted values referenced by key, never stored in definitions, redacted in logs ([Security §Secrets](10-security.md#secrets)).
- Interpolation supplies *values*, never *structure*: captured webhook responses are usable in conditions and interpolation but never as action identifiers or attribute codes ([Security §Trust boundary](10-security.md#ssrf-hardening-the-webhook-action)).

## Loop prevention, storms, and circuit breaking

Actions mutate entities; mutations fire events; events trigger workflows. Guards:

- **Chain depth:** executions carry `chain_depth`, propagated when an action's mutation causes a dispatch within the same request via a registry flag on the publisher — exceeding `loop_guard_depth` (default 1) skips dispatch and logs `loop_suppressed`.
- **Debounce, done atomically:** the same `(workflow_id, entity_id)` within N seconds collapses to one execution. A SELECT-then-INSERT check is racy under concurrent consumers — enforce with a unique key on `(workflow_id, entity_id, time_bucket)` and treat duplicate-key as debounced. No lock-service dependency.
- **Circuit breaker:** N consecutive step failures (default 10) or failure rate > X% over a window auto-pauses the workflow (`status = suspended`), fires an admin notification + email digest, and requires explicit re-enable. A misconfigured webhook must not silently burn the retry queue for days.
- **Bulk-operation suppression:** imports and mass-actions firing 100k `product.saved` events will detonate any per-entity engine. Ship a suppression API (`WorkflowSuppression::scope(callable)` + honored `bin/magento` flag + config toggle for known bulk paths like `catalog_product_import`). Phase 2: *aggregate triggers* — "fire once per batch with the matched collection" — turning the storm problem into a feature (e.g., "email me a summary of all products that went out of stock today").
