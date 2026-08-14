# 03 — Domain Model

```
Workflow            1 ──── n  WorkflowStep          (definition, versioned)
Workflow            1 ──── n  WorkflowExecution     (runtime instance per trigger firing)
WorkflowExecution   1 ──── n  WorkflowExecutionStep (per-step runtime state)
```

A **Workflow** owns:

- identity (name, status)
- scope (website/store IDs)
- trigger binding (async event name OR schedule)
- a root condition tree (serialized rule conditions)
- an ordered step list

A **step** is `{type: action|delay|branch|stop, config: json, on_true/on_false: step refs}`. Linear chains are the degenerate case of the step graph — the schema supports branching from day one even if the v1 UI only exposes linear + delay.

## DDL sketch

```sql
CREATE TABLE mageos_workflow (
  workflow_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(255) NOT NULL,
  status           TINYINT NOT NULL DEFAULT 0,          -- 0 disabled, 1 enabled
  trigger_type     VARCHAR(32) NOT NULL,                -- event | schedule | manual
  trigger_ref      VARCHAR(255) NOT NULL,               -- async event name | cron expr
  entity_type      VARCHAR(64) NOT NULL,                -- sales_order, customer, catalog_product...
  conditions_serialized MEDIUMTEXT,                      -- rule condition tree (same format as salesrule)
  definition       JSON NOT NULL,                        -- step graph
  version          INT UNSIGNED NOT NULL DEFAULT 1,
  loop_guard_depth TINYINT NOT NULL DEFAULT 1,
  created_at / updated_at TIMESTAMP
);
CREATE TABLE mageos_workflow_website (workflow_id, website_id, PK(workflow_id, website_id));

CREATE TABLE mageos_workflow_execution (
  execution_id     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid             CHAR(36) NOT NULL,                    -- correlates with async-events trace UUID
  workflow_id      INT UNSIGNED NOT NULL,
  workflow_version INT UNSIGNED NOT NULL,
  entity_id        INT UNSIGNED NOT NULL,
  store_id         SMALLINT UNSIGNED NOT NULL,
  status           VARCHAR(16) NOT NULL,                 -- pending|running|waiting|complete|failed|cancelled
  context          JSON,                                 -- variable bag (trigger snapshot + step outputs)
  chain_depth      TINYINT NOT NULL DEFAULT 0,           -- loop guard
  triggered_at / completed_at TIMESTAMP,
  INDEX (workflow_id, status), INDEX (uuid), INDEX (status, triggered_at)
);

CREATE TABLE mageos_workflow_execution_step (
  step_execution_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  execution_id      BIGINT UNSIGNED NOT NULL,
  step_key          VARCHAR(64) NOT NULL,
  status            VARCHAR(16) NOT NULL,                -- pending|running|complete|failed|skipped
  result            JSON,                                -- action output (webhook response, coupon code, ...)
  error             TEXT,
  resume_at         TIMESTAMP NULL,                      -- for delay steps
  started_at / finished_at TIMESTAMP,
  UNIQUE (execution_id, step_key),                       -- one row per step; makes the upsert atomic
  INDEX (status, resume_at)                              -- sweeper index
);
```

## Versioning semantics

Definitions are **versioned on save**:

1. Bump `version`.
2. Append the prior definition to a `mageos_workflow_revision` table — this feeds the change-history UI ([Admin UI §Merchant accessibility](11-admin-ui.md#merchant-accessibility--openness)).
3. Pin executions to the version they started under.

Executions **store the full definition snapshot** in their row — this is not optional. A mid-flight execution with a 3-day delay must resume into the exact graph it started in, with zero joins and no dependency on revision retention policy.

See also:

- [Definition Format](04-definition-format.md) — the `definition` JSON contract
- [Execution Model](08-execution-model.md) — how execution/step rows drive resumption and crash safety
