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
                            delay   -> persist state=waiting, resume_at; RELEASE message
                            wait    -> persist state=waiting + waiting_event; park until event or timeout
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
- Actions should be idempotent where cheap (add-comment dedupes on execution UUID); where not, **at-least-once is documented per action**. Non-idempotent actions check a per-step dedupe key (execution UUID + step key) — e.g., email send logs the key before SMTP.
- An entity deleted during a delay: the resume path treats missing-entity as `skipped` with an explicit log status, never as an error retry.

## Scaling and sizing

Consumers scale horizontally and off-box exactly like async-events consumers — the same ops story clients already run.

Sizing reality: a single `workflow.execute` consumer comfortably handles **hundreds of executions/min** when Phase-1 evaluation dominates ([Conditions §Two-phase evaluation](06-conditions.md#two-phase-evaluation-the-eav-at-scale-answer)); the ceiling is action side effects (order save ≈ 100–300ms), which parallelize across consumers.

For day-to-day running — required consumers, cron, the `workflow:health` and `workflow:stats` commands, and remediation for each health check — see the [Operations Guide](15-operations.md).
