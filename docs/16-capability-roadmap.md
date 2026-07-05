# 16 — Capability Roadmap (post-review execution plan)

**Status:** Implemented — waves 1–5 landed (July 2026); wave 6 test coverage in progress. This plan operationalized the July 2026 state-and-outlook review.
**Scope rule:** everything from the review **except** Phase 3 (canvas, template gallery, dry-run UI, connectors program) and the B2B company pack. (Canvas, the template gallery, and the dry-run UI were subsequently implemented via the discovery track — pending live-install verification — see [docs/discovery/](discovery/README.md); the connectors program and the B2B company pack remain deferred per [13 — Delivery Plan](13-delivery-plan.md).)

## Waves

### Wave 1 — Correctness fixes (dead ends found in review)

| Item | Problem | Resolution |
|---|---|---|
| `inventory.stock_threshold_crossed` publisher | Trigger declared in `workflow_triggers.xml` + `async_events.xml` but nothing published it | `StockThresholdDetector` cron added in `workflows-scheduler` (legacy stock, hysteresis flag table, batch-capped) |
| Quote schedule queries | `quote` entity had no repository wired in `QueryRunner` | `CartRepositoryInterface` wired in scheduler `di.xml` |
| Unbounded delays | No max delay; a `P1Y` typo parks an execution silently | `mageos_workflows/guards/max_delay_days` (default 365), clamped at runtime with a logged warning |

### Wave 2 — Entity & condition support

- **Quote condition root** (`quote` joins `sales_order` / `customer` / `catalog_product` as a first-class entity): `Condition\Quote\Attribute` + `Combine`, `QuoteHydrator` via `CartRepositoryInterface`. Enables "cart total > $100" on `quote.abandoned`.
- **Customer order-history aggregates**: computed attributes `orders_count`, `lifetime_sales`, `avg_order_value`, `last_order_at`, `days_since_last_order` hydrated on demand from `sales_order` (never in snapshot → zero cost unless referenced; classifier marks them `needs_hydration` automatically).
- **Broadened order attribute list**: adds addresses (country/region/postcode/city for billing+shipping), `order_currency_code`, `discount_amount`, `total_paid`, `total_refunded`, `customer_is_guest`.
- **Generic Trigger Data condition**: leaf condition matching any dot-path in the trigger payload (e.g. `from_status` / `to_status` on `sales.order.status_changed`). Available under every combine.
- **Relative date values**: date-type conditions accept `-30 days` style values, evaluated against now at run time — enables "created more than N days ago" / "within last N days".

### Wave 3 — Delay & wait semantics

Definition schema bumped to **version 2** (`schema: 1|2` accepted; v1 documents remain valid — v2 only adds optional fields and one step type).

- **Delay step additions** (all optional, default = v1 behavior):
  - `business_days: true` — day components count Mon–Fri in the store's timezone.
  - `at: "HH:MM"` — after the duration, roll forward to the next occurrence of that store-local time ("wait 1 day, then release at 09:00 store time").
- **New `wait` step** — parks the execution until a named trigger event fires **for the same entity**, with a timeout:
  ```json
  {"type": "wait", "config": {"event": "sales.order.updated", "timeout": "PT1H"},
   "on_event": "thank_you", "on_timeout": "remind"}
  ```
  Event match resumes via `on_event`; the resume sweeper fires `on_timeout` at the deadline. This replaces the racy delay+branch idiom for "wait 1h, cancel if paid".

### Wave 4 — Action library completion

| Action | Notes |
|---|---|
| `order.create_shipment` | `ShipOrderInterface`, guarded by `canShip()`, optional customer notify |
| `order.create_creditmemo` | `RefundOrderInterface` offline full refund, guarded by `canCreditmemo()` |
| `notify.email` ad-hoc mode | `subject` + `body` alternative to `template_id`, via a bundled pass-through template; variables work in both |
| `product.set_categories` | add / remove / replace via `CategoryLinkManagementInterface` |
| `product.set_special_price` | price + from/to dates, store-scoped attribute update |
| `product.set_stock` MSI | optional `source_code` uses MSI `SourceItemsSave` when MSI is present; legacy default-source path unchanged |
| `notify.webhook` auth | first-class `auth_type` (`bearer`/`basic`) + `auth_secret` (secret name, resolved directly — never appears in config or logs) |
| `customer.anonymize` | GDPR-assist: scrambles PII fields, unsubscribes newsletter; requires explicit `confirm: true` |

### Wave 5 — Variables, API, platform

- **Variable formatters**: `{{ path|filter }}` / `{{ path|filter:'arg' }}` with a fixed whitelist — `upper`, `lower`, `trim`, `number[:decimals]`, `date[:'format']`, `default:'fallback'`. Still no directive execution, no method calls.
- **REST API** (`etc/webapi.xml`): workflow CRUD + list, execution list/get, under the existing ACL resources — enables CI/CD deployment of workflows without SSH.
- **CI matrix**: unit suite + lint on PHP 8.1 / 8.2 / 8.3 / 8.4.

### Wave 6 — Test coverage (in progress, continuous through all waves)

- Standalone runner extended to discover `Test/Unit` across the module suite (the original five, now joined by the admin-extension, canvas, templates, and import-suppression modules), with an autoload fallback shim layer (`dev/tests/shims/`) providing minimal Magento interface definitions when Magento isn't installed. Runs unmodified under real PHPUnit in CI. The canvas additionally carries a TypeScript test suite.
- New suites: actions (webhook SSRF/auth, email idempotency + ad-hoc, order lifecycle guards, denylists), conditions (quote root, aggregates, relative dates, trigger-data), engine (definition v2, delay math incl. business days/`at`, wait step routing, max-delay clamp), variable formatters, scheduler detectors (abandoned cart, stock threshold, quote queries).

## Still deferred

The **connectors program** and the **B2B pack** — see [13 — Delivery Plan](13-delivery-plan.md) and [12 — B2B Pack](12-b2b.md). The rest of Phase 3 (canvas, template gallery, dry-run UI) plus a set of capability enhancements (multi-way branching, entity cross-referencing, fan-out, batch aggregation) have since been implemented via the discovery track ([docs/discovery/](discovery/README.md)). Either way, **live-install verification** (`setup:di:compile`, RabbitMQ path, async-events seam fidelity) remains the gate before any GA claim and cannot be done in this repository alone.
