# 09 — Scope, ACL & Observability

## Scope (multi-store semantics)

- Workflows bind to **website IDs**; store-view granularity for conditions comes via the standard `store_id IN` condition.
- The dispatcher resolves the entity's store → website and skips non-matching workflows *before* evaluation.
- **Global entities** (customer on shared accounts, product) evaluate against the event's emitting scope, falling back to workflow scope. Document this explicitly — it's the one genuinely fiddly semantic in multi-store.

## ACL

- Resources for **view / manage / enable** workflows.
- A dedicated **`MageOS_Workflows::dry_run`** resource gates the dry-run panel and REST endpoints; it deliberately does **not** imply `::manual_run` (previewing a workflow is not running it — see [Operations §Dry-run](15-operations.md)).
- **Per-action-group authoring gates** (`MageOS_Workflows::action_sales`, etc.): a merchant admin who can't cancel orders can't author a cancel-order step.
- Executions run under a **system context** (`AppArea` = crontab-like), with the authoring admin recorded on the definition for audit.
- Definition saves are logged (plays well with admin-activity modules).
- Scope is re-checked at *execution* time, not just authoring time — a workflow scoped to website 1 whose author lost website-1 access gets suspended, not silently escalated ([Security §Deferred privilege escalation](10-security.md#deferred-privilege-escalation-the-core-threat-model)).
- **Approval tasks split viewing from deciding** ([Approval Gate discovery §5](discovery/approval-gate.md#5-rest-api-the-post-a-decision-and-payload-surface)): the optional `mage-os/workflows-approvals` addon declares `MageOS_Workflows::approvals_view` and `MageOS_Workflows::approvals_decide` under the `MageOS_Workflows::workflows` tree. Deciding is deliberately **not** implied by `::manage` — the people who approve refunds are usually not the people who author workflows — and `::approvals_view` stays separate from the execution grid's `::view` because approval tasks carry interpolated PII (title/instructions snapshots) the execution grid doesn't surface as prominently. If a gate declares `assignee_role`, deciding additionally requires the actor to hold that role, enforced at decide time (not just filtered in the grid).

## Observability

- **Execution grid** — filterable by workflow / status / entity.
- **Drill-down timeline** per execution showing each step's status, duration, result, and error.
- **Correlated tracing:** executions link to the async-events trace UUID, so the full path *event → delivery → execution* is one correlated view.
- **Fan-out lineage:** fanned-out child executions carry an indexed `origin_uuid`; the execution grid's "Caused by" filter returns every child dispatched from one source event.
- **Dry-run rows:** admin dry-runs persist as `mode = 'dry_run'` execution rows (a marker only — never a side-effect predicate), feeding the same execution grid and trace panel as live runs.
- **ES indexing:** reuse the async-events ES indexing hook to index execution records for the same Lucene querying. Indexing is redaction-by-default — metadata + IDs; full payloads opt-in ([Security §PII containment](10-security.md#pii-containment)).
- **Monitoring events:** emit `workflow_execution_complete` / `workflow_execution_failed` as ordinary Magento events for monitoring integrations.
- **CLI counters:** a `workflow:stats` command for quick prod triage.

Failure UX for merchants (readable error messages, daily digest email) is covered in [Admin UI §Merchant accessibility](11-admin-ui.md#merchant-accessibility--openness).
