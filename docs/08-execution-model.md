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

## Static graph validation

The runtime `MAX_STEPS_PER_RUN` cap (≈1000) is a backstop, not the primary defense. Every authoring path (admin Save, REST save, CLI import, gallery install) funnels through the save-time validation pipeline behind `WorkflowRepositoryInterface::save`, whose `GraphCheck` runs a DFS over `getStepEdges()` from `entry`:

| Finding | Code | Severity |
|---|---|---|
| Cycle reachable from `entry` | `GRAPH_CYCLE` | **error** (blocks save) — the engine has no loop semantics, so a cycle is always an authoring error; catching it at save time converts ~1000 iterations of wasted step-row/context churn into an immediate rejection |
| Step unreachable from `entry` | `GRAPH_UNREACHABLE_STEP` | warning |
| `branch`/`switch` with **all** edges null | `GRAPH_DEAD_EDGE` | warning (the shipped form assembler can emit exactly this as a last-row branch, so it must stay re-savable) |
| `branch`/`switch` directly after a `delay` with `revalidate_entity: false` | `GRAPH_POST_DELAY_STALE` | warning (see [Conditions §Delay semantics](06-conditions.md#delay-semantics)) |

Errors block the save; warnings travel with it (admin form messages, REST responses, CLI output). Validation policy lives **outside** the parser: `Executor` re-parses `definition_snapshot` on every resume, so parse-time rules would be retroactive across parked executions — the pipeline never touches the executor's load path. The same pipeline runs read-only over an unsaved draft via `POST /V1/workflows/validate` and the edit form's "Refresh preview" button (see [Definition Format §Save-time validation](04-definition-format.md#save-time-validation)).

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
