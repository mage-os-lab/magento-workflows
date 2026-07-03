# 09 — Scope, ACL & Observability

## Scope (multi-store semantics)

- Workflows bind to **website IDs**; store-view granularity for conditions comes via the standard `store_id IN` condition.
- The dispatcher resolves the entity's store → website and skips non-matching workflows *before* evaluation.
- **Global entities** (customer on shared accounts, product) evaluate against the event's emitting scope, falling back to workflow scope. Document this explicitly — it's the one genuinely fiddly semantic in multi-store.

## ACL

- Resources for **view / manage / enable** workflows.
- **Per-action-group authoring gates** (`MageOS_Workflows::action_sales`, etc.): a merchant admin who can't cancel orders can't author a cancel-order step.
- Executions run under a **system context** (`AppArea` = crontab-like), with the authoring admin recorded on the definition for audit.
- Definition saves are logged (plays well with admin-activity modules).
- Scope is re-checked at *execution* time, not just authoring time — a workflow scoped to website 1 whose author lost website-1 access gets suspended, not silently escalated ([Security §Deferred privilege escalation](10-security.md#deferred-privilege-escalation-the-core-threat-model)).

## Observability

- **Execution grid** — filterable by workflow / status / entity.
- **Drill-down timeline** per execution showing each step's status, duration, result, and error.
- **Correlated tracing:** executions link to the async-events trace UUID, so the full path *event → delivery → execution* is one correlated view.
- **ES indexing:** reuse the async-events ES indexing hook to index execution records for the same Lucene querying. Indexing is redaction-by-default — metadata + IDs; full payloads opt-in ([Security §PII containment](10-security.md#pii-containment)).
- **Monitoring events:** emit `workflow_execution_complete` / `workflow_execution_failed` as ordinary Magento events for monitoring integrations.
- **CLI counters:** a `workflow:stats` command for quick prod triage.

Failure UX for merchants (readable error messages, daily digest email) is covered in [Admin UI §Merchant accessibility](11-admin-ui.md#merchant-accessibility--openness).
