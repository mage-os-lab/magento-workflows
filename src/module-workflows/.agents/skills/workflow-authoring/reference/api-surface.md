# API + CLI surface — authoring reference

Every route below is declared in `src/module-workflows/etc/webapi.xml`; every command in
`src/module-workflows/Console/Command/`. ACL resources are declared in
`src/module-workflows-admin-ui/etc/acl.xml` (and `src/module-workflows-approvals/etc/acl.xml`).
Request/response field names follow the service-interface parameter names in
`src/module-workflows/Api/`.

## REST

| Method + route | ACL | Purpose |
|---|---|---|
| `GET /V1/workflows` | `::view` | List (SearchCriteria) |
| `GET /V1/workflows/:workflowId` | `::view` | Read one |
| `POST /V1/workflows` | `::manage` | Create |
| `PUT /V1/workflows/:workflowId` | `::manage` | Update |
| `DELETE /V1/workflows/:workflowId` | `::manage` | Delete |
| `POST /V1/workflows/validate` | `::manage` | Validate-only, no persist |
| `POST /V1/workflows/dry-run` | `::dry_run` | Dry-run a posted definition |
| `POST /V1/workflows/:workflowId/dry-run` | `::dry_run` | Dry-run a saved workflow |
| `GET /V1/workflows/meta/actions` | `::view` | Action palette + config forms |
| `GET /V1/workflows/meta/triggers` | `::view` | Declared event triggers |
| `GET /V1/workflows/meta/entity-types` | `::view` | Workflow entity types |
| `GET /V1/workflows/meta/relations` | `::view` | Registered cross-entity relations |
| `GET /V1/workflows/meta/secrets` | `::view` | Secret **names** only |
| `GET /V1/workflows/meta/options` | `::view` | Option-source union (`source`, optional `query`) |
| `GET /V1/workflow-executions[/:executionId]` | `::view` | Execution history |
| `GET /V1/workflow-executions/:executionId/steps` | `::view` | Per-step timeline |

ACL resources: `MageOS_Workflows::view`, `::manage`, `::enable`, `::manual_run`,
`::dry_run`, `::secrets`, plus per-group authoring gates `::action_sales`,
`::action_customer`, `::action_catalog`, `::action_marketing`, `::action_notify`,
`::action_flow`. `::dry_run` deliberately does **not** imply `::manual_run` — previewing is
not running.

**There is no separate ACL gate for enabling over REST.** `MageOS_Workflows::enable` gates
the admin grid's mass enable/disable controllers
(`src/module-workflows-admin-ui/Controller/Adminhtml/Workflow/MassEnable.php`,
`MassDisable.php`); the edit-form Save controller and the REST save path are both `::manage`,
so a `::manage` credential *can* write `status: 1`. Nothing stops you but this rule:
**agents never write an enabled status.** Author `0` (disabled) or `2` (shadow), and let a
human flip it.

`GET …/dry-run` is intentionally a 404 (it falls through to `getById('dry-run')`), pinned by
a contract test. Use POST.

### `POST /V1/workflows/validate`

Args (from `Api/DefinitionValidationInterface::validate`): `definition` (required, raw
definition JSON **as a string**), `conditionsSerialized`, `triggerType`, `triggerRef`,
`entityType` (the last three feed the plain-language rendering only).

Returns (`Api/Data/DefinitionValidationResultInterface`): `valid` (bool; warnings do not
invalidate), `messages[]` (`severity` `error|warning`, `code`, `message`, `stepKey`, `edge`),
`plainLanguage` (merchant sentence).

Per-action ACL re-authorization is **skipped** here — a validate call is not an authoring
path. A definition that validates can still fail on save with `ACTION_UNAUTHORIZED`.

### `POST /V1/workflows/dry-run`

Args (from `Api/WorkflowDryRunInterface::runOnDefinition`): `definition` (string, required),
`entityType` (required), `conditionsSerialized`, `entityId`, `triggerPayload`
(object — synthetic, CI only, snapshot-only fidelity; omit `entityId` when using it).

`POST /V1/workflows/:workflowId/dry-run` takes `entityId` **or** `triggerPayload`.

Returns (`Api/Data/DryRunResultInterface`): `valid`, `skipped` (root conditions did not
match), `truncated` (visit cap hit), `messages[]`, `steps[]`. Each trace step
(`Api/Data/DryRunTraceStepInterface`): `stepKey`, `type`, `status`
(`would_run` | `would_fail` | `skipped` | `production_stops_here`), `pathIds[]`, `would`,
`edgeTaken`, `notes[]`, and `config` / `condition` / `timing` as JSON strings
(config is interpolated with secrets already redacted).

No queue, no side effects, no real secrets resolved. Dry-runs of *saved* workflows persist by
default as `mode = 'dry_run'` execution rows (an audit marker only — never a side-effect
predicate); unsaved-definition runs are transient.

## CLI (`bin/magento`)

| Command | Notes |
|---|---|
| `workflow:run <id> --entity-id=<n> --dry-run` | Preview a **saved** workflow, no dispatch. Unsaved-definition dry-run is REST/admin only |
| `workflow:run <id> --entity-id=<n> [--payload='{}']` | **Really dispatches** an execution (`trigger_type=manual`). Agents do not run this |
| `workflow:export <id> [--file=<path>]` | Export envelope; never contains secret values |
| `workflow:import <file> [--activate] [--shadow]` | Creates **disabled** by default. Never pass `--activate`. Runs with system privileges and does **not** re-authorize actions against an admin ACL |
| `workflow:template:list` | Bundled template catalog |
| `workflow:template:install <code> [-p k=v] [--params-file f.json] [--activate] [--shadow]` | Installs **disabled** by default; same untrusted-import warning as import |
| `workflow:secret:list` | Names only |
| `workflow:secret:set <key> [--value=…]` | **Never run this.** Secret creation is a human action |
| `workflow:secret:delete <key>` | **Never run this.** |
| `workflow:health` | Ops triage |
| `workflow:stats [--workflow-id=<id>]` | Execution counts by workflow |

Because CLI import/install bypass admin ACL re-authorization, prefer REST or the admin UI
whenever the ACL boundary is the point — and always tell the human which path you used.
