# Discovery — Branching Capabilities

**Status:** Discovery / evaluation · **Feeds:** Phase 3 planning ([13 — Delivery Plan](../13-delivery-plan.md)) · **Implementation plan:** [implementation/01-branching.md](implementation/01-branching.md)
**Related:** [04 — Definition Format](../04-definition-format.md) · [06 — Conditions](../06-conditions.md) · [08 — Execution Model](../08-execution-model.md) · [18 — Known Boundaries](../18-limitations.md) · [canvas.md](canvas.md) · [dry-run.md](dry-run.md)

---

## 1. Where branching stands today

The engine has supported branching **at the definition level since day one**; what's missing is
richer branch *shapes*, static graph *safety*, and any *authoring surface* beyond raw JSON.

What exists (verified against source):

| Capability | State | Where |
|---|---|---|
| Binary branch step (`on_true` / `on_false`, own condition tree, `revalidate_entity`) | ✅ Engine | `src/module-workflows/Model/Engine/Executor.php` (`runBranchStep`, ~407–441) |
| Two-outcome `wait` step (`on_event` / `on_timeout`) — covers "racing timers" | ✅ Engine (schema 2) | `Executor.php` (`runWaitStep`), [08 §Wait steps](../08-execution-model.md#wait-steps-schema-2) |
| Edge validation (dangling refs rejected) | ✅ Parse time | `src/module-workflows/Model/Definition/Definition.php` (`fromArray`, ~78–85) |
| Cycle detection / reachability analysis | ❌ None — only a runtime `MAX_STEPS_PER_RUN = 1000` cap | `Executor.php:39–41, 150–158` |
| Multi-way branch (switch / case) | ❌ Must be modeled as a chain of binary branches | — |
| Parallel split / join | ❌ Single-token walker (`current_step` is one column) | `Executor.php` (`walk`), [18 §Flow control](../18-limitations.md#flow-control--orchestration) |
| Branch authoring UI | ❌ Raw JSON textarea, or *degenerate* branches via the dynamicRows assembler (`buildDefinitionFromRows` emits branch rows with `on_false` hardwired to null and `on_true` always the next row — it cannot express a real fork, and a branch as the last row yields the both-null dead end §2 lints) | `src/module-workflows-admin-ui/Controller/Adminhtml/Workflow/Save.php:166–211`, `view/adminhtml/ui_component/mageos_workflows_form.xml` |
| Branch condition authoring | ❌ Serialized JSON pasted inline (`conditions_serialized` on the step) | — |

So "add branching" decomposes into four independent workstreams, evaluated separately below:

1. **Graph safety** — static validation (cycles, reachability, dead edges) at save time.
2. **Branch shapes** — multi-way `switch`; a hard look at parallel split/join.
3. **Branch semantics** — what a branch condition can see (already solid; small gaps noted).
4. **Authoring** — how a merchant builds a branch (form era vs canvas era; see [canvas.md](canvas.md)).

## 2. Workstream A — Static graph validation

### Problem

`Definition::fromArray()` validates edges point at *existing* steps but nothing else. Today a
definition can ship with: cycles (caught only at step 1000, at runtime, per execution), steps
unreachable from `entry`, branches whose two edges point at the same step, or a graph whose
only path is `on_false: null` (silent no-op). With a canvas and a template gallery about to
*generate* definitions programmatically, save-time validation stops being nice-to-have and
becomes the contract that protects the executor.

### Approaches

| | A1 — Validate in `Definition::fromArray()` | A2 — Separate `DefinitionValidator` service (recommended) | A3 — Lint-only (warnings, never block) |
|---|---|---|---|
| Shape | DFS inside the parser | Parser stays structural; a `Model/Definition/GraphValidator` runs DFS + reachability, called by Save controller, REST save, import, gallery install | Validator exists but only ever warns |
| Pros | One gate, impossible to bypass | Separates "is this parseable" from "is this runnable"; callers choose severity; executor can keep parsing old stored definitions that predate the rule | Nothing breaks retroactively |
| Cons | Executor parses stored `definition_snapshot`s — a mid-flight execution whose snapshot has a now-forbidden shape would fail to *load*, which is a correctness regression | Four-and-growing call sites to keep wired (Save controller, REST save, CLI import, gallery install) | Cycles stay a runtime failure; template/canvas ecosystems inherit the ambiguity |

**Recommendation: A2.** The decisive argument is the snapshot problem: `Executor::execute()`
re-parses `definition_snapshot` on every resume, so parse-time rules are retroactive across all
parked executions. Validation policy must live *outside* the parser. Severity split:

- **Errors (block save/import):** cycles; `entry` unreachable of itself (trivially true) — more precisely, any cycle reachable from `entry`; branch/switch with *all* edges null **and** no conditions (pure dead end is fine — `stop` exists — but flag it); wait step whose `on_event` and `on_timeout` are both null.
- **Warnings (surface in UI + CLI, don't block):** steps unreachable from `entry`; `on_true`/`on_false` pointing at the same step; branch directly after a delay with `revalidate_entity: false` (probably a mistake, per [06 §Delay semantics](../06-conditions.md#delay-semantics)).

Cycles are **errors**, not warnings: the engine has no loop semantics (explicit non-goal,
[01 §Non-goals](../01-overview.md#non-goals-for-v1)), so a cycle is always authoring error, and
the runtime cap turns it into ~1000 iterations of DB churn per execution (step rows are keyed
`(execution_id, step_key)` and re-UPDATEd each revisit, plus a context persist + execution save
per iteration) before `failExecution`.

Cost: small (a DFS over ≤ a few hundred nodes), pure, highly unit-testable. Ship first — the
canvas, dry-run, and gallery all lean on it.

## 3. Workstream B — Branch shapes

### B1 — Multi-way branch (`switch`)

The most common real gap. "Route by shipping country" today is a chain of binary branches:
N countries = N branch steps, each with a full condition tree, visually a diagonal ladder. Every
comparable product (Shopify Flow, AutomateWoo, n8n) has first-match-wins multi-way.

**Option S1 — UI sugar only.** Keep the engine binary; let the canvas render a branch *chain* as
one visual switch node. No schema change.
*Pros:* zero engine cost. *Cons:* the sugar leaks everywhere else — export, REST, plain-language
rendering, dry-run traces all still show N chained steps; round-tripping a hand-edited chain back
into "one switch" is heuristic and fragile. The definition format is the published contract — if
the concept exists, it should exist *in the format*, not in one client's imagination.

**Option S2 — `switch` step type, schema 3 (recommended).**

```json
{
  "type": "switch",
  "cases": [
    {"key": "us",  "conditions_serialized": "…", "next": "us_flow"},
    {"key": "eu",  "conditions_serialized": "…", "next": "eu_flow"}
  ],
  "revalidate_entity": true,
  "default": "everywhere_else"
}
```

- First-match-wins, top to bottom; `default` edge (nullable) when no case matches.
- Each case reuses the existing serialized condition-tree format — same evaluator
  (`ConditionEvaluator::evaluateSerialized`), same rule-widget story later, no new condition
  machinery.
- One shared `revalidate_entity` for the step (one hydration, evaluated N times — cheap; per-case
  revalidation flags would be semantically confusing and cost N hydrations).
- Step result records `{matched: "eu"}` — feeds execution timeline, dry-run traces, plain language.
- Executor cost: one new `case` in `walk()` + a `runSwitchStep()` that loops
  `evaluateSerialized` until true. Genuinely small.
- Schema bump to 3 following the exact precedent wave 3 set for schema 2
  ([04 §Schema versions](../04-definition-format.md#schema-versions)): additive, v1/v2 docs stay
  valid, parser rejects `switch` in documents declaring `schema < 3`, JSON Schema published in
  `spec/` with a conformance fixture.

**Option S3 — value-match switch** (`switch on {{ trigger.country_id }}`, cases are literals).
Simpler to author than N condition trees, but it introduces a *second* comparison system next to
the rule-model conditions (operators? type coercion? EAV awareness?), which violates the "one
condition engine" architecture decision. Rejected; a one-attribute condition tree per case gives
the same power with the machinery that already exists — and the UI can offer a "same attribute,
different values" fast path that *generates* those trees.

**Recommendation: S2.** Ship the `switch` step in the engine *before* the canvas, so the canvas
launches rendering real multi-way nodes instead of retrofitting them.

### B2 — Parallel split / join

Listed in [18 — Known Boundaries](../18-limitations.md#flow-control--orchestration) ("run steps
in parallel and join"). Honest evaluation:

What true parallelism would require:

- Multiple concurrent tokens per execution → `current_step` (one column) becomes a token table;
  every crash-safety guarantee in [08](../08-execution-model.md) re-derived per token.
- A `join` step with barrier semantics → atomic "last-arriver proceeds" claim (same class of race
  the wait-step wake already solves, but now N-way), timeout policy for a branch that never
  arrives, partial-failure policy (one branch failed — does the join fail? proceed degraded?).
- Context merge rules for `steps.*` written concurrently on two paths.
- Execution timeline UI, dry-run, and plain-language rendering all become DAG-shaped.

What it would buy: almost nothing for this engine's actual workload. Actions are short
(100–300 ms order saves) and executions are already parallel *across* entities via queue
consumers — intra-execution concurrency saves wall-clock time only when steps *wait*, and the
two waiting shapes that matter are already covered: racing an event against a timer is the
schema-2 `wait` step, and "do A and B and C" sequentially completes within a second anyway.
The remaining genuine use case ("wait for X *or* Y across different entities") fails on the
join/token problem *and* on the wait-step entity-scoping design, and is better attacked later as
a wait-step generalization than as general fork/join.

**Recommendation: keep deferred, now with documented rationale** (this section). Revisit only if
a concrete merchant scenario appears that sequential execution + `wait` cannot express. This is
the same "strategy decision, not bug fix" posture [18](../18-limitations.md#how-to-read-this-against-the-roadmap)
takes for structural limits. A cheap partial substitute if demand appears: allow *sequential
fan-out sugar* in the UI (one trigger, several independent linear tails executed in sequence) —
expressible today as consecutive steps, needs no engine change.

### B3 — Loops / iteration

Explicit non-goal ([01](../01-overview.md#non-goals-for-v1)); nothing in Phase 3 changes the
calculus. Cycles stay save-time errors (§2). Not pursued.

## 4. Workstream C — Branch semantics (small gaps)

- **Both-null lint** — a `branch` with `on_true: null, on_false: null` is legal and meaningless;
  becomes a §2 warning.
- **Empty condition tree** — `runBranchStep` treats empty `conditions_serialized` as `true`
  (verified, `Executor.php:420–429`). Correct default, but the plain-language renderer says
  "check a condition" for it; render "always" instead. Cosmetic.
- **`wait` + `switch` interplay** — a switch directly after a wait step commonly needs
  `steps.<wait_key>.resolution` / event payload paths; that works today via the Trigger Data-style
  dot-path leaf only against the *trigger* snapshot, not step outputs. Gap: condition trees
  cannot reference `steps.*`. Worth a discovery spike of its own; not a Phase-3 blocker (branch
  placement after `on_event`/`on_timeout` edges already encodes the resolution).

## 5. Workstream D — Authoring surface

Two eras, one decision:

| | D1 — Invest in the form UI (dynamicRows branch rows, rule widget per row) | D2 — Minimal form support; canvas is the branching UI (recommended) |
|---|---|---|
| Effort | High — nested dynamicRows for graph shapes is fighting the component model; the stock rule widget inside a row is uncharted | Low now; branching UX lands with the canvas |
| Outcome | A form that can *technically* express graphs but reads like a spreadsheet of GOTOs | JSON editor (with §2 validation + plain-language preview) bridges until canvas; canvas renders branch/switch natively |
| Risk | Sunk cost — the canvas obsoletes it within the same phase | Merchants without the canvas module keep JSON-only branching |

**Recommendation: D2.** The delivery plan already moved "branching in UI" (Phase 2) into the
canvas era de facto — v1's single-post-delay-branch form concept only exists as the assembler's
degenerate branch rows (§1), and building it out properly now competes with the canvas for the
same budget. What *is* worth doing in the form era, because
it survives into the canvas era:

1. **Save-time graph validation** (§2) with errors/warnings surfaced next to the JSON editor.
2. **Plain-language preview on the edit form** — `PlainLanguageRenderer` exists
   (`src/module-workflows-admin-ui/Model/PlainLanguageRenderer.php`) but today follows only the
   `on_true` edge (line ~157) and is used only as a grid column. Extend it to render both edges
   (indented, "otherwise: …") and switch cases, and surface it live on the form. This is the
   cheapest possible "did I build what I meant?" check and doubles as the canvas node summary.
3. **Branch-aware condition editing stays serialized JSON** until the rule-widget embedding
   problem is solved once, properly, for the canvas side panel (see [canvas.md §6](canvas.md)).

## 6. Architecture summary of the recommendation

```
schema 3 (additive):        switch step (first-match cases, default edge)
Model/Definition/           GraphValidator (DFS: cycles=error, unreachable=warn, dead-edge lints)
Model/Engine/Executor       runSwitchStep(); walk() case; step result records matched case
Save.php / REST save / import / gallery install  ──> GraphValidator (errors block, warnings returned)
PlainLanguageRenderer       both-edge + switch rendering; exposed on edit form
spec/                       JSON Schema v3 + switch conformance fixture
```

Explicitly **not** changing: the single-token execution model, crash-safety design, condition
engine, wait-step semantics, and the binary `branch` step (stays; `switch` is a sibling, not a
replacement — no migration).

## 7. Quality, maintainability, reliability

- **Reliability:** `switch` adds no new persistence states — it is evaluated inline like `branch`,
  so crash-safety analysis is unchanged (the step row is written before edge-follow, same as
  today). Cycle rejection converts a class of runtime failures (~1000 iterations of wasted
  step-row/context writes, then `failExecution`) into save-time errors.
- **Compatibility:** additive schema 3; every stored definition and every parked
  `definition_snapshot` keeps parsing byte-for-byte. The published JSON Schema and fixtures in
  `spec/` version in lockstep — third-party tooling gets the change as a semver minor.
- **Testability:** GraphValidator and `runSwitchStep` are pure-ish; unit suites follow the
  existing engine-test pattern (the schema-2 suites from wave 6 are the template). Add one
  switch conformance fixture (multi-region routing) to `spec/fixtures/`.
- **Maintainability:** one new step type, one new validator class; no parallel machinery, no
  second condition system. The main long-term cost is that every definition *consumer*
  (plain-language renderer, canvas, dry-run walker, export) must learn `switch` — budget that
  into each of those features rather than treating it as free.

## 8. Sequencing & effort

| Order | Item | Effort (senior M2 eng) |
|---|---|---|
| 1 | GraphValidator + wiring into save/import/REST + tests | ~1 wk |
| 2 | Plain-language renderer: both edges, switch, form preview | ~0.5 wk |
| 3 | `switch` step: parser (schema 3), executor, JSON Schema + fixture, tests | ~1–1.5 wk |
| 4 | Docs: 04/06/08 updates, spec changelog | ~0.5 wk |

Total ≈ 3–3.5 wks, independent of and prerequisite to the canvas.

## 9. Open questions

1. Should `switch` case conditions get a first-class "attribute equals value" compact form in the
   *UI generator* (not the format)? Leaning yes — it's pure authoring sugar.
2. Do we expose GraphValidator warnings over REST save responses (extension attribute) so CI
   pipelines see them? Leaning yes, as a non-breaking response field.
3. Condition trees referencing `steps.*` outputs (post-wait routing, captured webhook responses
   beyond the current interpolation path) — separate discovery, touches the trust boundary in
   [10 — Security](../10-security.md#ssrf-hardening-the-webhook-action).
