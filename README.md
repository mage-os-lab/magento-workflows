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

The original consolidated architecture document is preserved at [docs/architecture-plan.md](docs/architecture-plan.md).

## Repository layout

```
src/module-workflows/               mage-os/workflows            MageOS_Workflows (core engine)
src/module-workflows-admin-ui/      mage-os/workflows-admin-ui   MageOS_WorkflowsAdminUi
src/module-workflows-actions-core/  mage-os/workflows-actions-core  MageOS_WorkflowsActionsCore
src/module-workflows-triggers-core/ mage-os/workflows-triggers-core MageOS_WorkflowsTriggersCore
src/module-workflows-scheduler/     mage-os/workflows-scheduler  MageOS_WorkflowsScheduler
spec/                               Published definition + export JSON Schemas, conformance fixtures
docs/                               Architecture documentation
```

The core module ships the domain model (`etc/db_schema.xml`), two-phase condition engine (`Model/Rule/`), graph-walking executor and queue topology (`Model/Engine/`, `Model/Queue/`), variable resolver and secrets (`Model/Variable/`, `Model/Secrets/`), and the `workflow:*` CLI commands. Actions register into `ActionPool` via `di.xml` — see `src/module-workflows-actions-core/etc/di.xml` for the pattern; that *is* the connector SDK.

## Status

**Implemented, pre-alpha.** The full Phase 1–2 surface from the [Delivery Plan](docs/13-delivery-plan.md) is coded: core engine (linear + delays + branches), condition pool for order/customer/product with EAV auto-discovery, 17 core actions including the SSRF-hardened webhook, async-events notifier trigger path, scheduler with abandoned-cart detection, adminhtml UI (grid, form with JSON definition editor, execution logs, ACL), import/export/run/stats CLI, loop guards, circuit breaker, shadow mode.

Not yet done: integration against a live Magento install (the code has not been compiled by `setup:di:compile` or exercised end-to-end), unit/integration test suites, the rule-widget condition editor tab and metadata-driven dynamicRows action form (v1 ships a JSON editor fallback), the B2B pack, and the v2 canvas. Class-name fidelity against `mageos-async-events` internals needs verification on a real install — assumptions are documented in `src/module-workflows-triggers-core/etc/di.xml` and class docblocks.
