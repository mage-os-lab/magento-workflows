# Mage-OS Workflow Engine — Architecture & Implementation Plan

**Working name:** `MageOS_Workflows` · **Status:** Proposed (original design doc; much of it since implemented — pending live-install verification) · **Target:** Magento Open Source / Mage-OS / Adobe Commerce ≥ 2.4.4, PHP 8.1+

> **Note.** This is the original consolidated architecture proposal, preserved as written. The as-built status lives in the numbered docs: [13 — Delivery Plan](13-delivery-plan.md) and [16 — Capability Roadmap](16-capability-roadmap.md) for what's coded, and [docs/discovery/](discovery/README.md) for the follow-on build (canvas, template gallery, dry-run, branching, cross-referencing, fan-out, batch aggregation). Passages below that describe those as "v2 / Phase 2" future work are flagged inline where they'd otherwise mislead.

---

## 1. Positioning & Locked Decisions

A merchant-facing, admin-native trigger → condition → action workflow engine, entirely on-prem, composed from existing Magento primitives. Decisions carried in from prior analysis, restated as constraints:

| Decision | Rationale |
|---|---|
| Build native; do not embed n8n | Licensing (Sustainable Use / embed license), payload-JSON impedance vs. EAV/scopes/B2B, wrong user (ops vs. merchant) |
| `mageos-async-events` is the event bus | Inherits queue transport, quadratic-backoff retry, UUID trace logging, ES/Lucene search, subscription model |
| Conditions extend `Magento\Rule\Model` | Free EAV introspection, merchant-familiar UI widget, battle-tested evaluation |
| Actions = DI-registered pool | Standard Magento pattern (payment methods, totals collectors); third-party extensible by `di.xml` |
| v1 UI is adminhtml forms, not a canvas | ~20% of the cost of React Flow; AutomateWoo proves the model. Canvas is v2 (since implemented as the optional `workflows-canvas` module — pending live-install verification) |
| External connectors via webhook action → iPaaS | Don't compete with 400-connector ecosystems; own the data model instead |

Non-goals for v1: storefront-facing anything, Adobe I/O Events interop, loops/iterators over collections, approval-chain UI (B2B native approvals remain in Commerce core).

---

## 2. Package Decomposition

Composer packages, mirroring the async-events family layout:

```
mage-os/workflows                  Core engine: domain model, evaluation, execution, queues
mage-os/workflows-admin-ui         Grid + form UI, logs UI, ACL
mage-os/workflows-actions-core     Bundled action library (order, customer, catalog, notify)
mage-os/workflows-triggers-core    Trigger metadata over mageos-common-async-events + gap-fill events
mage-os/workflows-scheduler        Cron/query-based triggers
mage-os/workflows-b2b              B2B triggers/conditions/actions (suggest: Adobe Commerce only)
mage-os/workflows-canvas           v2 React Flow builder (optional, reads same definition)
```

Hard dependency of core: `mage-os/mageos-async-events`. Soft (suggest): `mageos-async-events-admin-ui` for raw subscription debugging.

---

## 3. Domain Model

```
Workflow            1 ──── n  WorkflowStep          (definition, versioned)
Workflow            1 ──── n  WorkflowExecution     (runtime instance per trigger firing)
WorkflowExecution   1 ──── n  WorkflowExecutionStep (per-step runtime state)
```

A **Workflow** owns: identity, scope (website/store IDs), trigger binding (async event name OR schedule), a root condition tree (serialized rule conditions), and an ordered step list. A **step** is `{type: action|delay|branch|stop, config: json, on_true/on_false: step refs}`. Linear chains are the degenerate case of the step graph — the schema supports branching from day one even if v1 UI only exposes linear + delay.

### 3.1 DDL sketch

```sql
CREATE TABLE mageos_workflow (
  workflow_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(255) NOT NULL,
  status           TINYINT NOT NULL DEFAULT 0,          -- 0 disabled, 1 enabled
  trigger_type     VARCHAR(32) NOT NULL,                -- event | schedule | manual
  trigger_ref      VARCHAR(255) NOT NULL,               -- async event name | cron expr
  entity_type      VARCHAR(64) NOT NULL,                -- sales_order, customer, catalog_product...
  conditions_serialized MEDIUMTEXT,                      -- rule condition tree (same format as salesrule)
  definition       JSON NOT NULL,                        -- step graph
  version          INT UNSIGNED NOT NULL DEFAULT 1,
  loop_guard_depth TINYINT NOT NULL DEFAULT 1,
  created_at / updated_at TIMESTAMP
);
CREATE TABLE mageos_workflow_website (workflow_id, website_id, PK(workflow_id, website_id));

CREATE TABLE mageos_workflow_execution (
  execution_id     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid             CHAR(36) NOT NULL,                    -- correlates with async-events trace UUID
  workflow_id      INT UNSIGNED NOT NULL,
  workflow_version INT UNSIGNED NOT NULL,
  entity_id        INT UNSIGNED NOT NULL,
  store_id         SMALLINT UNSIGNED NOT NULL,
  status           VARCHAR(16) NOT NULL,                 -- pending|running|waiting|complete|failed|cancelled
  context          JSON,                                 -- variable bag (trigger snapshot + step outputs)
  chain_depth      TINYINT NOT NULL DEFAULT 0,           -- loop guard
  triggered_at / completed_at TIMESTAMP,
  INDEX (workflow_id, status), INDEX (uuid), INDEX (status, triggered_at)
);

CREATE TABLE mageos_workflow_execution_step (
  step_execution_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  execution_id      BIGINT UNSIGNED NOT NULL,
  step_key          VARCHAR(64) NOT NULL,
  status            VARCHAR(16) NOT NULL,                -- pending|running|complete|failed|skipped
  result            JSON,                                -- action output (webhook response, coupon code, ...)
  error             TEXT,
  resume_at         TIMESTAMP NULL,                      -- for delay steps
  started_at / finished_at TIMESTAMP,
  INDEX (execution_id), INDEX (status, resume_at)        -- sweeper index
);
```

Definitions are **versioned on save**: bump `version`, append the prior definition to a `mageos_workflow_revision` table (feeds change-history UI, §9.1), and pin executions to the version they started under. Executions **store the full definition snapshot** in their row — not optional; a mid-flight execution with a 3-day delay must resume into the exact graph it started in, with zero joins and no dependency on revision retention policy.

### 3.2 Definition format (the contract everything shares)

The `definition` JSON is the single artifact the form UI, the future canvas, import/export, and the executor all read. Example:

```json
{
  "schema": 4,
  "steps": {
    "s1": {"type": "action", "action": "order.add_comment",
           "config": {"comment": "High-value order flagged ({{ trigger.grand_total }})"},
           "next": "s2"},
    "s2": {"type": "delay", "config": {"duration": "PT1H"}, "next": "s3"},
    "s3": {"type": "branch",
           "conditions_serialized": "...",
           "revalidate_entity": true,
           "on_true": "s4", "on_false": null},
    "s4": {"type": "action", "action": "notify.webhook",
           "config": {"url": "https://hooks.example/fraud", "capture_as": "fraud",
                      "timeout": 5, "sign_with": "{{ secrets.fraud_hmac }}"},
           "next": null}
  },
  "entry": "s1"
}
```

Agency workflow-as-code: `bin/magento workflow:export <id>` / `workflow:import <file>` emitting this JSON plus metadata; importable from a data patch. Git-versionable, CI-deployable — this matters more to your actual buyers (agencies) than the canvas does.

---

## 4. Trigger Layer

### 4.1 Event triggers — ride the async-events notifier seam

`mageos-async-events` delivers events to destinations via **notifiers** resolved from subscription `metadata` (`http`, `event_bridge`, ...). We add one:

```php
class WorkflowNotifier implements NotifierInterface   // metadata: "workflow"
{
    public function notify(AsyncEventDisplayInterface $event, array $data): ResultInterface
    {
        // $data = resolved service-class output (already the hydrated DTO, e.g. OrderInterface as array)
        return $this->dispatcher->dispatch(
            (int) $event->getSubscriptionData('workflow_id'), $data
        );
    }
}
```

Enabling a workflow programmatically creates a hidden async-event subscription (`event_name` = trigger ref, `metadata` = `workflow`, recipient = workflow ID). Disabling deactivates it. Consequences, all favorable:

- The engine inherits async-events' **queue transport, quadratic backoff retry, dead-lettering, UUID tracing, replay, and ES-indexed searchability** with zero code. A failed workflow dispatch is just a failed delivery — replayable from the existing admin grid.
- The **payload arrives pre-hydrated** by the event's declared service class (`OrderRepositoryInterface::get` etc.) — the same snapshot an HTTP subscriber would get. This is the trigger snapshot placed into execution `context.trigger`.
- Trigger coverage = `async_events.xml` definitions. `mageos-common-async-events` covers customer/order/invoice/shipment basics; `workflows-triggers-core` fills gaps (order status change w/ from→to in payload, stock threshold crossed, review submitted, cart abandoned¹, customer group changed, credit memo, RMA if present).

¹ Cart abandonment isn't an event — it's a query ("quote updated > N hours ago, no order"). It lives in the scheduler (4.2), which then *publishes* a `quote.abandoned` async event, keeping one dispatch path.

Trigger metadata for the UI (labels, entity type, payload hints) is declared in `workflow_triggers.xml`:

```xml
<trigger event="sales.order.created" entity="sales_order"
         label="Order Created" group="Sales"
         resolver="MageOS\Workflows\Model\Resolver\OrderResolver"/>
```

### 4.2 Scheduled triggers (`workflows-scheduler`)

A schedule-type workflow = cron expression + entity type + the same rule-condition tree used as a **query**. Key trick: `Magento\Rule\Model\Condition\*` supports `collectValidatedAttributes()` / SQL generation in the CatalogRule lineage — but that path is only reliable for products. Pragmatic approach: map the condition tree to `SearchCriteria` where operators translate cleanly (scalar/set/date on selectable attributes), and fall back to load-and-filter in batches of 500 where they don't. Each matching entity spawns a normal execution through the same dispatcher. Guard rails: per-run match cap (default 5k), and a `last_run_watermark` so "orders older than 72h" doesn't reprocess the same rows (dedupe on `(workflow_id, entity_id)` within a configurable window).

### 4.3 Manual triggers

Admin mass-action on order/customer/product grids ("Run workflow…") plus `bin/magento workflow:run <id> --entity-id=…`. Spawns a standard execution, `trigger_type=manual` recorded. This is also your test harness during development and the merchant's test harness after.

---

## 5. Condition Engine

### 5.1 Generalizing `Magento\Rule\Model`

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

Per-entity condition classes follow the `AbstractCondition` contract: `loadAttributeOptions()` introspects EAV metadata (attribute repository) + selected flat columns; `validate(AbstractModel $model)` evaluates. **Custom EAV attributes appear automatically** — the differentiator vs. every payload-JSON engine, and it costs nothing because CatalogRule's Product condition already demonstrates the pattern.

Cross-entity traversal via child combines: an Order combine exposes a "Customer" subtree (hydrates via `order.customer_id → CustomerRepository`) and an "Items" subtree with ANY/ALL semantics over `OrderItemInterface` (pattern exists in `SalesRule\Model\Rule\Condition\Product\Found`).

### 5.2 Two-phase evaluation (the EAV-at-scale answer)

The identified primary risk is hydration cost per event. Mitigation is structural, not tuning:

**Phase 1 — snapshot pass (cheap, no DB):** evaluate against the trigger payload wrapped in a `DataObject`. At workflow save time, statically analyze the condition tree: every attribute it references is classified `in_snapshot` or `needs_hydration` (the trigger's declared service class defines the snapshot shape). If all attributes are in-snapshot — the common case: totals, status, group id, SKUs — evaluation completes with **zero queries**.

**Phase 2 — hydration pass (lazy, scoped):** only if the tree references out-of-snapshot attributes, hydrate exactly the entities needed via the trigger's `resolver` (repository-backed, per-execution identity map). Short-circuit combinator evaluation ordering: in-snapshot conditions first, hydration-requiring conditions last, so an early `false` under ALL never touches the DB.

**Workflow index:** active workflows keyed by event name held in a config-style cache (invalidated on save), so the dispatcher's "any workflows for this event?" check is an array lookup, not a query. A store firing 50k events/day with three workflows on `sales.order.created` does three snapshot evaluations per order and typically zero hydrations.

### 5.3 Delay semantics

After a delay, the world has moved. Each post-delay branch/step carries `revalidate_entity: bool`. If true, re-hydrate fresh and re-evaluate (AutomateWoo's "validate before send" — correct default for "email 1h after abandonment *if still abandoned*"). If false, evaluate against the frozen trigger snapshot (correct for "log what it looked like at order time"). Exposed as a checkbox; defaults to true on branches following delays.

---

## 6. Action Framework

### 6.1 Contract

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

Registered via `di.xml` type-array into `ActionPool`. Third-party module = one class + one `di.xml` entry + optional `workflow_triggers.xml`. That *is* the connector SDK — no new plumbing concept required.

### 6.2 Core library (v1, `workflows-actions-core`)

| Group | Actions |
|---|---|
| Sales | add order comment · change order status² · hold/unhold · create invoice (capture online/offline) · cancel order |
| Customer | assign group · set custom attribute · subscribe/unsubscribe newsletter · add to segment (Commerce) |
| Catalog | set attribute value (scoped) · enable/disable product · set stock status/qty (MSI source-aware) |
| Marketing | generate coupon from cart price rule · apply customer tag attribute |
| Notify | send email (transactional template + context vars) · **call webhook** · admin notification (inbox) |
| Flow | delay · stop · set context variable |

² Status transitions validated against the state machine — reuse `Magento\Sales\Model\Order` guards; invalid transition = step failure, not silent corruption.

### 6.3 Webhook action with response capture

Sync HTTP POST (Guzzle), JSON body rendered from context, HMAC-SHA256 signature header (same convention as async-events HTTP notifier so receivers verify identically), configurable timeout (default 5s, cap 30s), `capture_as` key storing parsed JSON response into `context.steps.<key>`. Subsequent branch conditions reference it: `{{ steps.fraud.response.score }} > 80`. Failure honors `retryable` — 5xx/timeout retry via queue redelivery, 4xx fails the step terminally.

**This action is an SSRF vector and must ship hardened, not hardenable:**

- HTTPS only by default (HTTP behind a config flag with warning). Resolve DNS *then* connect to the resolved IP (defeats rebinding); reject private/link-local/loopback ranges (RFC1918, 169.254.0.0/16, ::1, metadata endpoints) unless the host is on an explicit admin-configured allowlist — same posture Shopify Flow takes.
- Deny redirects across the private-range boundary (Guzzle `on_redirect` re-validation).
- Response caps: 256KB body, JSON depth ≤ 10, parse failures capture `{parse_error: true}` rather than raw bytes.
- **Trust boundary is explicit:** captured responses are attacker-influenceable data. They are usable in branch conditions and variable interpolation but never as action *identifiers* (no `{{ steps.x.response.action_code }}` resolving which action runs), never in attribute *codes*, and always type-coerced at the condition comparator. Document this in the SDK: action configs interpolate values, never structure.
- Optional response JSON Schema per step — mismatch = step failure, keeping garbage out of downstream branches.

### 6.4 Variable resolution

Restricted mustache-style resolver over the context bag (`trigger.*`, `steps.*`, `workflow.*`, `secrets.*`). **Not** `Magento\Framework\Filter\Template` — no directive execution, no method calls, dot-path array access only. Secrets are config-encrypted values referenced by key, never stored in definitions, redacted in logs.

### 6.5 Loop prevention, storms, and circuit breaking

Actions mutate entities; mutations fire events; events trigger workflows. Guards:

- **Chain depth:** executions carry `chain_depth`, propagated when an action's mutation causes a dispatch within the same request via a registry flag on the publisher — exceeding `loop_guard_depth` (default 1) skips dispatch and logs `loop_suppressed`.
- **Debounce, done atomically:** same `(workflow_id, entity_id)` within N seconds collapses to one execution. A SELECT-then-INSERT check is racy under concurrent consumers — enforce with a unique key on `(workflow_id, entity_id, time_bucket)` and treat duplicate-key as debounced. No lock service dependency.
- **Circuit breaker:** N consecutive step failures (default 10) or failure rate > X% over a window auto-pauses the workflow (`status = suspended`), fires an admin notification + email digest, and requires explicit re-enable. A misconfigured webhook must not silently burn the retry queue for days.
- **Bulk-operation suppression:** imports and mass-actions firing 100k `product.saved` events will detonate any per-entity engine. Ship a suppression API (`WorkflowSuppression::scope(callable)` + honored `bin/magento` flag + config toggle for known bulk paths like `catalog_product_import`). *Aggregate triggers* — "fire once per batch with the matched collection" — turning the storm problem into a feature (e.g., "email me a summary of all products that went out of stock today"); **since implemented** (`Model/Aggregation/*`, both collected and window modes — pending live-install verification).

---

## 7. Execution Model & Queue Topology

```
async-events event.trigger.consumer ──> WorkflowNotifier ──> workflow.dispatch (topic)
                                                                   │
                                          workflow.execute.consumer (N instances, horizontal)
                                                                   │
                     ┌─ evaluate root conditions (two-phase) ── false ─> log, complete(skipped)
                     └─ true: walk step graph
                            action  -> execute inline, persist step result
                            branch  -> evaluate, follow edge
                            delay   -> persist state=waiting, resume_at; RELEASE message
                            stop    -> complete

Resumption: RabbitMQ -> per-delay message via DLX+TTL parking queue (same pattern
async-events uses for retry backoff). DB queue -> cron sweeper (1 min) on
INDEX(status, resume_at). Both paths converge on workflow.resume consumer.
```

Properties worth stating: executions are **resumable and crash-safe** (state is in DB before any side effect; consumer death mid-step = redelivery; steps are marked `running` with a claim timestamp so a sweeper can fail-or-retry zombies). Actions should be idempotent where cheap (add-comment dedupes on execution UUID); where not, at-least-once is documented per action. Consumers scale horizontally and off-box exactly like async-events consumers — same ops story your clients already run.

Sizing reality: a single `workflow.execute` consumer comfortably handles hundreds of executions/min when Phase-1 evaluation dominates; the ceiling is action side effects (order save ≈ 100–300ms), which parallelize across consumers.

---

## 8. Scope, ACL, Observability

**Scope:** workflows bind to website IDs (store-view granularity for conditions via the standard `store_id IN` condition). The dispatcher resolves the entity's store → website and skips non-matching workflows before evaluation. Global entities (customer on shared accounts, product) evaluate against the event's emitting scope, falling back to workflow scope — document this explicitly; it's the one genuinely fiddly semantic in multi-store.

**ACL:** resources for view/manage/enable workflows + per-action-group authoring gates (`MageOS_Workflows::action_sales`, etc.). A merchant admin who can't cancel orders can't author a cancel-order step. Executions run under a system context (`AppArea` = crontab-like), with the authoring admin recorded on the definition for audit. Definition saves are logged (plays well with admin-activity modules).

**Security hardening (adversarial pass):**

- **Deferred privilege escalation is the core threat model.** Workflows are stored intent executed later with system privileges — the same class of problem as cron-injected code. Mitigations beyond authoring ACL: attribute allowlists per action (the set-customer-attribute action refuses system attributes — `password_hash`, `is_active`, ACL-relevant fields — via a deny-by-default list of attribute codes shipped in config); scope-check at *execution* time, not just authoring time (a workflow scoped to website 1 whose author lost website-1 access gets suspended, not silently escalated).
- **Secrets:** dedicated ACL resource for secret CRUD, values encrypted via `EncryptorInterface`, write-only in the UI (never re-displayed), redacted in execution logs and ES documents by key prefix. Definitions reference secrets by name only — exports never contain values.
- **Import is untrusted input:** validate against the published JSON Schema, reject unknown action codes, and **re-authorize against the importing admin's ACL** — an imported definition containing actions the importer can't author fails loudly. Same check on programmatic creation via data patches (documented: patches run as system, agencies own that risk).
- **Subscription ownership:** the hidden async-events subscriptions carry an `owner=workflow:<id>` marker; the async-events admin UI and REST API refuse mutation of owned subscriptions, preventing an out-of-band edit from redirecting a workflow's event stream.
- **PII containment:** execution `context` holds entity snapshots (names, emails, addresses). Three controls: TTL pruning cron (default 90 days, configurable to hours), field-level redaction config applied before ES indexing (index metadata + IDs by default, full payload opt-in), and a hook into `CustomerRepository::delete` / GDPR erasure flows that scrubs matching execution contexts. Decide defaults *before* GA — retrofitting redaction into an existing ES index is miserable.
- **Manual mass-run:** confirmation modal with matched-count preview, per-run cap (default 1k, config), dedicated ACL resource, and full audit log entry (admin, workflow, entity ID list hash).

**Observability:** execution grid (filterable by workflow/status/entity), drill-down timeline per execution showing each step's status/duration/result/error, linked to the async-events trace UUID so the full path event→delivery→execution is one correlated view. Reuse the ES indexing hook to index execution records for the same Lucene querying. Emit `workflow_execution_complete/failed` as ordinary Magento events for monitoring integrations. Counters via a `workflow:stats` CLI for quick prod triage.

---

## 9. Admin UI

**v1 (adminhtml, ships with MVP):** grid + tabbed form. General (name, status, scope, loop guard) · Trigger (grouped select from trigger metadata; schedule builder for cron type) · Conditions (the stock rule widget — ugly, familiar, free) · Actions (`dynamicRows`; each row's fieldset rendered from `getConfigForm()` metadata; delay and stop are just row types; v1 exposes linear + delays + a single optional post-delay branch) · Logs (embedded execution grid). Plus grid mass-actions and manual-run modal.

**v2 (`workflows-canvas`) — since implemented (pending live-install verification):** React Flow reading/writing the same definition JSON, shipped as a read-only viewer (execution + dry-run overlays) plus a full drag-and-drop editor. Node palette from trigger/action metadata endpoints. The definition format is the API boundary — canvas is purely presentational, no engine changes. Also shipped: the template library (the `workflows-templates` content pack + gallery UI, installed via the existing import pipeline) and dry-run mode (`DryRunService` — actions render their would-be effect into step results without side effects via the realized optional `SimulateableActionInterface`).

**Shadow mode (v1, nearly free):** enable a workflow in `shadow` status — conditions evaluate on live traffic, actions log their would-be effect via `simulate()`, nothing mutates. This is the single highest-leverage confidence feature for merchants ("run it for a week, look at what it *would have* done") and it costs one enum value plus the simulate path dry-run already needs. Ship it before dry-run; it's the same machinery with a status flag.

## 9.1 Merchant Accessibility & Openness

The engine is only "merchant-facing" if a non-developer can trust and understand it:

- **Plain-language rendering:** auto-generate a sentence from any definition — *"When an order is created on US Store, if grand total > $500 and customer group is Wholesale, then: add order comment, wait 1 hour, if still unpaid notify #fraud."* Rendered on the grid, the form header, the confirmation modal, and change-history entries. Cheap (walk the graph, template per node type), enormous comprehension payoff, and doubles later as the target/source representation for AI-assisted authoring.
- **Change history with diffs:** definitions are versioned already (§3.1) — expose it. Who changed what, when, rendered as plain-language before/after. Merchants audit; agencies debug "it worked last month."
- **Failure UX:** step errors surfaced as merchant-readable messages with remediation hints (`"The webhook endpoint took longer than 5s"` not a Guzzle trace; trace behind a "technical details" expander). Daily failure digest email per store, opt-out.
- **a11y & i18n:** all new UI keyboard-navigable and WCAG 2.1 AA (the legacy rule widget won't be — wrap it, don't inherit its sins into new components); every trigger/action/condition label runs through `__()` from day one so the ecosystem can ship label packs.
- **Open spec as strategy:** publish and semver the definition JSON Schema, the `workflow_triggers.xml` XSD, and a conformance fixture set. Third parties (canvas alternatives, CI linters, AI tools, competing UIs) building on the format grows the moat rather than eroding it — the format wins, and the reference engine is the default implementation. Core engine under OSL-3.0/MIT via Mage-OS maximizes install base; commercial layer is the B2B pack, template gallery, and support.

---

## 10. B2B Pack (`workflows-b2b`, Adobe Commerce)

Triggers: negotiable quote created/updated/expired, PO submitted/approved/rejected, company created, company credit changed, requisition list updated — B2B modules dispatch plain events for all of these; the pack wraps them as async events + trigger metadata. Conditions: company hierarchy, buyer role, credit balance, quote totals. Actions: assign quote sales rep, adjust credit limit, approve/reject PO under threshold, notify company admin. Nothing here needs new engine capability — it's content on the pools, which validates the SDK story. This pack is the commercial wedge: nobody else has it, and the buyers (B2B merchants on Commerce) have the budget.

---

## 11. Delivery Plan

| Phase | Scope | Effort (senior M2 eng) |
|---|---|---|
| 0 — Spike | WorkflowNotifier E2E: subscription→dispatch→hardcoded condition→add-comment action. Validates the notifier seam and two-phase evaluation on a real payload | 2–3 wks |
| 1 — MVP | Core engine (linear + delays), rule-model condition pool for order/customer/product, 12–15 core actions, form UI, execution logging, import/export CLI, loop guards, docs | 3.5–4.5 eng-months |
| 2 — Depth | Scheduler + abandoned-cart trigger, branching in UI, webhook response capture, revalidation semantics, B2B pack, ES indexing of executions | 2–3 eng-months |
| 3 — Polish | Canvas, template gallery, dry-run, connectors program | 2–3 eng-months |

Phase 1 is a shippable, sellable product. Test strategy: engine is highly unit-testable (condition eval, graph walker, variable resolver are pure-ish); integration tests per action against the standard M2 integration framework; one E2E per trigger via the manual-run CLI. Reuse async-events' CI shape (it already runs integration + API-functional suites in GH Actions).

## 12. Risks & Open Questions

| Risk | Mitigation |
|---|---|
| EAV hydration at scale | Two-phase evaluation (§5.2); static attribute classification at save time; workflow index cache; batch caps on scheduler |
| DB-queue installs (no RabbitMQ) lack delayed delivery | Cron sweeper path (§7); document RabbitMQ as recommended, required for sub-minute delay precision — identical stance to async-events' retry backoff |
| Event loops from action side effects | Chain-depth guard + debounce (§6.5) |
| `Magento\Rule` widget UX debt | Accept for v1; canvas replaces the *layout*, rule widget remains the condition editor even in v2 (it's the only EAV-aware editor that exists) |
| Adobe pushes App Builder as the answer | Different market: on-prem/OS merchants and agencies who won't take a SaaS dependency; engine also runs on Adobe Commerce PaaS untouched |
| Order-status action vs. custom order-state extensions | Validate transitions via core guards; document that exotic state machines need custom actions |
| SSRF via webhook action | Hardened by default (§6.3): private-range denial, DNS-pin, redirect re-validation, response caps |
| Deferred privilege escalation (workflows run as system) | Authoring ACL + attribute denylists + execution-time scope re-check + import re-authorization (§8) |
| Event storm from imports/mass-actions | Suppression API + config-flagged bulk paths; aggregate triggers since implemented (pending live-install verification) (§6.5) |
| Runaway/misconfigured workflow | Circuit breaker auto-suspend + digest notification (§6.5) |
| Duplicate side effects on at-least-once redelivery | Step claim timestamps + per-step dedupe key (execution UUID + step key) checked by non-idempotent actions (email send logs the key before SMTP) |
| Entity deleted during a delay | Resume path treats missing-entity as `skipped` with explicit log status, never as error retry |
| Timezone ambiguity (delays, schedules) | Delays are absolute durations (UTC arithmetic); schedules evaluate in *store* timezone with the store recorded on the execution — document loudly, it's the #1 support ticket generator in every scheduler ever shipped |
| PII sprawl into ES / retained contexts | Redaction-by-default indexing, TTL pruning, GDPR erasure hook (§8) — GA blockers, not fast-follows |
| Open: multi-source inventory semantics for stock actions | `product.set_stock` now takes an optional `source_code` (MSI-aware via `SourceItemsSave` when MSI is present — pending live-install verification); full MSI-aware config (per-stock salability, multi-source strategies) remains open |

---

*Companion next steps: XSD for `workflow_triggers.xml`, JSON Schema for the definition format, and the Phase-0 spike ticket breakdown.*
