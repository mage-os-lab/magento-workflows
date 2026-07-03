# 13 — Delivery Plan

| Phase | Scope | Effort (senior M2 eng) |
|---|---|---|
| **0 — Spike** | `WorkflowNotifier` E2E: subscription → dispatch → hardcoded condition → add-comment action. Validates the notifier seam and two-phase evaluation on a real payload | 2–3 wks |
| **1 — MVP** | Core engine (linear + delays), rule-model condition pool for order/customer/product, 12–15 core actions, form UI, execution logging, import/export CLI, loop guards, docs | 3.5–4.5 eng-months |
| **2 — Depth** | Scheduler + abandoned-cart trigger, branching in UI, webhook response capture, revalidation semantics, B2B pack, ES indexing of executions | 2–3 eng-months |
| **3 — Polish** | Canvas, template gallery, dry-run, connectors program | 2–3 eng-months |

**Phase 1 is a shippable, sellable product.**

## Post-review execution (July 2026)

The July 2026 state-and-outlook review produced a capability roadmap, since executed: waves 1–5 of [16 — Capability Roadmap](16-capability-roadmap.md) are implemented (wait step + delay upgrades, quote condition root + customer aggregates, action-library completion, stock-threshold publisher, variable formatters, REST API), with wave-6 test coverage in progress. Phase 3 (canvas, template gallery, dry-run UI, connectors program) and the B2B pack remain deferred as planned.

## Test strategy

- The engine is highly unit-testable — condition evaluation, the graph walker, and the variable resolver are pure-ish.
- Integration tests per action against the standard M2 integration framework.
- One E2E per trigger via the manual-run CLI ([Triggers §Manual](05-triggers.md#manual-triggers)).
- Reuse async-events' CI shape — it already runs integration + API-functional suites in GitHub Actions.

## Companion next steps

1. XSD for `workflow_triggers.xml`
2. JSON Schema for the [definition format](04-definition-format.md)
3. Phase-0 spike ticket breakdown
