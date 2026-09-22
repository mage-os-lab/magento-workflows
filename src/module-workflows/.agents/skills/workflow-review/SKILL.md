---
name: workflow-review
description: Explain, audit, or review an existing MageOS_Workflows workflow — what it does in plain language, what it would do to a real entity, and what is risky about it. Use when someone asks "what does workflow 12 do?", "explain/review/audit this workflow", "why did this workflow fire?", or hands you a definition JSON or export envelope to interpret. Read-only. For creating or changing a workflow, use workflow-authoring instead.
---

# Reviewing a workflow

Read-only skill. You explain and flag; you never save, enable, run, or repair anything.

## Hard rules

1. **Read-only.** No `POST /V1/workflows`, no `PUT`, no `DELETE`, no
   `bin/magento workflow:run` without `--dry-run`, no `workflow:import`, no status change.
   If the review turns up something to fix, describe the fix and hand it to the
   `workflow-authoring` skill or the human — do not apply it.
2. **Never surface a secret value.** Definitions only ever contain
   `{{ secrets.<name> }}` references; report the *name*. Do not resolve, guess, or fetch
   values. Dry-run traces already redact secrets — do not attempt to un-redact them.
3. **Do not paraphrase the engine's own rendering — quote it.** The
   `PlainLanguageRenderer` output is the artifact merchants trust; give it verbatim, then
   add your explanation around it.
4. **Report enablement state prominently.** `status`: `0` disabled · `1` enabled (live,
   real side effects) · `2` shadow (evaluates and logs, no mutations) · `3` suspended
   (circuit breaker tripped or scope lost).

## Getting the plain-language rendering

The renderer is server-side (`src/module-workflows/Model/PlainLanguageRenderer.php`). Reach
it through:

- **Validate endpoint** (works for any definition, saved or not — this is the general case):

  ```
  POST /V1/workflows/validate          # ACL: MageOS_Workflows::manage
  {"definition": "<definition JSON as a string>",
   "conditionsSerialized": "…|null", "triggerType": "event",
   "triggerRef": "sales.order.created", "entityType": "sales_order"}
  ```

  Returns `plainLanguage` plus `valid` and `messages[]`. Pass all five fields — omitting
  `triggerType`/`triggerRef`/`entityType` degrades the sentence's "When …" clause.
- **Admin**: the workflow grid's plain-language column, and the edit form's preview panel
  ("Refresh preview").

Renderings look like:

> *When Order Created, if 2 conditions, then: Add Order Comment, wait 1 hour, stop.*

Fan-out leads with *"for each of &lt;relation&gt; (up to N)"*; aggregated (batch) workflows
render as *"Every 1 day, as one digest, for everything that matches 2 conditions, then: …"*.
The renderer is deliberately defensive — a malformed or partial definition **omits** the
clause it cannot read rather than failing. So a suspiciously short sentence is a signal:
compare it against the raw JSON before concluding the workflow is simple.

The renderer also caps at 25 steps. A long graph is summarized, not fully described.

## Review procedure

1. **Fetch** — `GET /V1/workflows/:workflowId`, or `bin/magento workflow:export <id>`, or
   read the envelope the human handed you.
2. **Render** — get `plainLanguage` from the validate endpoint and quote it.
3. **Validate** — report every `messages[]` finding (code, severity, message, `stepKey`,
   `edge`) verbatim. Warnings matter: `GRAPH_POST_DELAY_STALE`, `GRAPH_UNREACHABLE_STEP`,
   and `GRAPH_DEAD_EDGE` are the usual "this doesn't do what you think" tells.
4. **Dry-run** (ask for a real entity id):

   ```
   POST /V1/workflows/:workflowId/dry-run   # ACL: MageOS_Workflows::dry_run
   {"entityId": 12345}
   ```

   or `bin/magento workflow:run <id> --entity-id=<n> --dry-run`. Side-effect-free. Walk
   `steps[]` — `status` is `would_run` | `would_fail` | `skipped` |
   `production_stops_here`; `would` is the plain-language summary; `config` is the
   interpolated, redacted config. `skipped: true` at the top means the root conditions did
   not match this entity — say so plainly rather than reporting "nothing happens".
5. **Explain the graph** — walk it from `entry`, naming each step, its edges, and where each
   path terminates. Call out branches whose `on_false`/`default` is `null` (a silent dead
   end is usually intentional but rarely obvious).
6. **Flag the risks** — see below.
7. **Optionally, history** — `GET /V1/workflow-executions?…` and
   `GET /V1/workflow-executions/:executionId/steps` for what it has actually done;
   `bin/magento workflow:stats --workflow-id=<id>` for volume.

## What to flag

- **Enablement + scope**: enabled vs. shadow vs. disabled; `website_ids`; `status: 3`
  (suspended) means the circuit breaker tripped or the author lost scope.
- **Irreversible actions**: `order.cancel`, `order.create_creditmemo`,
  `order.create_invoice`, `order.create_shipment`, `customer.anonymize` (GDPR scrambling —
  requires `confirm: true`), `product.set_status`, `product.set_stock`,
  `marketing.generate_coupon`. Say out loud that these mutate production data.
- **Outbound calls**: `notify.webhook` — where does it POST, does it `capture_as`, is the
  captured response used in a later branch? Captured responses are attacker-influenceable
  data; they are legal in conditions and interpolated values but must never appear as an
  action code or attribute code.
- **Stale-snapshot branches**: `revalidate_entity: false` on a `branch`/`switch` after a
  `delay` or `approval` — after a park the world has moved.
- **Long parks**: `delay` durations and `wait`/`approval` timeouts. Durations are clamped
  at runtime to `mageos_workflows/guards/max_delay_days` (default 365).
- **Approval gates**: who can decide (`assignee_role`), what happens on `on_timeout` (the
  timeout consequence is the most-missed detail in a review), and `allow_bulk`.
- **Fan-out / aggregation blast radius**: `fan_out.cap` and the global
  `mageos_workflows/guards/fan_out_cap` (default 100) bound how many executions one event
  spawns; `relation_cap` (default 100) bounds relation resolution, and an `ALL` match over a
  truncated set fails toward false.
- **Loop pressure**: `loop_guard_depth`, and actions that mutate the same entity type the
  workflow triggers on.
- **Secrets**: which names it references, and whether any live in an approval
  `title`/`instructions` (that is an outright save error, `APPROVAL_SECRET_IN_PROMPT`).

## Reference

The sibling `workflow-authoring` skill carries `reference/definition-format.md` (step types,
edges, validation codes) and `reference/api-surface.md` (routes, ACLs, CLI); both apply here
unchanged. Project docs live in the source repository, not in a Magento install — read them
there (or on GitHub): `docs/04-definition-format.md`, `docs/09-scope-acl-observability.md`,
`docs/10-security.md`, `docs/15-operations.md`, `docs/21-ai-assisted-authoring.md`.
