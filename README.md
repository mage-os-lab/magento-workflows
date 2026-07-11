# Mage-OS Workflow Engine

**Working name:** `MageOS_Workflows` · **Status:** Proposed · **Target:** Magento Open Source / Mage-OS / Adobe Commerce ≥ 2.4.4, PHP 8.1+

A merchant-facing, admin-native **trigger → condition → action** workflow engine for Magento, entirely on-prem, composed from existing Magento primitives.

Merchants build automations like:

> *"When an order is created on US Store, if grand total > $500 and customer group is Wholesale, then: add order comment, wait 1 hour, if still unpaid notify #fraud."*

— in the admin panel, with no code, no SaaS dependency, and full awareness of EAV attributes, scopes, and B2B entities.

## Why native, not n8n?

| Decision | Rationale |
|---|---|
| Build native; do not embed n8n | Licensing (Sustainable Use / embed license), payload-JSON impedance vs. EAV/scopes/B2B, wrong user (ops vs. merchant) |
| [`mageos-async-events`](https://github.com/mage-os/mageos-async-events) is the event bus | Inherits queue transport, quadratic-backoff retry, UUID trace logging, ES/Lucene search, subscription model |
| Conditions extend `Magento\Rule\Model` | Free EAV introspection, merchant-familiar UI widget, battle-tested evaluation |
| Actions = DI-registered pool | Standard Magento pattern; third-party extensible by `di.xml` |
| v1 UI is adminhtml forms, not a canvas | ~20% of the cost of React Flow; AutomateWoo proves the model. Canvas is v2 |
| External connectors via webhook action → iPaaS | Don't compete with 400-connector ecosystems; own the data model instead |

See [Positioning & Scope](docs/01-overview.md) for the full rationale and non-goals.

## Documentation

| Doc | Contents |
|---|---|
| [01 — Overview & Positioning](docs/01-overview.md) | Locked decisions, non-goals, strategy |
| [02 — Package Decomposition](docs/02-packages.md) | Composer package layout and dependencies |
| [03 — Domain Model](docs/03-domain-model.md) | Entities, DDL, versioning semantics |
| [04 — Definition Format](docs/04-definition-format.md) | The definition JSON contract, import/export |
| [05 — Trigger Layer](docs/05-triggers.md) | Event, scheduled, and manual triggers |
| [06 — Condition Engine](docs/06-conditions.md) | Rule-model generalization, two-phase evaluation, delay semantics |
| [07 — Action Framework](docs/07-actions.md) | Action contract, core library, webhook action, variable resolution, guards |
| [08 — Execution Model](docs/08-execution-model.md) | Queue topology, resumption, crash safety, sizing |
| [09 — Scope, ACL & Observability](docs/09-scope-acl-observability.md) | Multi-store semantics, permissions, logging |
| [10 — Security Model](docs/10-security.md) | SSRF hardening, deferred privilege escalation, secrets, PII |
| [11 — Admin UI](docs/11-admin-ui.md) | v1 form UI, v2 canvas, shadow mode, merchant accessibility |
| [12 — B2B Pack](docs/12-b2b.md) | Adobe Commerce B2B triggers/conditions/actions |
| [13 — Delivery Plan](docs/13-delivery-plan.md) | Phases, effort estimates, test strategy |
| [14 — Risks & Open Questions](docs/14-risks.md) | Risk register with mitigations |
| [15 — Operations Guide](docs/15-operations.md) | Consumers, cron, health checks, retention, recovery |
| [16 — Capability Roadmap](docs/16-capability-roadmap.md) | Post-review execution record: waves 1–5 implemented, deferred scope |
| [17 — Use Cases](docs/17-use-cases.md) | 100+ high-level examples of how merchants and agencies use the engine |
| [18 — Known Boundaries](docs/18-limitations.md) | ~60 flows the engine does *not* support (yet), each with the architectural reason |
| [19 — Testing Strategy](docs/19-testing-strategy.md) | Test inventory, current-vs-ideal evaluation, behavior-findings registry |
| [20 — Integration Test Plan](docs/20-integration-test-plan.md) | Magento integration-test lane: harness wiring, suite catalog, phasing |
| [Discovery — Phase 3 & Enhancements](docs/discovery/README.md) | Planning/evaluation docs (canvas, template gallery, dry-run, branching, batch aggregation, fan-out, entity cross-referencing) plus bottom-up [implementation plans](docs/discovery/implementation/README.md) |

The original consolidated architecture document is preserved at [docs/architecture-plan.md](docs/architecture-plan.md).

## Repository layout

```
# Engine + shared infrastructure (entity-agnostic)
src/module-workflows/                 mage-os/workflows              MageOS_Workflows (core engine)
src/module-workflows-admin-ui/        mage-os/workflows-admin-ui     MageOS_WorkflowsAdminUi
src/module-workflows-actions-core/    mage-os/workflows-actions-core MageOS_WorkflowsActionsCore (notify + flow actions)
src/module-workflows-triggers-core/   mage-os/workflows-triggers-core MageOS_WorkflowsTriggersCore (notifier binding + EventPublisher)
src/module-workflows-scheduler/       mage-os/workflows-scheduler    MageOS_WorkflowsScheduler (cron + QueryRunner)
# Domain packs (one per commerce domain — roots, hydrators, relations, triggers, actions)
src/module-workflows-sales/           mage-os/workflows-sales        MageOS_WorkflowsSales
src/module-workflows-customer/        mage-os/workflows-customer     MageOS_WorkflowsCustomer
src/module-workflows-catalog/         mage-os/workflows-catalog      MageOS_WorkflowsCatalog
src/module-workflows-inventory/       mage-os/workflows-inventory    MageOS_WorkflowsInventory
src/module-workflows-review/          mage-os/workflows-review       MageOS_WorkflowsReview
src/module-workflows-newsletter/      mage-os/workflows-newsletter   MageOS_WorkflowsNewsletter
# Metapackage
src/metapackage-workflows-suite/      mage-os/workflows-suite        (engine + infra + all six domain packs)
# Optional extras (opt-in, not in the suite)
src/module-workflows-canvas/          mage-os/workflows-canvas       MageOS_WorkflowsCanvas (optional React Flow viewer + editor)
src/module-workflows-templates/       mage-os/workflows-templates    MageOS_WorkflowsTemplates (bundled gallery content pack)
src/module-workflows-admin-extension/ mage-os/workflows-admin-extension MageOS_WorkflowsAdminExtension (native-grid visibility addon)
src/module-workflows-import-suppression/ mage-os/workflows-import-suppression MageOS_WorkflowsImportSuppression (optional bulk-import suppression)
src/module-workflows-approvals/       mage-os/workflows-approvals    MageOS_WorkflowsApprovals (optional human-decision gate)
spec/                                 Published definition + export JSON Schemas, conformance fixtures
docs/                                 Architecture documentation
```

The engine and the three shared infrastructure packs are entity-agnostic *in fact*: order/customer/product/quote bindings live in the vertical **domain packs** ([domain-pack split](docs/discovery/implementation/08-domain-packs.md), executed July 2026), and [`dev/tools/dependency-honesty-check.php`](dev/tools/dependency-honesty-check.php) enforces honest composer metadata in CI.

The core module ships the domain model (`etc/db_schema.xml`), two-phase condition engine (`Model/Rule/`), graph-walking executor and queue topology (`Model/Engine/`, `Model/Queue/`), variable resolver and secrets (`Model/Variable/`, `Model/Secrets/`), and the `workflow:*` CLI commands. Actions register into `ActionPool` via `di.xml` — see `src/module-workflows-actions-core/etc/di.xml` for the pattern; that *is* the connector SDK.

## Status

**Implemented, pre-alpha.** The full Phase 1–2 surface from the [Delivery Plan](docs/13-delivery-plan.md), plus waves 1–5 of the [Capability Roadmap](docs/16-capability-roadmap.md), is coded: core engine (linear + delays with business-days/store-local-time options + branches + schema-2 `wait` steps), condition pool for order/customer/quote/product with EAV auto-discovery and customer order-history aggregates, 22 core actions including the SSRF-hardened webhook, async-events notifier trigger path, scheduler with abandoned-cart and stock-threshold detection, REST API for workflow CRUD and execution reads, adminhtml UI (grid, form with JSON definition editor, execution logs, ACL), import/export/run/stats CLI, loop guards, circuit breaker, shadow mode. The follow-on discovery-track build ([docs/discovery/](docs/discovery/README.md)) added, behind default-off flags and optional modules: multi-way `switch` branching with save-time graph validation (definition schema 3), entity cross-referencing (relation registry), a side-effect-free dry-run (CLI/REST/admin trace panel), trigger-level fan-out, batch aggregation, the template gallery (14 bundled recipes), the optional React Flow canvas, and the native-grid visibility addon. The bundled entity bindings have since been reorganized into six vertical domain packs (sales, customer, catalog, inventory, review, newsletter) plus a `workflows-suite` metapackage, leaving the engine and the shared trigger/action/scheduler packs entity-agnostic ([domain-pack split](docs/discovery/implementation/08-domain-packs.md)). A standalone test runner exercises `Test/Unit` across the module suite (via a Magento shim layer, `dev/tests/shims/`), plus the canvas's TypeScript tests, with CI lint + units on PHP 8.1–8.4.

Not yet done: integration against a live Magento install (the code has not been compiled by `setup:di:compile` or exercised end-to-end — this remains the gate before any GA claim), full unit/integration coverage (the runner exists; suites are still growing), the rule-widget condition editor tab and metadata-driven dynamicRows action form (v1 ships a JSON editor fallback), the B2B pack, and a signed remote template feed. Class-name fidelity against `mageos-async-events` internals has now been audited against the real package source (`mage-os/mageos-async-events` @ `b249976`) and holds: the `NotifierFactory` `notifierClasses` object-pool keyed by subscription `metadata`, the `NotifierInterface::notify(AsyncEventInterface, CloudEventImmutable): ResultInterface` contract (we narrow the return to `NotifierResult`), the `async_events.xsd` node shape, and the `AsyncEventRepositoryInterface::save(AsyncEventInterface, bool $checkResources)` signature plus the `AsyncEventInterface` accessors the subscription lifecycle drives are all confirmed (citations in `src/module-workflows-triggers-core/etc/di.xml` and class docblocks; asserted at runtime by `AsyncEventsFidelityTest`). Two items are flagged rather than confirmed: `EventPublisher` publishes through the async-events *delivery* dispatcher (`EventDispatcher::dispatch`) instead of the queue publisher (`AsyncEventPublisherInterface::publish`) — a synchronous-vs-async / payload-fidelity trade-off; and upstream `AsyncEventRepository::save()` ignores `event_name` changes on an existing subscription, so re-pointing a bound workflow's trigger needs a subscription recreate. Both are documented in `docs/14-risks.md`. End-to-end execution against a live Magento install (compiled by `setup:di:compile`) remains the outstanding gate before any GA claim.
