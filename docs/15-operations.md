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

## Configuration quick reference

Guard and scheduler keys added by the capability-roadmap waves, alongside the retention
and circuit-breaker keys documented in their own sections:

- `mageos_workflows/guards/max_delay_days` (default 365) — ceiling for delay durations
  *and* wait-step timeouts, clamped at runtime with a logged warning, so a mistyped
  `P1Y` cannot park an execution silently.
- `mageos_workflows/scheduler/stock_threshold` (default 5) — quantity boundary for the
  `mageos_workflows_stock_threshold` detector job; `0` disables the detector entirely.

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
   with Magento's schedule generator.
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
