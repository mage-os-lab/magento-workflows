# 04 — Definition Format

The `definition` JSON is **the contract everything shares**: the single artifact that the form UI, the future canvas, import/export, and the executor all read. It is the API boundary of the whole system — UIs are presentational layers over it.

## Example

```json
{
  "schema": 4,
  "steps": {
    "s1": {"type": "action", "action": "order.add_comment",
           "config": {"comment": "High-value order flagged ({{ trigger.grand_total }})"},
           "next": "s2"},
    "s2": {"type": "delay", "config": {"duration": "PT1H"}, "next": "s3"},
    "s3": {"type": "branch",
           "conditions_serialized": "...",
           "revalidate_entity": true,
           "on_true": "s4", "on_false": null},
    "s4": {"type": "action", "action": "notify.webhook",
           "config": {"url": "https://hooks.example/fraud", "capture_as": "fraud",
                      "timeout": 5, "sign_with": "{{ secrets.fraud_hmac }}"},
           "next": null}
  },
  "entry": "s1"
}
```

## Step types

| Type | Behavior |
|---|---|
| `action` | Executes a registered action (see [Action Framework](07-actions.md)); output merged into context under `steps.<key>` |
| `delay` | Suspends the execution; ISO-8601 duration, absolute UTC arithmetic (see [Risks — timezones](14-risks.md)). Two optional fields: `business_days: true` (day components count Mon–Fri in the store's timezone) and `at: "HH:MM"` (after the duration, roll forward to the next occurrence of that store-local time) |
| `branch` | Evaluates a condition tree; follows `on_true` / `on_false`; carries `revalidate_entity` (see [Conditions §Delay semantics](06-conditions.md#delay-semantics)) |
| `stop` | Completes the execution |
| `wait` | Parks the execution until `config.event` fires **for the same entity**, or until `config.timeout` (ISO-8601 duration) elapses; follows `on_event` / `on_timeout`. Step output is `{resolution: "event"\|"timeout", event: <payload>}` for downstream conditions and interpolation (see [Execution Model §Wait steps](08-execution-model.md#wait-steps-schema-2)) |
| `switch` | First-match-wins multi-way branch: `cases[]` (each with a unique `key`, an optional `conditions_serialized` tree in the same format `branch` uses, and a nullable `next` edge) evaluated top to bottom; the nullable `default` edge fires when no case matches. One shared `revalidate_entity` for the step (one hydration, evaluated N times). Step result records `{matched: <key>\|null}` |
| `approval` | A human-decision gate parked on the wait spine ([Approval Gate discovery](discovery/approval-gate.md)): `config.title` (required, interpolated at park time) and `config.timeout` (required ISO-8601 duration — no indefinite parks) drive the park; optional `config.instructions`, `config.assignee_role` (a Magento authorization role code), `config.allow_bulk` (default `false`), `config.payload_fields[]` (each `{key, label, type: string\|number\|boolean, required?}`, allowlisting the decision payload into context), and `config.notify_emails[]` (direct-email recipients notified at park time, in addition to the admin-inbox notice). Follows `on_approved` / `on_rejected` / `on_timeout`; step output is `{task_uuid}` at park and `{resolution, note, payload, decided_by}` after a decision (`{resolution: "timeout"}` on timeout). Requires the optional `mage-os/workflows-approvals` addon to author (see [Save-time validation](#save-time-validation)) |

`{{ ... }}` placeholders are resolved by the restricted variable resolver — dot-path access over `trigger.*`, `steps.*`, `workflow.*`, `secrets.*` only; no directive execution ([Actions §Variable resolution](07-actions.md#variable-resolution)). Values (never keys) may append whitelisted, chainable formatters — `{{ trigger.grand_total|number:2 }}`, `{{ trigger.email|lower|trim }}` — from a fixed list: `upper`, `lower`, `trim`, `number[:decimals]`, `date[:'format']`, `default:'fallback'`. Unknown filters are ignored. Aggregated (batch) workflows add collection formatters over `trigger.items` — `count`, `pluck:'field'`, `join:', '`, `table:'f1,f2'` (HTML-escaped cells), `json` (see [Batch aggregation](discovery/batch-aggregation.md#5-rendering-a-collection-actions--variables)).

## Trigger context conventions

The `context.trigger` bag is normally one entity's snapshot. Two reserved shapes carry provenance/collection metadata; their key names are fixed so features do not invent incompatible variants:

- **`origin`** (fan-out and event tracing) — `{event, entity_type, entity_id, trace_uuid, via: fan_out|…}`.
- **Batch trigger shape** (aggregated workflows, [batch aggregation](discovery/batch-aggregation.md)) — an aggregated workflow's single execution receives a collection instead of one entity:

  ```json
  "trigger": {
    "batch": true,
    "count": 143,
    "window": {"from": "2026-07-03T09:00:00-04:00", "to": "2026-07-04T09:00:00-04:00"},
    "overflow": false,
    "items": [ { "entity_id": 42, "sku": "ABC", "…": "…projected, capped snapshot…" }, … ]
  }
  ```

  `count` is always the true match count; `overflow` is `true` when `count` exceeds the item cap (default 500) so `items` is elided — never silently truncated. `items` entries are **projections** (identity fields + attributes named in the root conditions, or an explicit `projection` list), passed through the same `EntityDataConverter` shape as event snapshots so EAV attributes resolve identically. Batch executions carry `entity_id = 0` (there is no single entity); the execution grid renders "batch (N items)" rather than an entity link.

## Schema versions

There is exactly **one current schema version: 4**. The historical revisions (v2: `wait` + delay `business_days`/`at`; v3: `switch`; v4: `approval`) were purely additive — each differed only by which step types it gated, so every v1–v3 document was already a valid v4 document. No step type is version-gated anymore.

`schema` still accepts `1`, `2`, `3`, or `4` as *input*, but the parser (`Model/Definition/Definition.php`) normalizes legacy numbers to 4 immediately: `getSchemaVersion()` always returns 4 and serialization always emits `"schema": 4`, so stored legacy documents upgrade transparently on their next save — there is no separate migration. New documents should declare `"schema": 4`. Numbers above 4 are rejected as unsupported; the canvas renders documents declaring a newer-than-known schema read-only.

An optional top-level `ui` block (canvas layout persistence) is **non-semantic**: preserved verbatim through parse/serialize, never read by the engine, legal at any schema version. It is the only whitelisted non-semantic key — there is no general unknown-key passthrough.

Delay durations and wait timeouts are clamped at runtime to the `mageos_workflows/guards/max_delay_days` ceiling (default 365, with a logged warning when the clamp fires) — a fat-fingered `P1Y` cannot silently park an execution past the ceiling.

## Save-time validation

Parsing answers "is this well-formed?"; a separate validation pipeline answers "is this runnable?". Every authoring path — admin Save, REST save, CLI import, gallery install — funnels through it behind `WorkflowRepositoryInterface::save`; the executor never touches it (it re-parses stored `definition_snapshot`s directly, so any validation rule would otherwise be retroactive across parked executions — see [Execution Model §Static graph validation](08-execution-model.md#static-graph-validation)). Each finding carries a **stable machine code** (consumers may branch on it), a translated message, and an optional `step_key`/`edge` anchor. **Errors block the save; warnings travel with it.**

Graph findings (`GraphCheck`, DFS from `entry` over the edge helper):

| Code | Severity | Meaning |
|---|---|---|
| `GRAPH_CYCLE` | error | A cycle is reachable from `entry`. The engine has no loop semantics ([Overview §Non-goals](01-overview.md#non-goals-for-v1)); a cycle is always an authoring error |
| `GRAPH_UNREACHABLE_STEP` | warning | A step no path from `entry` can reach |
| `GRAPH_DEAD_EDGE` | warning | A `branch`/`switch` whose every edge is null (the form assembler can emit this as a last-row branch, so it stays re-savable) |
| `GRAPH_POST_DELAY_STALE` | warning | A `branch`/`switch` directly after a `delay` **or `approval`** with `revalidate_entity: false` — usually a mistake ([Conditions §Delay semantics](06-conditions.md#delay-semantics)). An approval gate can park for days, so a branch right after it evaluating the frozen trigger snapshot carries the same staleness hazard as a post-delay branch |

Trigger findings (`TRIGGER_REF_MISSING`, `TRIGGER_REF_UNKNOWN_EVENT`, `TRIGGER_REF_INVALID_CRON`) judge the workflow's `trigger_ref` — see [Triggers §`trigger_ref` is validated at save](05-triggers.md#trigger_ref-is-validated-at-save).

The pipeline also rejects unknown/empty action codes, re-authorizes every referenced action against the acting admin's ACL, and validates the condition-tree shape. **Compatibility bar:** `GraphCheck` never turns a currently-savable definition into an unsavable one — a genuine cycle (which the form assembler cannot produce) is the only new error on previously-valid input.

Approval-gate findings (`ApprovalCheck`, over every `approval`-typed step):

| Code | Severity | Meaning |
|---|---|---|
| `APPROVAL_MODULE_MISSING` | error | The definition uses an `approval` step but the optional `mage-os/workflows-approvals` addon is not installed (no task-manager delegate bound) — mirrors how an unregistered action code is rejected, so a step that would fail terminally at runtime never saves |
| `APPROVAL_BULK_REQUIRED_PAYLOAD` | error | `config.allow_bulk: true` combined with any `payload_fields` entry marked `required` — a bulk decision supplies one shared note and an empty payload, so it can never fill a per-task required value |
| `APPROVAL_SECRET_IN_PROMPT` | error | `{{ secrets.* }}` appears in `config.title` or `config.instructions` — these render in the approvals grid and emails, and a decision is not a secret channel |

The *same* pipeline runs read-only, without persisting, over an unsaved draft via **`POST /V1/workflows/validate`** (returns the findings plus a plain-language rendering; per-action ACL re-authorization is skipped — a validate call is not an authoring path). The workflow edit form's **plain-language preview + "Refresh preview"** panel calls this pipeline through a session-authenticated admin controller and renders the summary sentence with any warnings anchored to their step.

## Workflow-as-code

Agencies get first-class definition portability:

- `bin/magento workflow:export <id>` / `workflow:import <file>` emitting this JSON plus metadata
- Importable from a data patch
- Git-versionable, CI-deployable

This matters more to the actual buyers (agencies) than the canvas does.

## Import is untrusted input

Imported definitions are validated hard (full detail in [Security Model](10-security.md#import-is-untrusted-input)):

- Validate against the published JSON Schema
- Reject unknown action codes
- **Re-authorize against the importing admin's ACL** — an imported definition containing actions the importer can't author fails loudly
- The same check applies to programmatic creation via data patches (documented: patches run as system; agencies own that risk)
- Exports never contain secret values — definitions reference secrets by name only

## Trigger payload context conventions

Some shared keys can appear at the **root of the trigger payload** (i.e. in
`context.trigger`, addressable by a Trigger Data leaf as a bare dot-path — e.g.
`origin.event`, not `trigger.origin.event`). Reserving their names centrally
keeps features from inventing incompatible variants.

### `origin` — fan-out provenance

When a workflow declares a trigger-level **fan-out** clause
(see [Fan-out](discovery/fan-out.md)), one triggering event on a source entity
expands into N ordinary single-entity executions — one per member of a declared
relation. Each child's trigger payload carries an `origin` object describing why
the child exists:

```json
{
  "origin": {
    "event": "customer.group_changed",
    "entity_type": "customer",
    "entity_id": 7,
    "trace_uuid": "5f6c…",
    "via": "fan_out"
  }
}
```

| Key | Meaning |
|---|---|
| `event` | The async event name that caused the fan-out |
| `entity_type` / `entity_id` | The **source** entity the event fired on (the relation source, not the child's own entity) |
| `trace_uuid` | The async-events trace UUID of the causing event; omitted when the installed async-events version exposes no trace-UUID accessor. Also stamped onto the child's indexed `origin_uuid` column, powering the execution grid's "Caused by" filter |
| `via` | Always `fan_out` for trigger-level fan-out |

Conditions can gate on `origin.*` (`origin.event == customer.group_changed`) and
interpolation can reference it (`{{ trigger.origin.event }}`). **Caveat**
(inherited from Trigger Data's snapshot-only design): after a
`revalidate_entity: true` branch the freshly hydrated entity carries no
`origin`, so origin-based gating works at the root and pre-delay only.

## Published spec

The JSON Schema for this format, the `workflow_triggers.xml` XSD, and a conformance fixture set are published and semver'd deliverables (see [Overview §Strategy](01-overview.md#strategy-open-spec-commercial-layers)) under `spec/`, with revision history in `spec/CHANGELOG.md`.
