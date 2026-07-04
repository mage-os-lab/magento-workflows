# Discovery — Phase 3, Branching & Capability Enhancements

Planning/evaluation documents for the deferred Phase-3 scope ([13 — Delivery Plan](../13-delivery-plan.md)),
branching capabilities, and a second track of capability enhancements that attack the structural
through-lines in [18 — Known Boundaries](../18-limitations.md). One document per feature; each
evaluates approaches and makes a specific recommendation, with architecture, quality,
maintainability, and reliability treated as first-class inputs.

## Track 1 — Phase 3 & branching

| Doc | Feature | Recommendation in one line | Est. |
|---|---|---|---|
| [branching.md](branching.md) | Branching capabilities | Save-time graph validation now; `switch` step via additive schema 3; parallel/join stays deferred with documented rationale; canvas is the branching UI | ~3–3.5 wk |
| [dry-run.md](dry-run.md) | Dry-run | Synchronous `DryRunService` reusing the production evaluator/resolver/simulate with compressed time and both-path wait exploration; CLI/REST/admin entry points; production executor untouched | ~4–5 wk |
| [template-gallery.md](template-gallery.md) | Template gallery | Template envelope layered on the export format; bundled starter pack behind a `TemplateSourceInterface` (signed remote feed deferred, architecture preserved); install = existing untrusted-import pipeline, never auto-enabled | ~6–7 wk |
| [canvas.md](canvas.md) | Canvas | React Flow in the optional `workflows-canvas` package, phased read-only-viewer-first; metadata/validate REST endpoints as a standalone prerequisite; rule widget stays the condition editor via a slide-out | ~11–14 wk |

## Track 2 — Capability enhancements (beyond Phase 3)

These relax three of the structural through-lines catalogued in
[18 — Known Boundaries](../18-limitations.md#the-five-structural-through-lines) — deliberately,
at the seams where the existing architecture already points.

| Doc | Feature | Recommendation in one line | Est. |
|---|---|---|---|
| [entity-cross-referencing.md](entity-cross-referencing.md) | Data hydrators / entity cross-referencing | DI-registered relation registry + one generic EXISTS/NOT-EXISTS related-entity condition reusing the existing traversal primitive; flagship: guest-order email → existing customer | ~3.5–4.5 wk |
| [fan-out.md](fan-out.md) | Fan-out (one trigger → N related executions) | Relation-driven dispatch expansion in front of the existing dispatcher — N ordinary single-entity executions with the full guard stack; mid-flow fan-out step as fast-follow; no joins | ~3 wk (+ registry) |
| [batch-aggregation.md](batch-aggregation.md) | Batch aggregation (N events → one digest execution) | Scheduler collected-mode first, event-window accumulator second; restricted definition profile validated at save; collection formatters for rendering | ~3–3.5 wk (B1), ~6–7 wk full |

Track-2 sequence: **cross-referencing → fan-out → batch aggregation** (fan-out consumes the
relation registry; batch aggregation shares only `GraphValidator` and can run in parallel with
fan-out). Fan-out and batch aggregation are inverses — N outputs from one input vs one output
from N inputs — and both keep the single-token executor untouched by living at the dispatch
layer.

## Recommended cross-feature sequence (Track 1)

The features share foundations; this order lets each ship something user-visible while feeding
the next:

1. **Branching foundations** — GraphValidator, `switch` (schema 3, including the canvas's `ui`
   layout block so the spec bumps once), plain-language upgrades. Everything downstream assumes
   validated graphs.
2. **Dry-run** — engine-side, no new frontend; adds the metadata the gallery and canvas both
   want to surface ("what would this do?").
3. **Template gallery** — reuses import + dry-run; its intended UX is install → dry-run → enable.
4. **Canvas** — the largest item, consuming the metadata/validate/dry-run endpoints; its Phase A
   (read-only viewer with execution/dry-run overlays) is independently shippable if Phase B
   editing slips.

Track 2 is independent of Track 1 except for `GraphValidator` (branching item 1) and dry-run's
trace extensions; it can interleave — the natural slot for cross-referencing (pure
condition-layer work) is alongside the canvas's frontend-heavy phases, where it competes for
different skills.

Shared threads to hold across all four: the definition JSON remains the single contract
([04](../04-definition-format.md)) and every new surface (validator, switch, `ui` block, template
envelope) lands in the published `spec/` schemas with conformance fixtures; the server remains
the sole authority for validation and ACL; nothing auto-enables or mutates without the existing
save/import gauntlet.

These documents are discovery input, not committed scope — estimates are senior-M2-engineer
figures in the same currency as [13 — Delivery Plan](../13-delivery-plan.md).
