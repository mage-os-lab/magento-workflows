# 04 — Definition Format

The `definition` JSON is **the contract everything shares**: the single artifact that the form UI, the future canvas, import/export, and the executor all read. It is the API boundary of the whole system — UIs are presentational layers over it.

## Example

```json
{
  "schema": 1,
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
| `delay` | Suspends the execution; ISO-8601 duration, absolute UTC arithmetic (see [Risks — timezones](14-risks.md)). Schema 2 adds two optional fields: `business_days: true` (day components count Mon–Fri in the store's timezone) and `at: "HH:MM"` (after the duration, roll forward to the next occurrence of that store-local time) |
| `branch` | Evaluates a condition tree; follows `on_true` / `on_false`; carries `revalidate_entity` (see [Conditions §Delay semantics](06-conditions.md#delay-semantics)) |
| `stop` | Completes the execution |
| `wait` | Schema 2. Parks the execution until `config.event` fires **for the same entity**, or until `config.timeout` (ISO-8601 duration) elapses; follows `on_event` / `on_timeout`. Step output is `{resolution: "event"\|"timeout", event: <payload>}` for downstream conditions and interpolation (see [Execution Model §Wait steps](08-execution-model.md#wait-steps-schema-2)) |
| `switch` | Schema 3. First-match-wins multi-way branch: `cases[]` (each with a unique `key`, an optional `conditions_serialized` tree in the same format `branch` uses, and a nullable `next` edge) evaluated top to bottom; the nullable `default` edge fires when no case matches. One shared `revalidate_entity` for the step (one hydration, evaluated N times). Step result records `{matched: <key>\|null}` |

`{{ ... }}` placeholders are resolved by the restricted variable resolver — dot-path access over `trigger.*`, `steps.*`, `workflow.*`, `secrets.*` only; no directive execution ([Actions §Variable resolution](07-actions.md#variable-resolution)). Values (never keys) may append whitelisted, chainable formatters — `{{ trigger.grand_total|number:2 }}`, `{{ trigger.email|lower|trim }}` — from a fixed list: `upper`, `lower`, `trim`, `number[:decimals]`, `date[:'format']`, `default:'fallback'`. Unknown filters are ignored.

## Schema versions

`schema` accepts `1`, `2`, or `3`. Version 2 adds exactly one step type (`wait`) and two optional delay fields (`business_days`, `at`); version 3 adds exactly one step type (`switch`). Nothing else changes at either bump, and older documents remain valid unchanged. The compat rule is enforced, not advisory: a document using any v2 feature **must** declare `"schema": 2` or later, and a document using `switch` **must** declare `"schema": 3` — the parser (`Model/Definition/Definition.php`) rejects them below the required version, as does the published JSON Schema.

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
| `GRAPH_POST_DELAY_STALE` | warning | A `branch`/`switch` directly after a `delay` with `revalidate_entity: false` — usually a mistake ([Conditions §Delay semantics](06-conditions.md#delay-semantics)) |

The pipeline also rejects unknown/empty action codes, re-authorizes every referenced action against the acting admin's ACL, and validates the condition-tree shape. **Compatibility bar:** `GraphCheck` never turns a currently-savable definition into an unsavable one — a genuine cycle (which the form assembler cannot produce) is the only new error on previously-valid input.

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

## Published spec

The JSON Schema for this format, the `workflow_triggers.xml` XSD, and a conformance fixture set are published and semver'd deliverables (see [Overview §Strategy](01-overview.md#strategy-open-spec-commercial-layers)) under `spec/`, with revision history in `spec/CHANGELOG.md`.
