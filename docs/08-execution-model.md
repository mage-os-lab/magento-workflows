# 08 — Execution Model & Queue Topology

```
async-events event.trigger.consumer ──> WorkflowNotifier ──> workflow.dispatch (topic)
                                                                   │
                                          workflow.execute.consumer (N instances, horizontal)
                                                                   │
                     ┌─ evaluate root conditions (two-phase) ── false ─> log, complete(skipped)
                     └─ true: walk step graph
                            action  -> execute inline, persist step result
                            branch  -> evaluate, follow edge
                            switch  -> evaluate cases top-down, follow first match (or default)
                            delay   -> persist state=waiting, resume_at; RELEASE message
                            wait    -> persist state=waiting + waiting_event; park until event or timeout
                            approval-> persist state=waiting + open approval task; park until decision or timeout
                            stop    -> complete
```

## Resumption after delays

| Queue backend | Mechanism |
|---|---|
| RabbitMQ | Per-delay message via DLX+TTL parking queue (the same pattern async-events uses for retry backoff) |
| DB queue | Cron sweeper (1 min) on `INDEX(status, resume_at)` |

Both paths converge on a `workflow.resume` consumer. RabbitMQ is documented as recommended, and required for sub-minute delay precision — the identical stance async-events takes for retry backoff.

`resume_at` — for delays and wait timeouts alike — is ceiling-clamped to `mageos_workflows/guards/max_delay_days` (default 365) with a logged warning when the clamp fires, so a mistyped duration cannot park an execution silently for a year.

## Wait steps (schema 2)

A `wait` step parks the execution (`status = waiting`) with the awaited event name written to `mageos_workflow_execution.waiting_event`; a `(status, waiting_event, entity_id)` index makes the event-side lookup an index hit, not a scan. Two paths race to wake it, and each execution is claimed exactly once:

- **Event match** — the hidden wait subscription (`workflow:<id>:wait:<event>`, see [Triggers](05-triggers.md)) delivers the event to `Dispatcher::resumeWaiting()`, which selects parked executions matching `(workflow_id, waiting_event, entity_id)` and claims each with an atomic `waiting → pending` conditional UPDATE — a concurrent timeout sweep or duplicate event delivery loses the race cleanly. The event payload is written into the wait step row's result *before* the resume is published.
- **Timeout** — the resume sweeper claims the execution once the timeout deadline passes (computed like any delay and subject to the same max-delay ceiling); wait timeouts ride the sweeper on both queue backends.

Both converge on the same `workflow.resume` consumer, which routes `on_event` or `on_timeout` depending on whether the step row carries an event result, and injects `{resolution: "event"|"timeout", event: <payload>}` as the wait step's output — available to downstream branches and interpolation as `steps.<key>.*`.

## Switch steps (schema 3)

A `switch` step is a multi-way `branch`: `Executor::runSwitchStep()` hydrates the entity once (governed by the step's single `revalidate_entity` flag), then evaluates each case's `conditions_serialized` tree top to bottom through the same `ConditionEvaluator::evaluateSerialized` the `branch` step uses. **First match wins** — the walker follows that case's `next` edge; if no case matches it follows the nullable `default` edge (a null `default` ends the walk). An empty/absent `conditions_serialized` on a case always matches, so an unconditional trailing case behaves like `default`.

`switch` adds no new persistence state: exactly like `branch`, the step row is written **before** the edge is followed, so crash-safety analysis is unchanged. The step result records `{matched: <key>|null}`, feeding the execution timeline, dry-run traces, and plain-language rendering. Edge topology comes from `Definition::getStepEdges()`, the single source of a step's outgoing edges.

## Approval steps (schema 4)

An `approval` step is a `wait` whose "event" is a human decision instead of a Magento event, and whose timeout **is** the SLA clock ([Approval Gate discovery](discovery/approval-gate.md)). It parks exactly like `wait`: `status = waiting`, `resume_at = now + config.timeout` (same `DelayCalculator`, same max-delay ceiling), with **`current_step` left on the gate itself**. The park path additionally opens one approval task through the core `ApprovalTaskManagerInterface` seam — idempotent on `(execution_id, step_key)`, so a redelivered park after a crash re-attaches to the existing open task rather than creating a second.

Where `wait` has two wake paths, an approval gate has three, all converging on the existing `workflow.resume` consumer:

- **Decision** (admin UI or the optional addon's REST API) — `ApprovalService::decide()` races through the same two atomic claims `resumeWaiting()` uses: a task claim (`UPDATE … WHERE uuid=? AND status='open'` — the decision-vs-decision arbiter, first click wins) and an execution claim (the same conditional `waiting → pending` UPDATE — the decision-vs-timeout arbiter: if the sweeper already claimed the execution, the task claim rolls back to `open` and the caller is told the gate already expired). Result is written to the parked step row **before** the resume is published; a publish failure rolls both claims back.
- **Timeout** — the existing resume sweeper claims the execution once `resume_at` passes; zero new sweep code, the sweep *is* the SLA breach. `ResumeConsumer` marks the task `expired`.
- (No event path — a gate that also wants event resolution is composed as two steps.)

`ResumeConsumer::routeWaitStep()` extends its existing result-driven routing to the gate: `resolution: 'approved' -> on_approved`, `'rejected' -> on_rejected`, no decision result -> `on_timeout` (and the task is marked `expired`). The step output exposes `{task_uuid}` at park time and `{resolution, note, payload, decided_by}` after a decision (`{resolution: "timeout"}` on timeout) — available to downstream branches and interpolation as `steps.<key>.*`, the same convention `wait` uses. The flagship use is a decider-supplied value (e.g. `payload.approved_amount`) flowing into a downstream action's config — the one sanctioned way a human-entered value enters a running execution mid-flight.

**Orphans and reconciliation.** If the execution leaves `waiting` by any path other than a decision or a timeout (execution failure, a future cancel surface), the open task must not stay `open` — `failExecution` orphans it. A reconciliation sweep, piggybacked on the existing `ResumeSweeper` cadence, catches anything that slips through: an `open` task whose execution is already terminal is a bug marker, never a valid steady state (see [15 — Operations](15-operations.md#reconciliation-sweep)).

**Packaging.** The step semantics (parser, executor park handler, `ResumeConsumer` routing, dry-run, plain-language) live in core; the task record, `ApprovalService::decide()`, REST/ACL, and admin surface ship in the optional `mage-os/workflows-approvals` addon behind the `ApprovalTaskManagerInterface` delegation seam (see [02 — Packages](02-packages.md)). With no addon installed, an `approval` step is unauthorable (`APPROVAL_MODULE_MISSING`, [§Static graph validation](#static-graph-validation)); a data-patched one reached at runtime with no bound task manager fails the step terminally, never silently.

## Static graph validation

The runtime `MAX_STEPS_PER_RUN` cap (≈1000) is a backstop, not the primary defense. Every authoring path (admin Save, REST save, CLI import, gallery install) funnels through the save-time validation pipeline behind `WorkflowRepositoryInterface::save`, whose `GraphCheck` runs a DFS over `getStepEdges()` from `entry`:

| Finding | Code | Severity |
|---|---|---|
| Cycle reachable from `entry` | `GRAPH_CYCLE` | **error** (blocks save) — the engine has no loop semantics, so a cycle is always an authoring error; catching it at save time converts ~1000 iterations of wasted step-row/context churn into an immediate rejection |
| Step unreachable from `entry` | `GRAPH_UNREACHABLE_STEP` | warning |
| `branch`/`switch` with **all** edges null | `GRAPH_DEAD_EDGE` | warning (the shipped form assembler can emit exactly this as a last-row branch, so it must stay re-savable) |
| `branch`/`switch` directly after a `delay` or `approval` with `revalidate_entity: false` | `GRAPH_POST_DELAY_STALE` | warning (see [Conditions §Delay semantics](06-conditions.md#delay-semantics)) |
| `approval` step present with no `ApprovalTaskManagerInterface` bound (addon not installed) | `APPROVAL_MODULE_MISSING` | **error** (blocks save) — mirrors an unregistered action code |
| `approval` step with `allow_bulk: true` and a required `payload_fields` entry | `APPROVAL_BULK_REQUIRED_PAYLOAD` | **error** — a bulk decision cannot supply a per-task value |
| `approval` step referencing `{{ secrets.* }}` in `title`/`instructions` | `APPROVAL_SECRET_IN_PROMPT` | **error** — these render in the approvals grid and emails |

Errors block the save; warnings travel with it (admin form messages, REST responses, CLI output). Validation policy lives **outside** the parser: `Executor` re-parses `definition_snapshot` on every resume, so parse-time rules would be retroactive across parked executions — the pipeline never touches the executor's load path. The same pipeline runs read-only over an unsaved draft via `POST /V1/workflows/validate` and the edit form's "Refresh preview" button (see [Definition Format §Save-time validation](04-definition-format.md#save-time-validation)).

## Dry-run (synchronous preview)

Dry-run answers "what *would* this do to entity X, right now, before I enable it" — the immediacy complement to shadow mode's fidelity ([discovery](discovery/dry-run.md)). It is a **separate in-process walker** (`Model/DryRun/DryRunService` + `Walker` + `PathExplorer`); it does **not** run through the crash-safe `Executor`, add a simulation flag to it, or call `Executor::execute()`. Both walkers route over the one shared edge helper `Definition::getStepEdges()`, and a routing-equivalence conformance suite pins that they agree.

The walker reuses every production semantic component (`ConditionEvaluator`, `VariableResolver`, `DelayCalculator`, action `simulate()`) and owns only routing, time compression, and trace assembly. Semantics that differ from production, all deliberate:

- **Delays/waits never park** — they annotate the resolved resume time (same `DelayCalculator`, store timezone, max-delay clamp) and continue.
- **Waits explore both edges** (no event can arrive in-process): `on_event` and `on_timeout` both render; shared tails where they reconverge are emitted once (rejoin dedupe), and the walk is capped on **distinct** step visits.
- **Failures don't stop the walk** — a failed `simulate()`, or an unevaluable branch/switch condition, is flagged and the walk continues so every problem surfaces in one pass; steps reached only past a production-terminal failure are marked `production_stops_here`. An unevaluable branch/switch explores **all** edges (production would silently follow the false/`default` edge).
- **Secrets are redacted** — the walker's resolver is the `VariableResolverForDryRun` virtualType, so `{{ secrets.* }}` renders `***name***`, never the value (traces render in the browser and may persist).

Dry-run is gated by its own ACL resource `MageOS_Workflows::dry_run` (which does **not** imply `::manual_run`), and is reachable via the admin edit-form "Dry run" button, CLI `workflow:run --dry-run` (saved workflows by id), and REST `POST /V1/workflows/dry-run` and `POST /V1/workflows/:workflowId/dry-run`.

**`mode` column.** `mageos_workflow_execution.mode` (`live` | `dry_run`, default `live`) marks persisted dry-run audit rows (admin dry-runs of *saved* workflows, on by default). It is a feature marker, **not** a side-effect predicate — a `mode=live` row under a shadow-status workflow still ran simulated; side effects remain governed by workflow status. Dry-run rows are pruned on a separate, shorter clock (see [15 — Operations](15-operations.md#retention--pii-pruning)).

## Crash safety and delivery semantics

Executions are **resumable and crash-safe**:

- State is in the DB *before* any side effect; consumer death mid-step = redelivery.
- Steps are marked `running` with a claim timestamp so a sweeper can fail-or-retry zombies.
- Actions should be idempotent where cheap; where not, **at-least-once is documented per action**. The dedupe key is always **execution UUID + step key** — fine enough that two comment steps in one workflow coexist, coarse enough that a redelivered single step still dedupes (add-comment pinned by `AddCommentTest`; email send logs the key before SMTP).
- An entity deleted during a delay surfaces **per-action** on resume: the resume path does not re-check the entity, so the next step's action encounters the missing entity itself and reports it under that action's own failure semantics (`NoSuchEntityException` handled as terminal or retryable per action). Branch/switch steps with `revalidate_entity: true` (the default) fail closed by routing `on_false`/`default`. A uniform execution-level `skipped` resume status is tracked as [#13](https://github.com/rhoerr/magento-workflows/issues/13) but not currently implemented.

## Aggregated (batch) workflows

An [aggregated workflow](discovery/batch-aggregation.md) (its `mageos_workflow.aggregation` column is non-null) collapses N events into **one** execution carrying a collection. The executor learns exactly one thing — tolerate `entity_id = 0` — and every other batch concern lives in save-time validation and the dispatch layer. Two modes: **collected** (the scheduler digests a query at a cadence, `module-workflows-scheduler`) and **window** (the dispatcher accumulates events into a batch that a one-minute flush sweep releases, with write-before-publish idempotency on the batch row).

**Honest cost.** Accumulation is *cheaper for the whole pipeline* (no execution rows, no per-event queue round-trips, one action run instead of thousands) but it is **not** cheaper per event: today the synchronous dispatch path never evaluates root conditions (that happens later, in the async executor), whereas an aggregated window workflow moves a snapshot-only rule-tree `validate()` into the notifier/dispatch hot path — a membership filter run *per event* during exactly the storms this feature absorbs. Snapshot-only evaluation is cheap and allocation-bound (zero queries, guaranteed by the save-time in-snapshot check), but it is *added* synchronous work. The win is downstream, not on the per-event dispatch cost. With `aggregate_suppressed_events` set, the trade is starker still: a bulk import that today hits a near-free "suppressed, return" path instead pays per-event membership + an insert — bounded and worthwhile for the workflows that want one digest out of a 12k-row import, but a trade, not a freebie.

## Scaling and sizing

Consumers scale horizontally and off-box exactly like async-events consumers — the same ops story clients already run.

Sizing reality: a single `workflow.execute` consumer comfortably handles **hundreds of executions/min** when Phase-1 evaluation dominates ([Conditions §Two-phase evaluation](06-conditions.md#two-phase-evaluation-the-eav-at-scale-answer)); the ceiling is action side effects (order save ≈ 100–300ms), which parallelize across consumers.

For day-to-day running — required consumers, cron, the `workflow:health` and `workflow:stats` commands, and remediation for each health check — see the [Operations Guide](15-operations.md).
