# Mage-OS Workflow Engine

[![CI](https://github.com/mage-os-lab/magento-workflows/actions/workflows/lint.yml/badge.svg)](https://github.com/mage-os-lab/magento-workflows/actions/workflows/lint.yml)
**Pre-alpha · 0.1.x-dev** · OSL-3.0

A merchant-facing **trigger → condition → action** workflow engine for Magento, built entirely on native Magento primitives. No SaaS dependency, no external runtime — just Composer packages that plug into the admin panel.

Merchants build automations like:

> *"When an order is placed on US Store, if grand total > $500 and customer group is Wholesale, then: add an order comment, wait 1 hour, if still unpaid notify the fraud team."*

— in the admin, with no code, full EAV awareness, and multi-store scope support.

> [!WARNING]
> This is an early development release. The engine compiles and runs, but APIs, database schemas, and the definition JSON format may change without notice between 0.x releases. Do not use in production.

## Requirements

- **Magento Open Source / Mage-OS** ≥ 2.4.4 (or Adobe Commerce)
- **PHP** 8.1, 8.2, 8.3, 8.4, or 8.5
- [`mage-os/mageos-async-events`](https://github.com/mage-os/mageos-async-events) ^4.0

## Installation

```bash
# Everything — engine + admin UI + all domain packs
composer require mage-os/workflows-suite:dev-main

# Or just the engine and the packs you need
composer require mage-os/workflows:dev-main \
                 mage-os/workflows-admin-ui:dev-main \
                 mage-os/workflows-actions-core:dev-main \
                 mage-os/workflows-triggers-core:dev-main \
                 mage-os/workflows-sales:dev-main
```

Then:

```bash
bin/magento module:enable MageOS_Workflows MageOS_WorkflowsAdminUi \
    MageOS_WorkflowsActionsCore MageOS_WorkflowsTriggersCore MageOS_WorkflowsSales
bin/magento setup:upgrade
bin/magento setup:di:compile
```

## What's included

The engine ships as a set of Composer packages you can mix and match:

**Core** (entity-agnostic):

| Package | Module | What it does |
|---|---|---|
| `mage-os/workflows` | `MageOS_Workflows` | Domain model, condition engine, graph-walking executor, queue topology, variable resolver, secrets vault, CLI |
| `mage-os/workflows-admin-ui` | `MageOS_WorkflowsAdminUi` | Admin grid, form with JSON definition editor, execution log viewer, ACL |
| `mage-os/workflows-actions-core` | `MageOS_WorkflowsActionsCore` | 22 built-in actions: email, order status, customer group, webhook (SSRF-hardened), flow control, and more |
| `mage-os/workflows-triggers-core` | `MageOS_WorkflowsTriggersCore` | Async-events notifier binding and EventPublisher |
| `mage-os/workflows-scheduler` | `MageOS_WorkflowsScheduler` | Cron-triggered workflows, abandoned-cart detection, stock-threshold queries |

**Domain packs** (entity bindings — triggers, conditions, hydrators, relation roots):

| Package | Covers |
|---|---|
| `mage-os/workflows-sales` | Orders, invoices, shipments, credit memos, quotes |
| `mage-os/workflows-customer` | Customers, addresses, order-history aggregates |
| `mage-os/workflows-catalog` | Products, categories, EAV attribute auto-discovery |
| `mage-os/workflows-inventory` | Stock / CatalogInventory |
| `mage-os/workflows-review` | Product reviews |
| `mage-os/workflows-newsletter` | Newsletter subscriptions |
| `mage-os/workflows-wishlist` | Wishlists |

**Optional extras** (not in the suite metapackage — require separately):

| Package | What it does |
|---|---|
| `mage-os/workflows-canvas` | React Flow visual workflow editor/viewer |
| `mage-os/workflows-templates` | Bundled template gallery (14 ready-made recipes) |
| `mage-os/workflows-admin-extension` | Native-grid visibility strips on order/customer/product grids |
| `mage-os/workflows-approvals` | Human-decision approval gate for workflow steps |
| `mage-os/workflows-import-suppression` | Suppresses workflow dispatch during bulk CSV imports |

## CLI

```
bin/magento workflow:run           # Execute a workflow manually
bin/magento workflow:import        # Import workflow definition JSON
bin/magento workflow:export        # Export workflow definition JSON
bin/magento workflow:stats         # Execution statistics
bin/magento workflow:health        # Queue and engine health check
bin/magento workflow:secret:set    # Store an encrypted secret for use in actions
bin/magento workflow:secret:list   # List stored secrets
bin/magento workflow:secret:delete # Remove a secret
bin/magento workflow:template:list    # List available templates
bin/magento workflow:template:install # Install a template as a new workflow
```

## How it works

1. **Triggers** fire when something happens — an order is placed, a cron schedule hits, a manual CLI run.
2. **Conditions** evaluate against the entity using Magento's `Rule\Model` — full EAV introspection, combinable with AND/OR/NOT.
3. **Actions** execute in a step graph — linear, branching, or with delays (business-days and store-timezone aware). The executor is queue-backed with crash recovery, loop guards, and a circuit breaker.

Actions are registered into an `ActionPool` via `di.xml`. To add a custom action, implement `ActionInterface` and register it — that's the extension API. See `src/module-workflows-actions-core/etc/di.xml` for the pattern.

Workflows support **shadow mode** (log what *would* happen without side effects) and **dry-run** (trace execution against a real entity without writing anything).

## Project status

**What works:** The full engine — triggers, conditions, actions, delays, branching, the admin UI (grid + form with JSON editor + execution logs), REST API, CLI, import/export, shadow mode, dry-run, the template gallery, the React Flow canvas, and the approval gate. CI runs lint and unit tests on PHP 8.1–8.4.

**What needs work:**
- The admin condition editor ships a **JSON editor fallback** — the rule-widget tab and metadata-driven action form are not yet built
- Test coverage is growing (280 unit tests, 90 integration tests) but incomplete
- The **B2B domain pack** is designed but not implemented
- No signed remote template feed yet
- APIs and schema will change — this is a 0.x release

See the [Delivery Plan](docs/13-delivery-plan.md) and [Capability Roadmap](docs/16-capability-roadmap.md) for the full picture.

## Documentation

| | |
|---|---|
| **Start here** | [Overview & Positioning](docs/01-overview.md) · [Definition Format](docs/04-definition-format.md) · [Use Cases](docs/17-use-cases.md) |
| **Architecture** | [Domain Model](docs/03-domain-model.md) · [Packages](docs/02-packages.md) · [Execution Model](docs/08-execution-model.md) |
| **Building blocks** | [Triggers](docs/05-triggers.md) · [Conditions](docs/06-conditions.md) · [Actions](docs/07-actions.md) |
| **Operating** | [Operations Guide](docs/15-operations.md) · [Security Model](docs/10-security.md) · [Scope & ACL](docs/09-scope-acl-observability.md) |
| **Planning** | [Delivery Plan](docs/13-delivery-plan.md) · [Roadmap](docs/16-capability-roadmap.md) · [Risks](docs/14-risks.md) · [Known Boundaries](docs/18-limitations.md) |
| **Admin UI** | [Admin UI](docs/11-admin-ui.md) · [LLM-Assisted Authoring](docs/21-ai-assisted-authoring.md) |
| **Testing** | [Testing Strategy](docs/19-testing-strategy.md) · [Integration Test Plan](docs/20-integration-test-plan.md) |
| **Future** | [B2B Pack](docs/12-b2b.md) · [Discovery & Enhancements](docs/discovery/README.md) |

## Repository layout

```
src/module-workflows/                    Core engine
src/module-workflows-admin-ui/           Admin UI
src/module-workflows-actions-core/       Built-in actions
src/module-workflows-triggers-core/      Async-events trigger binding
src/module-workflows-scheduler/          Cron + query-based triggers
src/module-workflows-sales/              Sales domain pack
src/module-workflows-customer/           Customer domain pack
src/module-workflows-catalog/            Catalog domain pack
src/module-workflows-inventory/          Inventory domain pack
src/module-workflows-review/             Review domain pack
src/module-workflows-newsletter/         Newsletter domain pack
src/module-workflows-wishlist/           Wishlist domain pack
src/metapackage-workflows-suite/         Metapackage (all of the above)
src/module-workflows-canvas/             Optional: React Flow editor
src/module-workflows-templates/          Optional: template gallery
src/module-workflows-admin-extension/    Optional: native-grid strips
src/module-workflows-approvals/          Optional: approval gate
src/module-workflows-import-suppression/ Optional: bulk-import suppression
spec/                                    JSON Schemas + conformance fixtures
docs/                                    Architecture documentation
dev/                                     Test runner, shims, CI tools
```

The engine and shared infrastructure packs are entity-agnostic — all entity bindings live in the domain packs. [`dev/tools/dependency-honesty-check.php`](dev/tools/dependency-honesty-check.php) enforces this in CI.

## Licensing

This repository's own code is licensed **OSL-3.0** (see `LICENSE.txt`, included in every package).

The `mage-os/workflows-canvas` package bundles third-party code (React, @xyflow/react, elkjs/EPL-2.0, d3 helpers) in a prebuilt JS file. Full attribution and license texts are in [`src/module-workflows-canvas/THIRD-PARTY-NOTICES.txt`](src/module-workflows-canvas/THIRD-PARTY-NOTICES.txt), generated from the lockfile and verified by CI.

No other package redistributes third-party code. Ordinary Composer dependencies (guzzlehttp/guzzle, dragonmantank/cron-expression — both MIT, both already in Magento core) are resolved at install time.

## Contributing

Contributions are welcome. This project is in early development — please open an issue before starting significant work so we can discuss the approach.

## Credits

Built by [Mage-OS](https://mage-os.org) contributors with extensive use of [Claude Code](https://claude.com/claude-code) for architecture, implementation, and documentation.
