# 02 — Package Decomposition

Composer packages, mirroring the `mageos-async-events` family layout:

| Package | Contents |
|---|---|
| `mage-os/workflows` | Core engine: domain model, evaluation, execution, queues |
| `mage-os/workflows-admin-ui` | Grid + form UI, logs UI, ACL |
| `mage-os/workflows-actions-core` | Bundled action library (order, customer, catalog, notify) |
| `mage-os/workflows-triggers-core` | Trigger metadata over `mageos-common-async-events` + gap-fill events |
| `mage-os/workflows-scheduler` | Cron/query-based triggers |
| `mage-os/workflows-b2b` | B2B triggers/conditions/actions (suggest: Adobe Commerce only) |
| `mage-os/workflows-canvas` | React Flow viewer + editor (optional, reads the same definition) |
| `mage-os/workflows-templates` | Bundled gallery template content pack (data-only; optional/trimmable — the gallery UI itself lives in `workflows-admin-ui`) |
| `mage-os/workflows-import-suppression` | Suppresses dispatch during ImportExport CSV imports (optional; keeps core free of a hard ImportExport dependency) |
| `mage-os/workflows-admin-extension` | ACL-gated summary strip + view/create deep links on native entity grids, layered on `mage-os/workflows` + `mage-os/workflows-admin-ui` (optional; entity modules are soft dependencies) |
| `mage-os/workflows-approvals` | Human-decision gate: the approval task table, decision service, REST endpoint, and admin grid/decision view behind the schema-4 `approval` step's core seam (optional; see [Approval Gate discovery](discovery/approval-gate.md)) |

> **Proposed evolution (discovery):** to execute the [core coverage
> backlog](discovery/core-coverage.md) without breaking compilation or runtime on installs
> missing optional core modules, the shared packs keep hard requires on the never-absent
> domains (Sales, Customer, Catalog, …) — declared honestly — while the plausibly-absent
> domains get **small dedicated modules** (`workflows-review`, `workflows-wishlist`,
> `workflows-newsletter`, deferred `-cms`) plus a `workflows-suite` metapackage; MSI keeps the
> runtime-guard pattern. Module enable state, not in-code guards, is the absence-safety
> boundary. See
> [Core Coverage §Packaging](discovery/core-coverage.md#packaging-where-the-backlog-lives).

## Dependencies

- **Hard dependency of core:** `mage-os/mageos-async-events` — the engine rides its notifier seam, queue transport, retry, and tracing (see [Triggers](05-triggers.md) and [Execution Model](08-execution-model.md)).
- **Soft dependency (suggest):** `mageos-async-events-admin-ui` — useful for raw subscription debugging during development and support.

## Separation rationale

- The **core engine** stays UI-free so headless installs and CI pipelines can run it (definitions are installable via data patches and the import CLI).
- **Actions** and **triggers** ship as separate packages because they are pure *content on the pools* — the same extension mechanism third parties use. The bundled packages are reference implementations of the SDK, not privileged code.
- The **scheduler** is separate because query-based triggers (cron + condition-tree-as-query) carry their own operational weight (batching, watermarks, match caps — see [Triggers §Scheduled](05-triggers.md#scheduled-triggers-workflows-scheduler)) that pure event-driven installs don't need.
- The **canvas** is optional and purely presentational: it reads and writes the same [definition JSON](04-definition-format.md), so it can lag or be replaced without engine changes.
