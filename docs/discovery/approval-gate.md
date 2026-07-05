# Discovery — Approval / Decision Gate (Human-in-the-Loop Step)

**Status:** Discovery / evaluation · **Track:** human-in-the-loop (graduated from [exploration](exploration-composition-creation-long-span.md))
**Related:** [18 — Known Boundaries §Human-in-the-loop](../18-limitations.md#human-in-the-loop) · [08 — Execution Model §Wait steps](../08-execution-model.md#wait-steps-schema-2) · [04 — Definition Format](../04-definition-format.md) · [06 — Conditions §Delay semantics](../06-conditions.md#delay-semantics) · [dry-run.md](dry-run.md)

---

## 1. Reframing the gap

[18 — Known Boundaries](../18-limitations.md#human-in-the-loop) lists approval chains as an
explicit v1 non-goal: the only human touchpoint is `notify.admin`, a fire-and-forget broadcast.
The workflow can *tell* a human, but cannot *stop and branch on what the human decides* — so
every flow that needs a sign-off ends at a notification and a manual handoff. The examples that
matter:

- Goodwill credit requested → **create the credit memo only if a manager approves**; on reject,
  send the policy email instead.
- High-risk order held → park for fraud review → on approve, unhold + invoice; on reject,
  cancel + notify; **on 4-hour silence, escalate to a second reviewer**.
- Price drop >30% detected → revert unless a merchandiser confirms within 24h.
- Customer inactive 3 years → anonymize **after** a human confirms.

The reframing that makes this tractable: **the engine already has the exact semantics — the
`wait` step.** A `wait` parks the execution (`status=waiting`), races an external happening
against a timeout, is claimed atomically by whichever side wins, and routes `on_event` /
`on_timeout` with the happening's payload injected as step output
([08 §Wait steps](../08-execution-model.md#wait-steps-schema-2), `Dispatcher::resumeWaiting()`,
`Model/Engine/Dispatcher.php:150–228`). An approval gate is a wait whose "event" is a human
decision instead of a Magento event — and whose timeout **is** the SLA clock. Nothing about the
park/claim/resume spine changes; what's new is the *decision surface*: a task record, an admin
UI to decide from, and a REST API so external tools (Slack bots, middleware, BI dashboards) can
post a decision with a payload.

What this is **not** (dispositioned to neighbors):

- Not an anonymous inbound endpoint — deciders authenticate as Magento admins or API
  integrations. An unauthenticated signed-token callback ("vendor calls back when done") is the
  separate inbound-callback exploration ([exploration §G3](exploration-composition-creation-long-span.md)).
- Not multi-step approval *chains* with reassignment/delegation — tiers are modeled as chained
  gate steps (each with its own timeout/escalation edge), not as a chain object.
- Not a general task-management surface — the task exists to resolve a parked step, nothing else.

## 2. Approaches

### A1 — Dedicated `approval` step type on the wait spine — recommended

A new step type (schema 4), three edges, parked exactly like `wait`:

```json
{
  "type": "approval",
  "config": {
    "title": "Approve goodwill credit for order {{ trigger.increment_id }}",
    "instructions": "Customer requested cancellation outside the 30-day window. Total: {{ trigger.grand_total|number:2 }}.",
    "timeout": "P3D",
    "assignee_role": "sales_managers",
    "allow_bulk": false,
    "payload_fields": [
      {"key": "approved_amount", "label": "Approved amount", "type": "number", "required": false}
    ]
  },
  "on_approved": "s5",
  "on_rejected": "s6",
  "on_timeout": "s7"
}
```

Park path (mirrors `runWaitStep`, `Executor.php:357–376`): persist the step row
`status=waiting` with `resume_at` = now + timeout (same `DelayCalculator`, same
`max_delay_days` clamp), set execution `status=waiting` with `current_step` on the gate itself,
**and insert one approval-task row** (title/instructions interpolated at park time, `due_at` =
`resume_at`). Task insert is idempotent on `(execution_id, step_key)` so redelivery re-parks
cleanly.

Wake paths — three, where `wait` has two, all converging on the existing `workflow.resume`
consumer:

1. **Decision** (admin UI or REST): `ApprovalService::decide()` — a near-clone of
   `resumeWaiting()` (atomic claims, result-before-publish, rollback on publish failure;
   §4 specifies the races).
2. **Timeout**: the existing `ResumeSweeper` claims the execution when `resume_at` passes —
   zero new code; the sweep *is* the SLA breach.
3. (Nothing else — no event path; a gate that also wants event resolution is two steps.)

`ResumeConsumer::routeWaitStep()` (`ResumeConsumer.php:111–153`) already routes on the parked
step's `result.resolution`; the gate extends the same routing to
`approved → on_approved`, `rejected → on_rejected`, no result → `on_timeout` (and marks the
task `expired`).

- ✅ Three-edge routing is authorable and dry-runnable exactly like `switch`/`wait`; SLA +
  escalation fall out of the existing timeout machinery; every observability surface
  (execution timeline, stats, sweeper) applies unchanged.
- ⚠️ Cost: a schema bump, a new table + service + two ACL resources, an admin grid, REST
  routes, canvas node — itemized in §7.

### A2 — Reuse the `wait` step verbatim + a "post synthetic event" API

Expose an API that fires a synthetic Magento event (`workflow.approval.granted`) that existing
`wait` steps park on. No new step type, no schema bump.

- ⚠️ Rejected: the decision has nowhere to live — no task record means no approvals inbox, no
  due-date, no assignee, no audit of who decided; the two-edge `wait` can't distinguish
  approve from reject without two chained waits; and a synthetic-event API is a general
  "inject events from outside" surface with a much larger security story than a scoped
  decision endpoint. It saves a step type and spends it on incoherence.

### A3 — Approval lives outside; workflow just webhooks out and waits

Send the approval request to an external system (Slack, Jira) via the webhook action; that
system calls the decision API when a human acts.

- ✅ This is not actually a rival — it is A1's API consumed from outside, and it works on day
  one: the webhook body carries the task UUID (`{{ steps.gate.task_uuid }}` — the park step
  writes it to its own output before releasing the message), the external tool posts the
  decision with an integration token. The admin grid remains the fallback for tokens/tools
  that don't exist yet. A1 subsumes A3.

**Recommendation: A1.** The step type carries the semantics; the REST API (§5) makes it
externally consumable; the admin surface (§6) makes it usable with zero integration work.

## 3. Data model

One new table, `mageos_workflow_approval` (module-workflows, alongside the execution tables):

| Column | Notes |
|---|---|
| `approval_id` | PK |
| `uuid` | unique, indexed — the API/deep-link handle; never expose the int id |
| `execution_id`, `step_key` | unique composite (idempotent re-park); FK cascade with execution |
| `workflow_id`, `entity_type`, `entity_id` | denormalized for the grid (filter/link without JSON digging) |
| `title`, `instructions` | interpolated **at park time** (they render in grids/emails; late interpolation would leak post-hoc entity changes into an already-issued request) |
| `assignee_role` | nullable role code; targeting + (if set) enforcement at decide time |
| `status` | `open` → `approved` \| `rejected` \| `expired` (timeout won) \| `orphaned` (execution died otherwise) |
| `due_at` | = the step's `resume_at`; the grid's SLA column |
| `decided_by_type`, `decided_by_id` | `admin` \| `integration` + actor id — the audit trail |
| `decision_note`, `decision_payload` | note text; validated JSON payload (§5) |
| `created_at`, `decided_at` | |

Task rows are pruned on the execution-retention clock (they reference executions and carry the
same PII class as context rows — [15 §Retention](../15-operations.md#retention--pii-pruning));
`title`/`instructions` are interpolated snapshots and must be treated as PII-bearing.

## 4. Decision semantics and races

`ApprovalService::decide(uuid, decision, note, payload, actor)`:

1. **Task claim** — atomic `UPDATE … SET status=:decision, decided_* WHERE uuid=? AND
   status='open'`. First decision wins; the loser gets a clean "already decided by X" error.
   This is the decision-vs-decision arbiter (two admins clicking at once).
2. **Execution claim** — the same conditional `waiting → pending` UPDATE `resumeWaiting()`
   uses (`Dispatcher.php:171–178`). This is the decision-vs-timeout arbiter: if the sweeper
   claimed the execution microseconds earlier, the decide call rolls the task claim back to
   `open`, reports "expired", and the timeout path marks it so.
3. **Result before publish** — write `{resolution, decided_by, note, payload}` into the parked
   step row's `result`, then publish to `workflow.resume`; on publish failure roll both claims
   back (the sweeper still owns the timeout) — the exact posture of
   `Dispatcher.php:183–215`.

Downstream, the step output is `steps.<key>.resolution`, `steps.<key>.note`,
`steps.<key>.payload.*`, `steps.<key>.decided_by` — available to branches and interpolation
like any wait output. The flagship use: an approver-supplied `approved_amount` flowing into a
downstream action's config — the one sanctioned way a *human-entered value* enters a flow
mid-execution (the resolver still computes nothing; the human is the computer).

**Post-gate staleness:** an approval can park for days, so a branch after it evaluating
against the frozen trigger snapshot has the same staleness hazard as post-delay branches.
Extend `GRAPH_POST_DELAY_STALE` ([08 §Static graph validation](../08-execution-model.md#static-graph-validation))
to treat `approval` like `delay`/`wait`.

**Orphans:** if the execution leaves `waiting` by any path other than a decision — timeout
(→ `expired`), execution failure, or a future cancel surface — the task must not stay `open`.
Timeout marking rides `ResumeConsumer`'s `on_timeout` routing; `failExecution` marks
`orphaned`. A reconciliation sweep (piggybacked on the existing `ResumeSweeper` cadence)
catches anything that slips — an `open` task whose execution is terminal is a bug marker, not
a valid state.

**No indefinite parks:** `timeout` is **required** (validation error when absent; default
suggestion `P7D` in the form), clamped by `max_delay_days` like every `resume_at`. A gate
nobody answers *must* resolve — `on_timeout: null` (end the walk) is a legal author choice,
an unbounded open task is not. This keeps the parked fleet self-draining and sidesteps the
"no cancel surface" gap ([exploration §3](exploration-composition-creation-long-span.md)) rather
than depending on it.

## 5. REST API (the "post a decision and payload" surface)

Admin/integration-token authenticated web API, same conventions as the existing routes
(`etc/webapi.xml`):

| Route | Method | ACL | Purpose |
|---|---|---|---|
| `/V1/workflow-approvals` | GET | `::approvals_view` | searchCriteria list — inbox for external tools (filter `status=open`, `assignee_role`, `due_at`) |
| `/V1/workflow-approvals/:uuid` | GET | `::approvals_view` | one task, incl. title/instructions/entity refs |
| `/V1/workflow-approvals/:uuid/decision` | POST | `::approvals_decide` | body `{"decision": "approved"\|"rejected", "note": "…", "payload": {…}}` |

Two new ACL resources under `MageOS_Workflows::workflows` (`acl.xml`):
`::approvals_view` (sortOrder 47) and `::approvals_decide` (sortOrder 48). Deciding is
deliberately **not** implied by `::manage` — the people who approve refunds are usually not
the people who author workflows. If the task carries an `assignee_role`, decide additionally
requires the actor to hold that role (integration tokens: the integration must be granted the
role's resources) — enforced at decide time, not just filtered in the UI.

**Payload constraints** (this is merchant-facing input entering execution context — same trust
class as a captured webhook response, and handled the same way):

- Flat JSON object, scalar values only, size-capped (8 KB), key count capped (20).
- If the step declares `payload_fields`, keys are **allowlisted against the declaration** and
  values type-coerced (`string`/`number`/`boolean`); required fields enforced on `approved`
  only. No declaration = note-only (empty payload) — payload acceptance is opt-in per gate.
- Values land in context as values, never structure ("interpolation supplies values, never
  structure", [10 — Security](../10-security.md)); rendered HTML-escaped everywhere
  (timeline, grid, plain-language trace).
- Secrets never appear in tasks in either direction: `{{ secrets.* }}` is rejected in
  `title`/`instructions` at save time (they render in grids and emails), and payload values
  are stored plaintext — a decision is not a secret channel.

## 6. Admin surface (act/resume without any integration)

- **Approvals grid** (`Controller/Adminhtml/Approval/Index`, menu under Workflows, gated
  `::approvals_view`): columns title, workflow, entity (deep link), due-in, status,
  assignee role. Default filter `status=open`. Row action opens the **decision view**.
- **Mass decide, per-gate opt-in**: the grid carries approve/reject mass actions, but they
  only apply to tasks whose gate declared `allow_bulk: true` (default `false`) — low-stakes
  gate classes ("confirm sending the win-back batch") can be cleared in bulk; refund-style
  gates never can. The mass action posts through the same `ApprovalService::decide()` per
  row (same claims, same audit trail, one shared note, empty payload), with a confirmation
  count and a selection cap borrowing the manual mass-run vocabulary
  ([10 §Manual mass-run](../10-security.md#manual-mass-run)); rows whose gate is not
  bulk-enabled are skipped and reported, never silently decided. Save-time validation
  rejects `allow_bulk: true` on a gate with any *required* `payload_fields` entry — a bulk
  approval cannot supply per-task values, so the combination is an authoring error.
- **Decision view** (`Approval/View` + `Decide` POST controller, gated `::approvals_decide`):
  title, instructions, entity summary + link, the execution timeline so far, note field, and —
  when `payload_fields` is declared — a generated form for exactly those fields (no free-form
  JSON entry in v1). Approve / Reject buttons post through the same `ApprovalService::decide()`
  as REST, so races, validation, and audit are identical.
- **Execution view**: an execution parked on a gate shows an inline decision panel (same
  component) — "resume from the execution you're already staring at."
- **Notification at park**: an admin-inbox notice with the deep link (existing notifier
  machinery), plus optional `notify_emails` config on the step for direct email — the gate
  owns this because only the gate knows the task UUID; a `notify.*` step *before* the gate
  cannot link to a task that doesn't exist yet.
- **Plain language** ([04 §Save-time validation](../04-definition-format.md#save-time-validation)):
  *"then wait up to 3 days for a decision (Sales Managers): if approved → …, if rejected → …,
  if no decision → …"*. The timeout consequence must be unmissable — a merchant who doesn't
  realize silence takes a branch will be surprised in the worst way.

**Dry-run** ([dry-run.md](dry-run.md)): the gate behaves like `wait` — never parks, explores
**all three edges** with rejoin dedupe, annotates the resolved due-time, and injects a
placeholder output (`{resolution: "approved", payload: {approved_amount: "SIMULATED"}}` per
declared field) so downstream interpolation renders. **Canvas**: one new node type (three
labeled handles) via the same palette/config-panel metadata path as `switch`.

## 7. Quality, maintainability, reliability

- **Reliability:** no new park state, no queue-topology change, no executor-walk change beyond
  one step handler; both wake paths reuse the proven atomic-claim + result-before-publish +
  rollback pattern verbatim. The long-park concerns from the exploration (RabbitMQ TTL
  fragility at month spans, parked-fleet observability) apply but are bounded here: gates are
  *required* to time out, and approval parks ride the DB sweeper path (`resume_at` row), not a
  parked AMQP message — document that gates get their timeout from the sweeper on both
  backends.
- **Schema discipline:** `approval` rides schema 4 following the exact `switch` template
  (type constant, shape validator, `getStepEdges()` returning the three named edges, spec/
  JSON-Schema release + conformance fixtures, compat rule: using `approval` requires
  `"schema": 4`). If the sub-workflow invoke step advances on a similar clock, ship them in
  one schema revision rather than burning two.
- **Security:** the decision endpoint is authenticated web API (never anonymous); UUID handles
  only; per-resource ACL split between viewing and deciding; role enforcement at decide time;
  payload allowlisted/coerced/capped; full actor audit on the task row; decided tasks are
  immutable (no re-decide, no edit). Bulk decisions honor the same gates: `allow_bulk` is
  enforced server-side per row (not just filtered in the grid), so a crafted mass-action
  request cannot bulk-decide a gate that didn't opt in.
- **Maintainability:** one table, one service, one step handler, one routing extension, two
  controllers + grid, three REST routes. The service is the single decision path for UI and
  API — no parallel logic.
- **Testability:** unit — park idempotency, claim races (decision-vs-decision,
  decision-vs-timeout, publish-failure rollback), payload validation matrix, routing;
  conformance — schema-4 fixtures + dry-run three-edge exploration; the shim harness covers
  all of it like the existing engine suites.

## 8. Sequencing & effort

No dependency on other discovery-track features (relation registry, fan-out, etc.); depends
only on the shipped wait/resume spine. Estimates in the same currency as
[13 — Delivery Plan](../13-delivery-plan.md).

| Order | Item | Effort |
|---|---|---|
| 1 | Engine: step type + schema 4 + spec release, park handler, `ApprovalService::decide()`, `ResumeConsumer` routing, timeout/orphan marking, task table | ~1.5 wk |
| 2 | REST routes + ACL resources + payload validation + audit; `GRAPH_POST_DELAY_STALE` extension; plain-language rendering | ~1 wk |
| 3 | Admin: approvals grid, decision view, execution-view panel, park notifications | ~1.5 wk |
| 4 | Dry-run three-edge exploration, canvas node, docs (04/07/08/09/15/18 updates), test suites | ~1 wk |

Total ≈ **5 wk**. Stages 1–2 are independently shippable as an API-only feature (external
tools can decide before the grid exists); stage 3 is what makes it a merchant feature.

## 9. Resolved decisions (July 2026 review)

Originally open questions; resolved with the project owner. The body sections above reflect
these outcomes.

1. **Reminder pings before timeout — no for v1.** Escalation tiers are chained gates, and a
   reminder is a delay+notify the author can already build; revisit if beta merchants ask.
2. **Mass decide — yes, per-gate opt-in** (`allow_bulk: true`, default `false`; §6). Bulk
   clearing is legitimate for low-stakes gate classes, but the gate author decides — never the
   grid operator. Server-side enforcement per row; incompatible with required `payload_fields`
   (save-time error).
3. **`assignee_role` = role code**, matching Magento authorization roles. Users churn; roles
   are the stable handle. A per-user "claim this task" affordance can layer on later without
   schema change (`decided_by` already records the individual).
4. **`::approvals_view` stays separate from `::view`.** Approval tasks carry interpolated PII
   the execution grid doesn't surface as prominently; the grant is explicit.
5. **Schema-4 packaging — ship `approval` alone when ready.** Don't couple a committed
   feature's timeline to the still-pre-discovery sub-workflow invoke step
   ([exploration §1](exploration-composition-creation-long-span.md)); if the invoke step
   happens to be committed before the schema-4 spec release ships, merging into one revision
   remains an option, not a dependency.
