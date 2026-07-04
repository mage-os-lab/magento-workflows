# Implementation Plans — Index & Build Order

High-level implementation plans for the features evaluated in [docs/discovery/](../README.md).
These describe **structure and intent** — components, seams, data changes, staging — not
method-level design; that is deliberately left to the implementing engineer/agent per stage.

The plans are ordered **bottom-up**: [00 — Foundations](00-foundations.md) is the shared
substrate every feature builds on, extracted so the seven features compose with each other and
with the shipped engine instead of each carving its own seams.

## Dependency graph

```
00 Foundations
 ├─ F1 Definition/spec release (schema 3: switch · ui block · edge helper)
 ├─ F2 Save-time validation pipeline (GraphValidator + check pool)
 ├─ F3 WorkflowImporter (shared import path)
 ├─ F4 AttributeClassifier wiring (+ node-type awareness)
 ├─ F5 Relation registry (RelationInterface/Pool/Context)
 ├─ F6 REST metadata + validate endpoints (+ PlainLanguageRenderer → core)
 ├─ F7 Simulation substrate (redacting secrets decorator)
 └─ F8 DB evolution map (columns/tables reserved once)

01 Branching        ← F1, F2, F6(plain language)
02 Cross-referencing← F4, F5
03 Dry-run          ← F1(edge helper), F2, F6, F7
04 Fan-out          ← F5, F8(origin_uuid, fan_out col); extends 03's traces
05 Batch aggregation← F2(profile checks), F4, F8(batch tables); extends 03
06 Template gallery ← F2, F3, F6(plain language); wants 03 for install→dry-run→enable
07 Canvas           ← F1, F2, F6; renders 01's switch, launches 03, overlays executions
```

## Build order

| Stage | Contents | Unblocks |
|---|---|---|
| 0 | Foundations F1–F8 (incrementally — each F-item ships with its first consumer, see [00](00-foundations.md#delivery-rule)) | everything |
| 1 | [01 — Branching](01-branching.md) (GraphValidator live, switch, plain-language upgrades) | 03, 05, 07 |
| 2 | [02 — Cross-referencing](02-entity-cross-referencing.md) · [03 — Dry-run](03-dry-run.md) (parallel; disjoint layers) | 04, 06 |
| 3 | [04 — Fan-out](04-fan-out.md) · [05 — Batch aggregation](05-batch-aggregation.md) (parallel; both dispatch-layer, disjoint code) | — |
| 4 | [06 — Template gallery](06-template-gallery.md) | — |
| 5 | [07 — Canvas](07-canvas.md) (Phase A viewer, then Phase B editor) | — |

Stages 2–5 can overlap where teams allow; the hard edges are only the ones in the graph above.

## Conventions (all plans assume these)

- **PR-sized stages.** Every plan's "Stages" table is ordered so each row is independently
  mergeable and leaves `main` shippable. A stage = 1–3 PRs.
- **Spec lockstep.** Any change to the definition format lands in the same PR as its
  `spec/workflow-definition.schema.json` update and a conformance fixture. The published spec
  never lags the engine.
- **Tests ride the shim harness.** Unit suites under `Test/Unit` per module, runnable without
  Magento via `dev/tests/shims/` — the existing wave-6 pattern. Integration-shaped tests are
  written but gated on the live-install milestone (unchanged GA gate, [16](../../16-capability-roadmap.md)).
- **Flags default-off.** New runtime behavior (fan-out, aggregation, remote anything) ships
  behind per-workflow config or system config defaulting to today's behavior. Pure additions
  (new step type, new condition, new endpoints) need no flag — inert until authored into a
  definition.
- **Docs updated in-stage.** The affected `docs/NN-*.md` pages update in the stage that changes
  behavior, not in a cleanup pass.
