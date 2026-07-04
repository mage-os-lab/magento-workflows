# 00 — Foundations (Shared Substrate)

The eight pieces more than one feature needs. Building these as shared infrastructure — rather
than letting each feature grow a private variant — is what makes the seven plans compose.

<a name="delivery-rule"></a>
**Delivery rule:** foundations are *not* a big-bang stage. Each F-item lands with its **first
consumer** (noted per item) so nothing ships speculative and unexercised — but its *shape* is
agreed here, up front, so the second consumer extends instead of refactors.

## F1 — Definition & spec release (schema 3)

*First consumer: [01 — Branching](01-branching.md). Also serves: dry-run, canvas, every renderer.*

One coordinated revision of the format contract, in `module-workflows` + `spec/`:

| Piece | Intent |
|---|---|
| `SCHEMA_VERSIONS = [1, 2, 3]`; `switch` step parsing + per-type validation in `Definition` | The one new semantic feature of schema 3 ([branching §3](../branching.md)) |
| Optional top-level `ui` block: **preserved verbatim** through `fromArray()`/`toArray()`, whitelisted (no general unknown-key passthrough), never read by the engine | Canvas layout persistence ([canvas §5](../canvas.md)); non-semantic, so legal at any schema version. Ships in 01 stage 4 with the spec release, exercised immediately by the ui round-trip fixture; canvas (07) is its first *product* consumer |
| **Edge-routing helper** on `Definition` — pinned signature: `getStepEdges(string $stepKey): array<string, ?string>` returning the step's **full declared edge map read from step data** (action/delay → `['next'=>…]`; branch → `['on_true'=>…, 'on_false'=>…]`; wait → `['on_event'=>…, 'on_timeout'=>…]`; switch → one `case:<key>` entry per case plus `'default'` — switch edges are data-dependent, not derivable from the type alone). **Topology only**: runtime *selection* (which edge to follow) stays in each consumer; `runSwitchStep` reads `cases[]` (targets + conditions) directly | The single source of "what edges does this step have". Consumers: the `Executor` `run*Step` methods' edge returns, `ResumeConsumer` (the only runtime follower of `on_event`/`on_timeout` — do not miss it), `GraphCheck`, `DryRunService` fan-out, `PlainLanguageRenderer`, canvas mapping layer. Today this knowledge is duplicated across at least four sites (`Executor` run-methods, `PlainLanguageRenderer`, `ResumeConsumer`, and `Definition`'s flat edge list); every new step type means hand-synchronized edits — this helper collapses that to one |
| `spec/workflow-definition.schema.json` v3 (switch schema, `ui` relaxation, everything else stays `additionalProperties:false`) + fixtures (switch routing, ui round-trip) + a `spec/CHANGELOG` | Third parties get one semver event, one migration note |

Deliberately **not** in F1: any executor behavior change beyond `runSwitchStep` (which rides
[01](01-branching.md)); any general extensibility of step types (the format stays closed —
versioned evolution, not plugins).

## F2 — Save-time validation pipeline

*First consumer: [01 — Branching](01-branching.md) (GraphValidator). Extended by: batch profile
checks, fan-out type-alignment, gallery install.*

Today validation is scattered — and one path has **none**: structural checks live in
`Definition::fromArray`, ACL re-auth and the conditions-shape check in the Save controller,
envelope/action checks in `ImportCommand`, nothing topological anywhere, and **REST save
(`POST/PUT /V1/workflows` → `WorkflowRepositoryInterface::save`) performs zero validation** —
not even JSON parsing, and critically no per-action ACL (`authorizeActionCodes` exists only on
the admin controller path). Wiring F2 into REST is therefore a **deliberate tightening, not a
consolidation**: it closes a real per-action-ACL bypass and will start rejecting previously
accepted invalid payloads. Document it as a breaking change for API clients and add contract
tests (invalid definition → 400; unauthorized action code → 403).

```
Model/Validation/
  WorkflowValidator          orchestrator: validate(Definition $d, ValidationContext $ctx)
  ValidationContext          carries auth mode (ADMIN_CONTEXT | SYSTEM), workflow kind, dry-run flag
  ValidationResult           list of ValidationMessage
  ValidationMessage          {severity: error|warning, code: string (stable machine code),
                              message: string (__()'d), target?: {step_key?: string, edge?: string}}
                             — target.step_key is REQUIRED where applicable: the canvas pins
                             messages to nodes and the form anchors them to steps
  Check/ (pool, di.xml-registered, ordered)
    StructuralCheck          wraps Definition::fromJson (parse errors become typed results)
    GraphCheck               cycles/entry = errors; unreachable/dead-edge/post-delay-stale = warnings
                             (codes: GRAPH_CYCLE, GRAPH_UNREACHABLE_STEP, GRAPH_DEAD_EDGE,
                              GRAPH_POST_DELAY_STALE)
    ProfileCheck             step-type + action allowlist per workflow kind (standard vs aggregated —
                             added by 05; inert until then)
    ActionCodesCheck         unknown action codes = error (always runs, every context)
    ActionAuthorizationCheck per-action ACL (relocated from Save); no-ops when
                             ctx auth mode = SYSTEM or ctx is a dry-run (not an authoring path)
    ConditionsShapeCheck     the existing shallow conditions_serialized JSON check
```

**Wiring decision (made here, not at implementation): a `before`-plugin on
`WorkflowRepository::save`.** Every authoring path funnels through the repository (admin Save,
REST, CLI import, future gallery), and the executor never calls it (it parses
`definition_snapshot` directly) — so the plugin is the single chokepoint that structurally
cannot leak into the retroactivity trap ([branching §2](../branching.md)). Required guard: the
plugin validates **only when definition or conditions changed** (reuse the repository's existing
`isDefinitionChanged` idiom) — status-only saves (mass enable/disable) must not re-validate a
stored definition, or disabling a workflow whose action module was uninstalled becomes
impossible. Warnings travel: form messages in admin, an extension attribute on REST save
responses, console output on CLI import.

## F3 — WorkflowImporter (one import path)

*First consumer: refactor of `workflow:import` + admin Save convergence. Extended by: template
gallery.*

`Model/Import/WorkflowImporter` in `module-workflows`: envelope parse (`mageos-workflow-export/1`),
F2 pipeline, then persistence with an explicit **authorization mode** — `ADMIN_CONTEXT` (runs
`ActionAuthorizationCheck` against the current admin; used by Save-adjacent flows and gallery)
vs `SYSTEM` (skips it with the existing loud warning; used by CLI + data patches). Creates
disabled by default, `shadow`/`--activate` as options. `ImportCommand` becomes a thin shell;
`ExportCommand` is already fine and stays.

## F4 — AttributeClassifier wiring

*First consumers: [05 — Batch](05-batch-aggregation.md) (membership constraint) and
[02 — Cross-referencing](02-entity-cross-referencing.md) (node-type awareness).*

`Model/Rule/AttributeClassifier` exists, is correct for leaf attributes, and has **zero
production callers**. Two moves:

1. **Wire it** as a service consulted by F2's `ProfileCheck` (batch: "root conditions must be
   fully in-snapshot") and available to UI/REST metadata ("this condition will require a DB
   lookup at run time" hints — cheap, high merchant value, optional).
2. **Extend it with node-type awareness**: classification consults a small registry of
   condition-node types that force `needs_hydration` regardless of attributes (first entry:
   `RelatedEntity` combine). This is the gap the adversarial review exposed — a childless
   NOT-EXISTS relation node references zero attributes and would otherwise classify zero-query.

Snapshot-shape source of truth (which attributes a trigger payload carries) comes from the
trigger's declared resolver/service class; the classifier API takes it as input rather than
discovering it — keeps the class pure and shim-testable.

## F5 — Relation registry

*First consumer: [02 — Cross-referencing](02-entity-cross-referencing.md). Extended by:
[04 — Fan-out](04-fan-out.md), F6 metadata endpoints.*

```
Api/RelationInterface            code, label, source/target entity types, cardinality, resolveIds()
Model/Relation/RelationPool      di.xml type-array (mirror: Model/Action/ActionPool.php — this IS
                                 the extension surface)
Model/Relation/RelationContext   the ONLY entry point feature code may call:
                                   resolve(string $relationCode, DataObject $source): int[]
                                     — memoizes on (relation, source entity_id), skips caching when
                                       id <= 0, applies website scoping, catches resolver exceptions
                                       (fail-toward-false + log)
                                   resolveWebsiteId(DataObject $source): ?int
                                     — source-entity-derived (store_id column), honors
                                       customer/account_share/scope; null = indeterminable
                                   isFresh(): bool — threads revalidate_entity
Model/Relation/Resolver/*        seed resolvers (see 02)
```

**Invariant:** all resolution goes through `RelationContext::resolve()`;
`RelationInterface::resolveIds()` is context-internal and must never be called by feature code
(a consumer bypassing the context silently loses memoization, the storeless fail-toward-false
rule, and website scoping — the exact guarantees this design centralizes). Both consumers —
02's `RelatedEntity` combine and 04's `FanOutExpander` — bind to the context.

## F6 — REST metadata + validate endpoints; PlainLanguageRenderer relocation

*First consumers: [01](01-branching.md) (plain-language on validate) and CI linting; primary
customer: [07 — Canvas](07-canvas.md).*

- **Relocate `PlainLanguageRenderer` from `module-workflows-admin-ui` to `module-workflows`**
  (admin-ui keeps its grid column as a thin consumer). Reason: the validate endpoint, dry-run
  traces, and gallery previews live in core/webapi and must not depend on the admin-ui module.
  Same stage extends it: both branch edges, switch cases, wait steps (currently unrendered),
  batch phrasing later.
- New webapi routes in `module-workflows`:
  `GET /V1/workflows/meta/actions|triggers|entity-types|relations`, `GET …/meta/secrets`
  (names only), `POST /V1/workflows/validate` (runs F2, returns the `ValidationResult` messages +
  plain-language rendering). ACL: meta under `::view`, validate under `::manage`.
- **Each endpoint has one owning stage** (an endpoint referenced by a plan but built by no stage
  is how contracts drift): `validate` → 01 stage 2; `meta/relations` → 02 stage 4;
  `meta/actions|triggers|entity-types|secrets` + the options mechanism below → 07 stage 1
  (pull earlier if 06 stage 4 lands first — first consumer builds it).
- **Option-source decision (recorded here, not deferred):** a `getConfigForm()` select field
  either carries inline options — `options: [{value, label}]`, for sources the provider declares
  bounded (guideline ≤ ~200 entries: order statuses, customer groups, entity types) — or a
  search reference: `options_search: {source: "<code>", min_chars: 2}` resolved via a new
  `GET /V1/workflows/meta/options?source=<code>&q=…` endpoint backed by a DI-registered
  option-source pool (large sources: cart price rules, email templates, products). Gallery
  parameter fields (`entity:*`, `select`) and canvas config panels both render from this one
  union shape. SPI impact is additive: field defs gain the optional `options`/`options_search`
  keys; no `ActionMetadataInterface` method change.

## F7 — Simulation substrate

*First consumer: [03 — Dry-run](03-dry-run.md). Offered to: shadow mode.*

`Model/Secrets/RedactingSecretsProvider` — a decorator over `SecretsProviderInterface` returning
`***<name>***`. The production `VariableResolver` already takes its provider by DI, so the work
is a named virtualType (e.g. `VariableResolverForDryRun`) binding the decorator — never a swap
of the shared production instance. A system-config toggle
(`mageos_workflows/simulation/redact_shadow_secrets`, default 0) offers the same decorator to
shadow-mode executions (behavior change for existing shadow users; flip the default at the next
minor). SDK docs gain the contract line: `simulate()` performs no I/O beyond entity reads.

**Config-path convention** (all new settings follow the existing `mageos_workflows/<group>/<field>`
pattern, declared in `etc/config.xml` + `etc/adminhtml/system.xml`): the paths reserved by these
plans are `mageos_workflows/simulation/redact_shadow_secrets` (F7),
`mageos_workflows/dry_run/persist` + `mageos_workflows/dry_run/retention_days` (03 — a separate
knob from the existing `mageos_workflows/retention/days`, applied by extending the existing
`PruneExecutions` cron), `mageos_workflows/guards/relation_cap` (02, default 100), and
`mageos_workflows/guards/fan_out_cap` (04, default 100 — the global ceiling per-workflow caps
clamp to).

## F8 — DB evolution map

*Reserved here; each column/table ships in its owning feature's stage.* Purpose: one naming/shape
decision so features don't collide or churn `db_schema.xml` twice.

| Object | Owner | Shape intent |
|---|---|---|
| `mageos_workflow.fan_out` | 04 | nullable JSON: `{relation, cap}`; null = no fan-out |
| `mageos_workflow.aggregation` | 05 | nullable JSON: window policy, caps, projection fields, `aggregate_suppressed_events`; null = per-entity workflow (workflow "kind" is derived from this being non-null — no enum column) |
| `mageos_workflow_execution.mode` | 03 | varchar/enum `live|dry_run`, default `live`; **not** a side-effect predicate (documented) |
| `mageos_workflow_execution.origin_uuid` | 04 | nullable char(36) + index; async-events trace UUID of the causing event |
| `mageos_workflow_batch`, `mageos_workflow_batch_item` | 05 | per [batch §2](../batch-aggregation.md); unique `(workflow_id, window_key)` / `(batch_id, entity_id)` |
| `mageos_workflow_template_install` | 06 | provenance: workflow_id, template code+version, params snapshot, installed_by/at |
| `entity_id = 0` sentinel on executions | 05 | documented convention, no schema change |

Shared context conventions (documented in [04 — Definition Format](../../04-definition-format.md)
when the first owner ships): the `origin` key in a trigger payload
(`{event, entity_type, entity_id, trace_uuid, via: fan_out|…}`) and the batch trigger shape
(`{batch: true, count, window, overflow, items[]}`). Reserving the *names* now prevents two
features inventing incompatible `origin`s.

## What is deliberately not a foundation

- **No generic "workflow kind" plugin system** — exactly two kinds (standard, aggregated),
  expressed as the presence of the `aggregation` config; a third kind earns the abstraction.
- **No event-sourcing / no executor rework** — every plan keeps the single-token, crash-safe
  walker untouched; foundations only add seams *around* it.
- **No shared frontend framework decision beyond the canvas package** — admin-ui stays
  server-rendered; the canvas's React toolchain is quarantined in its own module
  ([07](07-canvas.md)).
