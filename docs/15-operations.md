# 15 — Operations Guide

This is the runbook for keeping the workflow engine actually running after install, not
just installed. It exists because of a specific failure mode: on a default Magento
install (no RabbitMQ, no consumers started, cron possibly not configured), the engine
looks fully functional — workflows save, triggers fire, `mageos_workflow_execution` rows
appear with `status = pending` — and then **nothing ever processes them**. There is no
error, no red banner, nothing in the default admin UI. The workflow silently does
nothing forever. `workflow:health` and the admin grid notice described below exist to
close that gap.

## Required infrastructure

The engine has three moving parts beyond the web request/save path. All three must be
running for an execution to progress end to end (see
[Execution Model](08-execution-model.md)):

1. **Magento cron** (`* * * * *` in the crontab, i.e. `bin/magento cron:run` actually
   firing on schedule). Two of this module's own jobs live in the `default` cron group
   (`etc/crontab.xml`):
   - `mageos_workflows_resume_sweeper` — every minute. On a db-queue install this *is*
     the delay-resume mechanism (see below); it also recovers zombie steps whose consumer
     died mid-step.
   - `mageos_workflows_prune_executions` — daily at 02:00. Retention/PII pruning (below).

   The scheduler module (`workflows-scheduler`) registers three more jobs in the same
   group: `mageos_workflows_scheduler` (every minute; evaluates schedule-type
   workflows), `mageos_workflows_abandoned_carts` (every 10 minutes), and
   `mageos_workflows_stock_threshold` (every 10 minutes) — the publisher for the
   `inventory.stock_threshold_crossed` trigger. The stock detector fires when a managed
   product's qty drops to or below `mageos_workflows/scheduler/stock_threshold`
   (default 5; `0` disables it), with hysteresis via the `mageos_workflow_stock_flag`
   table so a product hovering at the boundary fires once, not every 10 minutes.

   If Magento cron is not scheduled at all (no crontab entry, or `cron:run` never
   invoked), none of these jobs ever execute, independent of queue backend.

2. **Queue consumers.** Executions and delay-resumes are dispatched onto message queue
   topics (`etc/queue_publisher.xml`, `etc/queue_consumer.xml`); a consumer process must
   be running to actually pull those messages and do the work. At minimum, run:
   - `mageos.workflow.execute` — processes newly dispatched executions (walks the step
     graph, runs actions).
   - `mageos.workflow.resume` — resumes executions parked at a `delay` step once
     `resume_at` passes.

   Also run whatever consumer(s) `mageos-async-events` uses for its event-trigger
   delivery (`event.trigger.consumer` in that package's terminology) — event-triggered
   workflows depend on async-events delivering the trigger before this module's
   dispatcher ever runs. Check that package's own operations docs for the exact consumer
   name(s) shipped in your version, and include them in the same consumer-running setup
   as the two above.

3. **A queue backend**: RabbitMQ (`amqp`) or the built-in MySQL-backed `db` connection.

### RabbitMQ (recommended)

Configure `queue_amqp` in `env.php` as usual. With RabbitMQ, consumers are normally run
as long-lived supervised processes (supervisord, systemd, `bin/magento
queue:consumers:start <consumer-name>` under a process manager), one or more instances
per consumer for horizontal scale. Delay-step resumption uses a DLX+TTL parking-queue
pattern for sub-minute precision — the same pattern async-events uses for retry backoff.

### DB queue (no RabbitMQ)

If `env.php` has no `queue/amqp/host` configured, the `db` connection is used
automatically for any publisher/consumer that doesn't pin a connection — this module's
`queue_consumer.xml` deliberately omits a `connection` attribute for exactly this reason.
On this path:

- There is **no long-running consumer process by default**. You must either run
  `bin/magento queue:consumers:start mageos.workflow.execute` /
  `... mageos.workflow.resume` (plus the async-events consumer(s)) under a process
  manager yourself, **or** add them to Magento's `cron_consumers_runner` so cron invokes
  them periodically:

  ```php
  // app/etc/env.php
  'cron_consumers_runner' => [
      'cron_run' => true,
      'max_messages' => 20000,
      'consumers' => [
          'mageos.workflow.execute',
          'mageos.workflow.resume',
          // plus your async-events consumer(s)
      ],
  ],
  ```

  Without one of these two, published messages sit in the `queue_message`/
  `queue_message_status` tables forever and executions never leave `pending`.
- Delay-step resumption has **1-minute precision, not sub-minute** — it rides the
  `mageos_workflows_resume_sweeper` cron job against the
  `(status, resume_at)` index on `mageos_workflow_execution_step`, not a queue message.
  This is a documented floor of the db-queue path, not a bug.

## `workflow:health` and `workflow:stats`

- **`bin/magento workflow:health`** — runs `MageOS\Workflows\Model\Health\HealthCheck`
  and prints each check as a table row (OK / WARN / FAIL) with a remediation message.
  Exits non-zero if any check is FAIL, so it's suitable for a deploy smoke-test or an
  external monitoring probe (cron + alert on non-zero, Nagios/Icinga check, etc.). The
  same checks render as a compact notice above the workflow grid in the admin UI
  (`MageOS\WorkflowsAdminUi\Block\Adminhtml\Health`) — that notice renders nothing at all
  when every check is OK, and shows only the problem rows otherwise.

- **`bin/magento workflow:stats [--workflow-id=<id>]`** — execution counts by workflow +
  status, the same breakdown for the last 24h, and a count of steps currently `waiting`
  (parked on a delay). Use it to sanity-check throughput and spot a workflow accumulating
  `waiting` steps that never drain (a resume-path problem) or `pending` executions that
  never move (a consumer-not-running problem) before `workflow:health` even flags it as
  stale.

### Health checks reference

| Code | Meaning | Remediation |
|---|---|---|
| `queue_backend` | Whether `queue/amqp/host` is set in deployment config. | WARN (not FAIL) when unset — db-queue is a supported path, not an error. Confirm you've wired consumers into `cron_consumers_runner` or a process manager per "DB queue" above. |
| `cron_alive` | Whether any `cron_schedule` row has `scheduled_at` in the last 15 minutes. | FAIL means Magento cron itself isn't running. Check your OS crontab has the Magento-generated entry, and that `bin/magento cron:run` isn't erroring (`var/log/cron.log`, `var/log/system.log`). |
| `sweeper_scheduled` | Whether `mageos_workflows_resume_sweeper` has a `cron_schedule` row in the last 30 minutes. | WARN. Either the `default` cron group isn't running, or `setup:upgrade` / `cache:flush` hasn't run since this module was installed so the job isn't registered in the schedule generator yet. Run `bin/magento setup:upgrade` and wait one cron cycle. |
| `stuck_executions` | Count of `mageos_workflow_execution` rows `status = pending` with `triggered_at` older than 10 minutes. | FAIL. The `mageos.workflow.execute` consumer is not running (or has been down long enough to accumulate a backlog). Start/restart it; see "Required infrastructure" above. |
| `overdue_resumes` | Count of `mageos_workflow_execution_step` rows `status = waiting` with `resume_at` older than 10 minutes. | FAIL. Neither the `mageos.workflow.resume` consumer nor the `mageos_workflows_resume_sweeper` cron job is draining delay steps. Check both: consumer process status, and `sweeper_scheduled` above. |
| `async_events_module` | Whether `MageOS_AsyncEvents` is enabled (`Magento\Framework\Module\Manager::isEnabled`). | FAIL if disabled. Event-triggered workflows (the majority of workflows in practice) will never fire — this module is a hard dependency (`etc/module.xml` sequence), so a disabled dependency here means someone ran `module:disable` on it directly. Re-enable it: `bin/magento module:enable MageOS_AsyncEvents`. |

All checks that touch the database catch their own exceptions and degrade to WARN rather
than throwing — e.g. running `workflow:health` before `setup:upgrade` has created the
module's tables reports WARN ("table may not exist yet; run setup:upgrade"), not a
stack trace.

## Retention / PII pruning

Execution `context` snapshots hold entity data (order/customer fields referenced by the
workflow), so unbounded retention is a PII liability, not just disk usage. The
`mageos_workflows_prune_executions` cron job (daily, 02:00) deletes completed executions
— and their step rows — older than the configured retention window:

- Config path: `mageos_workflows/retention/days` (`Stores > Configuration`, or directly
  via `bin/magento config:set mageos_workflows/retention/days <n>`).
- Default: 90 days. Can be set as low as a few hours if your compliance posture requires
  it; the job batches deletes (1000 executions/pass) so a large backlog doesn't hold long
  locks.
- Only `completed_at IS NOT NULL` rows are eligible — executions still in flight
  (`pending`/`running`/`waiting`) are never pruned regardless of age.
- This is retention pruning, not full GDPR erasure; see
  [Security §PII containment](10-security.md#pii-containment) for the erasure-hook and
  ES field-redaction controls, which are separate from this cron job.

**Dry-run audit rows** (`mode='dry_run'`, from persisted admin dry-runs of saved
workflows) are previews, not history, and carry entity snapshots — so the same cron
prunes them first on a **separate, shorter** clock:

- Config path: `mageos_workflows/dry_run/retention_days` (default 7). Independent of the
  90-day live-execution window above; whatever survives it is still swept by the general
  retention.
- `mageos_workflows/dry_run/persist` (default 1) toggles the persistence itself. Set to 0
  to keep dry-runs transient (no audit rows written at all). Unsaved-definition dry-runs
  are always transient regardless of this flag.

**Batch rows** (`mageos_workflow_batch` + `mageos_workflow_batch_item`, from aggregated
workflows) also carry projected entity snapshots. Flushed batches (and their items, which
CASCADE) are pruned by the same `mageos_workflows_prune_executions` cron on the general
`mageos_workflows/retention/days` clock. Open/flushing batches are never pruned — they are
still accumulating or mid-flush.

**Debounce slots** (`mageos_workflow_debounce`) are swept by the same cron. The
dispatcher's atomic debounce works by *inserting* a slot row per guarded dispatch — the
insert is the check — so the table would otherwise grow forever. A slot only guards its
own time bucket, so the cron deletes rows older than twice the configured
`mageos_workflows/guards/debounce_window_seconds` (minimum keep: one hour). No PII is
involved (workflow id, entity id, bucket number only) and there is no separate setting.

**Approval task rows** (`mageos_workflow_approval`, from the optional
`mage-os/workflows-approvals` addon — [Approval Gate discovery §3](discovery/approval-gate.md#3-data-model))
carry `title`/`instructions` interpolated **at park time**, plus any decision `note`/`payload` —
the same PII class as execution `context` snapshots, so they are not pruned independently:
`execution_id` is `onDelete CASCADE`, so a task row disappears the moment
`mageos_workflows_prune_executions` deletes its parent execution on the general retention
clock above. There is no separate approvals retention setting.

## Reconciliation sweep

The addon's `mageos_workflows_approval_reconcile` cron job (`MageOS\WorkflowsApprovals\Cron\ReconcileApprovals`,
`* * * * *`, same one-minute cadence as the core resume sweeper) is the backstop for approval
tasks whose execution moved on without going through a decision or the timeout path
([Approval Gate discovery §4 "Orphans"](discovery/approval-gate.md#4-decision-semantics-and-races)):

- An open task whose execution reached a terminal status (`complete` / `cancelled` /
  `failed` / `skipped`) is marked `orphaned` — this is a backstop for `failExecution`, which
  already orphans inline; anything the sweep finds here is a bug marker, not a valid steady
  state, and is logged as such.
- An open task whose execution is still live but whose `current_step` has moved off that
  gate's `step_key` is marked `expired` — the backstop for a best-effort `expireTask` failure
  in `ResumeConsumer::routeApprovalStep` (logged and swallowed there to avoid a routing loop).
- An open task whose execution's `current_step` still equals its `step_key` is never touched,
  regardless of execution status — the gate may be genuinely parked, mid-park, or just
  claimed for resume; every transition above is additionally guarded on `status = 'open'`, so
  a decision that claimed the task microseconds earlier is never overwritten.

**Uninstalling `mage-os/workflows-approvals`.** The step semantics (parser, park handler,
`ResumeConsumer` routing) live in core, so a parked `approval` gate still resumes by timeout
after the addon is removed — the sweeper and routing don't need it. But the task record,
decision service, REST API, and admin grid go with the addon: decisions become impossible,
and any still-open tasks orphan with no reconciliation sweep to clean them up (the cron job
above is the addon's). **Disable (or otherwise drain) any workflow with a live gate before
uninstalling the addon** — the same caveat as removing any action module a workflow still
references, sharper here because the consequence is a silently undecidable, permanently
`waiting` execution rather than a save-time rejection
([Approval Gate discovery §7 "Missing-module posture"](discovery/approval-gate.md#7-packaging--thin-core-seam--module-workflows-approvals-addon)).

## Batch aggregation (event-window digests)

An [aggregated workflow](discovery/batch-aggregation.md) turns N events into one digest
execution. There are two mechanisms and two new tables:

- **Collected mode** (`aggregation.mode = collected`) rides the existing scheduler cron
  (`mageos_workflows_run_scheduled`): `QueryRunner` accumulates matches and dispatches one
  batch execution. No new infrastructure.
- **Window mode** (`aggregation.mode = window`) uses the **event accumulator**: the
  dispatcher appends each matching event to a batch (`mageos_workflow_batch` /
  `mageos_workflow_batch_item`, keyed `UNIQUE(workflow_id, window_key)` and
  `UNIQUE(batch_id, entity_id)`), and a new **flush sweep** releases due batches.

**New cron job:** `mageos_workflows_flush_batches` (instance `MageOS\Workflows\Cron\FlushBatches`,
schedule `* * * * *`) rides the same one-minute cadence as the resume sweeper. Each pass:

1. claims due-and-open batches (`flush_due_at` passed) with an atomic `open → flushing`
   UPDATE — one worker wins each batch, so overlapping cron ticks never double-flush;
2. creates the batch execution, records its id on the batch row **before** publishing
   (write-before-publish), publishes, then stamps `flushed`;
3. re-claims batches stuck in `flushing` past a 5-minute grace period (a crashed sweeper)
   and **re-publishes the recorded execution** — never a second one.

**Window policies:**

- `schedule` — `flush_due_at` is the next cron fire in the batch's declared store timezone;
  the `window_key` is the window-start instant, so every event in the window converges on
  one batch.
- `interval` — the window closes `PT…` after the opening event.

**`min_items`** (default 1): a window closing under the minimum **carries** its items to the
next window in `interval` mode, or **drops** them with a debug log in `schedule` mode.

**Suppression synergy:** by default a bulk import inside `WorkflowSuppression::scope()` drops
aggregated events just like per-entity ones. Set `aggregate_suppressed_events: true` on the
workflow to keep accumulating during a storm — the import becomes one "12,431 products were
updated" digest instead of silent drops. Off by default (it trades suppression's near-free
drop for per-event membership evaluation + an insert — see
[08 — Execution Model](08-execution-model.md#aggregated-batch-workflows)).

The known-bulk-path wiring for CSV imports (Data/System > Import) ships in the
optional `mage-os/workflows-import-suppression` package (so the core engine has
no hard ImportExport dependency): `mageos_workflows/general/suppress_bulk_imports`
— enabled by default when the package is installed — wraps the whole import run
in `WorkflowSuppression::scope()` so a 100k-row `catalog_product_import` doesn't
fire one dispatch per row; disable it only if a store deliberately wants per-row
workflow reactions during import.

**Remediation:**

| Symptom | Likely cause | Action |
|---|---|---|
| Batches accumulate but never flush | `mageos_workflows_flush_batches` cron not running | `bin/magento cron:run --group=default`; check `cron_schedule` for the job |
| A batch stuck in `flushing` | sweeper crashed between claim and publish | the next sweep re-claims it after the 5-minute grace and re-publishes the recorded execution; no manual action |
| Digest fired but `items[]` is short with `overflow: true` | the match count exceeded `item_cap` (default 500) | expected — `count` is always accurate; raise `item_cap` in the workflow's aggregation config if the full list is needed |
| Aggregated workflow never accumulates | events dropped by suppression, or membership never matches | check `aggregate_suppressed_events`; confirm the root conditions are in-snapshot (save-time validation enforces this) |
| Batch execution shows `entity_id = 0` in the grid | expected — a batch has no single entity | the grid renders "batch (N items)"; the collection is in `context.trigger.items` |

## Configuration quick reference

Guard and scheduler keys added by the capability-roadmap waves, alongside the retention
and circuit-breaker keys documented in their own sections:

- `mageos_workflows/guards/max_delay_days` (default 365) — ceiling for delay durations
  *and* wait-step timeouts, clamped at runtime with a logged warning, so a mistyped
  `P1Y` cannot park an execution silently.
- `mageos_workflows/scheduler/stock_threshold` (default 5) — quantity boundary for the
  `mageos_workflows_stock_threshold` detector job; `0` disables the detector entirely.
- `mageos_workflows/guards/fan_out_cap` (default 100) — global ceiling on how many
  executions one triggering event may fan out to (see **Fan-out** below). Per-workflow
  fan-out caps clamp to this value; excess targets are dropped with a logged,
  admin-visible marker.
- `mageos_workflows/guards/relation_cap` (default 100) — maximum related-entity ids a
  single relation lookup may return (the cross-referencing condition and the fan-out
  expander share this path); excess is dropped with a logged warning, and an `ALL` match
  over a truncated set fails toward `false`.

## REST API

Workflow CRUD and execution reads are exposed over the standard Magento REST layer
(`src/module-workflows/etc/webapi.xml`): `GET`/`POST /V1/workflows` and
`GET`/`PUT`/`DELETE /V1/workflows/:workflowId` under the `MageOS_Workflows::view` /
`::manage` ACL resources, plus read-only `GET /V1/workflow-executions[/:executionId]`
under `::view`. This is the CI/CD deployment path for workflow definitions when SSH
(`bin/magento workflow:import`) isn't available.

**Dry-run** is exposed as `POST /V1/workflows/dry-run` (a posted definition + entity ref
or a synthetic `triggerPayload` for CI, snapshot-only fidelity) and
`POST /V1/workflows/:workflowId/dry-run` (saved workflow), both under the dedicated
`MageOS_Workflows::dry_run` resource (which does **not** imply `::manual_run`). The
response carries the validation findings and, on a sound graph, the flat step trace. The
route shapes are POST-only and non-colliding with `GET /V1/workflows/:workflowId`, so a
stray `GET …/dry-run` falls through to `getById('dry-run')` → 404 (pinned by a contract
test). CLI equivalent: `bin/magento workflow:run <id> --entity-id <n> --dry-run`.

## Fan-out

A workflow with a **fan-out** clause turns one triggering event into N ordinary
single-entity executions — one per member of a declared relation resolved against the
source entity (e.g. *customer group changed → hold each of the customer's open orders*).
Each child is an ordinary execution: full guard stack (debounce, scope, suppression,
circuit breaker) and grid visibility, distinguishable only by its `origin` context and
indexed `origin_uuid`.

Operational notes:

- **Cap the blast radius.** `mageos_workflows/guards/fan_out_cap` (default 100) is the
  global ceiling; a per-workflow cap clamps to it. Storm math is
  `events/sec × relation size`, so review the cap before enabling a fan-out workflow on a
  high-frequency trigger.
- **Debounce window ≥ worst-case redelivery delay (load-bearing).** Fan-out expansion
  happens inside the notifier consumer. If it crashes mid-expansion, async-events
  redelivers and the expander **re-expands from scratch** — the per-child debounce
  (`mageos_workflows/guards/debounce_window_seconds`, default 60) is what collapses the
  already-dispatched children so redelivery does not double-dispatch. That safety holds
  only while the debounce window comfortably exceeds the queue's worst-case redelivery
  delay. If you raise redelivery backoff, raise the debounce window to match.
- **Truncation is never silent.** Over-cap expansions log a `fan_out_truncated` warning
  and record `{dispatched, skipped, truncated}` on the notifier result; the dry-run
  preview and plain-language rendering both surface the cap.
- **"Caused by" filter.** The execution grid's `origin_uuid` column (filter: *Caused by*)
  returns every child of one source event's trace UUID — the one-query answer to "show me
  everything that group change caused".

## Circuit-breaker recovery

`MageOS\Workflows\Model\Engine\CircuitBreaker` auto-suspends a workflow
(`status = suspended`) after N consecutive step failures (default 10, config
`mageos_workflows/guards/circuit_breaker_threshold`), logs a `critical` log entry, and
raises an admin notification. This is deliberate: a misconfigured webhook or a downstream
outage must not silently burn the retry queue for days. **A suspended workflow does not
self-heal** — a human has to look at it.

To recover:

1. Find out why it tripped: `bin/magento workflow:stats --workflow-id=<id>` for volume,
   then the execution grid's drill-down timeline (per-step status/error) for the actual
   failure. Fix the underlying cause first (bad webhook URL, expired credential, a
   downstream API outage that has since resolved, etc.) — re-enabling without fixing the
   cause just re-trips the breaker after N more failures.
2. Re-enable the workflow: edit it and set status back to **Enabled**, or use the grid's
   **Enable** mass action (`MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow\MassEnable`,
   ACL resource `MageOS_Workflows::enable`). Either path sets
   `status = WorkflowInterface::STATUS_ENABLED` via `WorkflowRepositoryInterface::save()`.
3. The breaker's consecutive-failure counter is cache-backed and keyed per workflow; it
   is cleared automatically the moment a step for that workflow succeeds again, and also
   cleared as part of the trip itself, so re-enabling starts the counter fresh at 0 — you
   are not still "9 failures in" after re-enabling.

## First 10 minutes after install

1. `bin/magento setup:upgrade` — creates the module's tables and registers its cron jobs
   with Magento's schedule generator. `bin/magento setup:db:status` should report the
   schema as up to date immediately afterwards; if it reports pending `modify_column`
   changes on any of the suite's tables, see "Declarative schema and JSON columns" below.
2. Confirm Magento cron is actually scheduled at the OS level (not just that
   `crontab.xml` exists) — `crontab -l` for the web user should show the Magento-managed
   entry.
3. Decide RabbitMQ vs. db-queue (see "Required infrastructure" above) and start
   consumers accordingly — either long-running consumer processes, or
   `cron_consumers_runner` entries in `env.php` for `mageos.workflow.execute` and
   `mageos.workflow.resume` (plus your async-events consumer(s)).
4. Wait a couple of minutes for at least one cron cycle, then run
   `bin/magento workflow:health`. Everything should read OK except possibly
   `queue_backend` (WARN is expected and fine on a deliberate db-queue install).
5. Create or import one workflow, trigger it (e.g. `bin/magento workflow:run
   <workflow_id> --entity-id=<id>` for a manual smoke test), and confirm with
   `bin/magento workflow:stats --workflow-id=<id>` that the execution reaches
   `complete` rather than sitting in `pending`.
6. Open the workflow grid in the admin UI — if anything above was missed, the health
   notice block will say so directly on that page from now on; on a fully healthy
   install it renders nothing.

## Declarative schema and JSON columns

Every column in the suite that stores a JSON payload is declared `mediumtext`, never
`xsi:type="json"`. This is deliberate and should stay that way.

MariaDB implements `JSON` as an alias for `LONGTEXT` plus an automatic
`CHECK (json_valid(col))` constraint, so `information_schema.COLUMNS.DATA_TYPE` reads
back `longtext`. Magento's declarative-schema differ builds the *declared* column via
`Dto\Factories\Json` (which yields a `Dto\Columns\Blob`) and the *introspected* column
via `Dto\Factories\LongText` (which yields a `Dto\Columns\Text`), and
`Setup\Declaration\Schema\Comparator::compare()` begins with
`get_class($first) === get_class($second)`. `Blob` never equals `Text`, so on MariaDB a
`json` column can never compare equal no matter what its `nullable`, `default` or
`comment` attributes are. The result is a permanent `modify_column` entry in
`bin/magento setup:db:status` that `setup:upgrade` re-applies but can never clear,
because the `ALTER ... MODIFY ... JSON` it emits produces a `longtext` column again.

`mediumtext` resolves to `Dto\Columns\Text` on both the declared and the introspected
side, so it round-trips cleanly on MariaDB *and* MySQL 8. 16 MB is far above any
payload this suite writes.

If you are upgrading from a build that still declared these columns as `json`, the first
`setup:upgrade` after the change emits one `ALTER TABLE ... MODIFY ... mediumtext` per
affected column. That is expected, runs once, and converts the column in place — no data
is lost (MariaDB was already storing the value as text; MySQL 8 renders the JSON document
to its text form). On MariaDB the `json_valid()` check constraint that came with the JSON
alias is dropped along with the old column definition. Plan for a table rebuild on
`mageos_workflow_execution_step` and `mageos_workflow_batch_item` if those tables are
large.
