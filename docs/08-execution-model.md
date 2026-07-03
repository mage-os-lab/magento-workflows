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
                            stop    -> complete
```

## Resumption after delays

| Queue backend | Mechanism |
|---|---|
| RabbitMQ | Per-delay message via DLX+TTL parking queue (the same pattern async-events uses for retry backoff) |
| DB queue | Cron sweeper (1 min) on `INDEX(status, resume_at)` |

Both paths converge on a `workflow.resume` consumer. RabbitMQ is documented as recommended, and required for sub-minute delay precision — the identical stance async-events takes for retry backoff.

## Crash safety and delivery semantics

Executions are **resumable and crash-safe**:

- State is in the DB *before* any side effect; consumer death mid-step = redelivery.
- Steps are marked `running` with a claim timestamp so a sweeper can fail-or-retry zombies.
- Actions should be idempotent where cheap (add-comment dedupes on execution UUID); where not, **at-least-once is documented per action**. Non-idempotent actions check a per-step dedupe key (execution UUID + step key) — e.g., email send logs the key before SMTP.
- An entity deleted during a delay: the resume path treats missing-entity as `skipped` with an explicit log status, never as an error retry.

## Scaling and sizing

Consumers scale horizontally and off-box exactly like async-events consumers — the same ops story clients already run.

Sizing reality: a single `workflow.execute` consumer comfortably handles **hundreds of executions/min** when Phase-1 evaluation dominates ([Conditions §Two-phase evaluation](06-conditions.md#two-phase-evaluation-the-eav-at-scale-answer)); the ceiling is action side effects (order save ≈ 100–300ms), which parallelize across consumers.
