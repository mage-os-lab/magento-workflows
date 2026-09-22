# Definition format — authoring reference

Derived from `spec/workflow-definition.schema.json` (schema 4) and
`spec/workflow-export.schema.json`. When this file and the schema disagree, the schema wins.

## The workflow record vs. the definition

A workflow is a record; the **definition** is one field of it.

| Field | Notes |
|---|---|
| `name` | 1–255 chars |
| `status` | `0` disabled · `1` enabled · `2` shadow · `3` suspended. **You only ever author `0` or `2`.** (`Api/Data/WorkflowInterface.php`) |
| `entity_type` | `sales_order`, `customer`, `quote`, `catalog_product`, … — enumerate via `GET /V1/workflows/meta/entity-types` |
| `trigger_type` | `event` \| `schedule` \| `manual` |
| `trigger_ref` | For `event`: the async event name (`sales.order.created`). Enumerate via `GET /V1/workflows/meta/triggers` |
| `conditions_serialized` | Root condition tree JSON string, or `null` (= always match) |
| `definition` | The step-graph JSON below, as a **string** over REST |
| `loop_guard_depth` | 0–10, default 1 |
| `aggregation` | Non-null = batch/digest workflow. Leave null unless explicitly asked |
| `fan_out` | `{"relation": "<code>", "cap": <n>}` — one event → N executions. Leave null unless explicitly asked |
| `website_ids` | Scope binding |

## Step graph

```json
{
  "schema": 4,
  "entry": "s1",
  "steps": {
    "s1": {"type": "action", "action": "order.add_comment",
           "config": {"comment": "Flagged ({{ trigger.grand_total|number:2 }})"},
           "next": "s2"},
    "s2": {"type": "delay", "config": {"duration": "PT1H"}, "next": "s3"},
    "s3": {"type": "branch",
           "conditions_serialized": "{…}",
           "revalidate_entity": true,
           "on_true": "s4", "on_false": null},
    "s4": {"type": "stop"}
  }
}
```

- Required top level: `schema`, `steps`, `entry`. `additionalProperties: false` — the only
  other legal key is `ui` (non-semantic canvas layout, preserved verbatim, never
  evaluated). Do not invent top-level keys.
- Step keys match `^[a-zA-Z0-9_\-]{1,64}$`. `entry` is a step key, or `null` only when
  `steps` is empty.
- `schema` accepts 1–4 as input and normalizes to 4. **Always emit `4`.**
- Every step object is `additionalProperties: false`.

### Step types

| Type | Required | Optional | Edges |
|---|---|---|---|
| `action` | `type`, `action` | `config` | `next` |
| `delay` | `type`, `config.duration` | `config.business_days`, `config.at` | `next` |
| `branch` | `type` | `conditions_serialized`, `revalidate_entity` | `on_true`, `on_false` |
| `switch` | `type`, `cases[]` (≥1) | `revalidate_entity`, `default` | per-case `next`, plus `default` |
| `stop` | `type` | — | none (terminal) |
| `wait` | `type`, `config.event`, `config.timeout` | — | `on_event`, `on_timeout` |
| `approval` | `type`, `config.title`, `config.timeout` | `config.instructions`, `assignee_role`, `allow_bulk`, `payload_fields[]`, `notify_emails[]` | `on_approved`, `on_rejected`, `on_timeout` |

Every edge is a step key or `null` (`null` = the path ends there). All edges nullable —
"ends here" is expressed as `null`, never as a missing/dangling key.

**`action`** — `action` matches `^[a-z0-9_]+\.[a-z0-9_]+$` and must be a registered code
(`GET /V1/workflows/meta/actions`). It is static and **never interpolated**. Output merges
into context under `steps.<key>`.

**`delay`** — `config.duration` is ISO-8601 (`PT1H`, `P3D`, `P1W`). Absolute UTC arithmetic
unless `business_days: true` (day components count Mon–Fri in the store timezone) and/or
`at: "HH:MM"` (roll forward to the next store-local occurrence). Clamped at runtime to
`mageos_workflows/guards/max_delay_days` (default 365).

**`switch`** — cases evaluated top to bottom, first match wins; each case
`{key, conditions_serialized?, next?}` with a unique `key`. An empty/absent case condition
always matches (use it as a trailing catch-all, or use `default`). One shared
`revalidate_entity` for the whole step.

**`wait`** — parks until `config.event` fires **for the same entity** or `config.timeout`
elapses. Step output is `{resolution: "event"|"timeout", event: <payload>}`.

**`approval`** — human-decision gate. `timeout` is required (no indefinite parks). Output is
`{task_uuid}` at park, then `{resolution, note, payload, decided_by}`. Requires the optional
`mage-os/workflows-approvals` module to save, else `APPROVAL_MODULE_MISSING`.
`allow_bulk: true` is incompatible with any `required` entry in `payload_fields`.
`{{ secrets.* }}` in `title`/`instructions` is a hard error.

## Interpolation

Restricted, mustache-*style*, **not** `Magento\Framework\Filter\Template` — dot-path access
only, no directives, no method calls.

- Roots: `trigger.*`, `steps.*`, `workflow.*`, `secrets.*`.
- **Values only, never keys**, and never as an action code or an attribute code.
- Chainable whitelisted formatters: `upper`, `lower`, `trim`, `number[:decimals]`,
  `date[:'format']`, `default:'fallback'` — e.g. `{{ trigger.email|lower|trim }}`,
  `{{ trigger.grand_total|number:2 }}`. Unknown filters are silently ignored.
- Aggregated (batch) workflows add collection formatters over `trigger.items`: `count`,
  `pluck:'field'`, `join:', '`, `table:'f1,f2'`, `json`.
- Reserved trigger-payload roots: `origin` (fan-out provenance:
  `{event, entity_type, entity_id, trace_uuid, via}`) and the batch shape
  (`{batch, count, window, overflow, items}`).

Captured webhook responses (`capture_as`) are **attacker-influenceable data**: usable in
conditions and interpolated values, never as structure.

## Condition trees (`conditions_serialized`)

A JSON **string** in the salesrule-compatible serialized shape: a combine node
(`type`, `aggregator: all|any`, `value: "1"|"0"`, `conditions[]`) over leaf nodes
(`type`, `attribute`, `operator`, `value`). Node `type` is the condition class /
registered leaf code — copy the shapes in `spec/fixtures/*.json` rather than inventing
them, and prefer round-tripping an existing workflow's tree over writing one blind.

Available leaves come from the per-entity condition pool with **EAV auto-discovery**, so
custom attributes appear automatically. Notable capabilities (`docs/06-conditions.md`):

- Cross-entity `RelatedEntity/Combine` with `EXISTS` / `NOT EXISTS` and, for to-many
  relations, `ANY` / `ALL` / `NONE`. **`NOT EXISTS` with children is a hard save error**
  (`RELATION_NOT_EXISTS_WITH_CHILDREN`) — express the negation as `NONE` under `EXISTS`.
- Customer order-history aggregates (`orders_count`, `lifetime_sales`, `avg_order_value`,
  `last_order_at`, `days_since_last_order`) — absent values match only negative operators.
- Trigger Data leaves: any dot-path into the raw payload (`from_status`, `payment.method`,
  `items.0.sku`). Snapshot-only by design; unavailable after `revalidate_entity: true`.
- Relative dates: `'-30 days'`, resolved at evaluation time.

## Validation findings you will actually hit

Codes are stable; branch on them. Errors block the save, warnings travel with it.

| Code | Severity | Meaning |
|---|---|---|
| `DEFINITION_INVALID` | error | Malformed document / schema violation |
| `GRAPH_CYCLE` | error | A cycle reachable from `entry` — the engine has no loops |
| `GRAPH_UNREACHABLE_STEP` | warning | A step no path from `entry` reaches |
| `GRAPH_DEAD_EDGE` | warning | A `branch`/`switch` with every edge null |
| `GRAPH_POST_DELAY_STALE` | warning | `branch`/`switch` right after a `delay`/`approval` with `revalidate_entity: false` |
| `ACTION_UNKNOWN` | error | Unregistered or empty action code |
| `ACTION_UNAUTHORIZED` | error | Acting admin may not author that action (save path only) |
| `CONDITIONS_INVALID_JSON` | error | Condition tree is not a valid tree |
| `APPROVAL_MODULE_MISSING` | error | `approval` step without the approvals addon |
| `APPROVAL_BULK_REQUIRED_PAYLOAD` | error | `allow_bulk` + a required payload field |
| `APPROVAL_SECRET_IN_PROMPT` | error | `{{ secrets.* }}` in an approval title/instructions |
| `RELATION_NOT_EXISTS_WITH_CHILDREN` | error | `NOT EXISTS` relation node with child conditions |
| `FAN_OUT_*` | error | Malformed/unsupported fan-out (`MALFORMED`, `AGGREGATED_UNSUPPORTED`, `SCHEDULE_UNSUPPORTED`, `UNKNOWN_RELATION`, `SOURCE_MISMATCH`, `TARGET_MISMATCH`) |
| `PROFILE_*` | error | Batch/aggregated profile violations (`STEP_TYPE_FORBIDDEN`, `ACTION_FORBIDDEN`, `ACTION_NOT_BATCH_CAPABLE`, `CONDITION_NOT_IN_SNAPSHOT`, `REVALIDATE_FORBIDDEN`) |

Source: `src/module-workflows/Model/Validation/Check/*.php`.

## Export envelope

`bin/magento workflow:export <id>` emits / `workflow:import <file>` consumes:

```json
{"format": "mageos-workflow-export/1", "name": "…", "entity_type": "sales_order",
 "trigger_type": "event", "trigger_ref": "sales.order.created",
 "conditions_serialized": "…|null", "definition": { … }, "loop_guard_depth": 1}
```

`additionalProperties: false`; `definition` is the **object** here (not a string, unlike the
REST validate/dry-run payloads). Exports never contain secret values.
