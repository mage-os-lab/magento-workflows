# Spec Changelog

Published deliverables: `workflow-definition.schema.json`, `workflow-export.schema.json`,
and the conformance fixtures under `fixtures/`. One semver event per definition-schema
revision; the engine (`Model/Definition/Definition.php`) and this spec move in lockstep.

## Definition schema 3

- **`switch` step type** — first-match-wins multi-way branch. `cases[]` entries carry a
  unique `key`, an optional `conditions_serialized` tree (same salesrule-compatible format
  the `branch` step uses; empty = always matches), and a nullable `next` edge. A nullable
  `default` edge fires when no case matches. One shared `revalidate_entity` flag for the
  step (one hydration, evaluated N times). The step result records `{matched: <key>|null}`.
  Documents using `switch` MUST declare `"schema": 3`; the parser rejects it below that.
- **Top-level `ui` block relaxation** — an optional, non-semantic object for canvas layout
  persistence. Preserved verbatim through parse/serialize, never read by the engine, legal
  at **any** schema version (it is non-semantic, so it needs no version gate). It is the
  only whitelisted non-semantic key; everything else remains `additionalProperties: false`.
- New conformance fixtures: `fixtures/multi-region-order-routing.json` (switch),
  `fixtures/canvas-ui-round-trip.json` (ui block).

**Migration note for third parties:** schema 1 and 2 documents remain valid unchanged.
Consumers that walk step edges must learn exactly one new shape: `switch` edges are
data-dependent (`cases[].next` + `default`), not derivable from the step type alone.
Consumers that re-serialize definitions must preserve the `ui` block byte-for-byte.

## Definition schema 2

- `wait` step type (park until an event fires for the same entity, with `on_event` /
  `on_timeout` edges and a `config.timeout` ceiling-clamped by the max-delay guard).
- Optional delay fields `business_days` and `at` (store-timezone semantics).
- Documents using any v2 feature MUST declare `"schema": 2` or later.

## Definition schema 1

- Initial published format: `action`, `delay`, `branch`, `stop` step types;
  `{{ trigger.* }}` / `{{ steps.* }}` / `{{ workflow.* }}` / `{{ secrets.* }}`
  placeholder interpolation with whitelisted formatters.

## Export envelope

- `mageos-workflow-export/1` — unchanged. Exports never contain secret values;
  definitions reference secrets by name only.
