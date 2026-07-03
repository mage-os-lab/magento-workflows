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

`{{ ... }}` placeholders are resolved by the restricted variable resolver — dot-path access over `trigger.*`, `steps.*`, `workflow.*`, `secrets.*` only; no directive execution ([Actions §Variable resolution](07-actions.md#variable-resolution)). Values (never keys) may append whitelisted, chainable formatters — `{{ trigger.grand_total|number:2 }}`, `{{ trigger.email|lower|trim }}` — from a fixed list: `upper`, `lower`, `trim`, `number[:decimals]`, `date[:'format']`, `default:'fallback'`. Unknown filters are ignored.

## Schema versions

`schema` accepts `1` or `2`. Version 2 adds exactly one step type (`wait`) and two optional delay fields (`business_days`, `at`); nothing else changes, and v1 documents remain valid unchanged. The compat rule is enforced, not advisory: a document using any v2 feature **must** declare `"schema": 2` — the parser (`Model/Definition/Definition.php`) rejects wait steps and delay extras in a schema-1 document, as does the published JSON Schema.

Delay durations and wait timeouts are clamped at runtime to the `mageos_workflows/guards/max_delay_days` ceiling (default 365, with a logged warning when the clamp fires) — a fat-fingered `P1Y` cannot silently park an execution past the ceiling.

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

The JSON Schema for this format, the `workflow_triggers.xml` XSD, and a conformance fixture set are published and semver'd deliverables (see [Overview §Strategy](01-overview.md#strategy-open-spec-commercial-layers)). Authoring the schema is a companion next step alongside the Phase-0 spike.
