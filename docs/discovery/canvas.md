# Discovery — Canvas (Visual Workflow Builder)

**Status:** Discovery / evaluation · **Feeds:** Phase 3 planning ([13 — Delivery Plan](../13-delivery-plan.md))
**Related:** [11 — Admin UI](../11-admin-ui.md#v2-workflows-canvas) · [04 — Definition Format](../04-definition-format.md) · [02 — Packages](../02-packages.md) · [branching.md](branching.md) · [dry-run.md](dry-run.md)

---

## 1. Scope and the one non-negotiable

The canvas is a visual reader/writer of the [definition JSON](../04-definition-format.md) —
palette of triggers/actions, nodes and edges, per-node config panels. The locked decision from
[02 — Packages](../02-packages.md) stands: it ships as an **optional, purely presentational
module** (`mage-os/workflows-canvas`). The engine, the save path, validation, and ACL never move
into JavaScript; the canvas is a client of the same contracts every other client uses. Every
architectural choice below is downstream of that.

Current state (verified): the admin UI contains **zero JavaScript** — no `web/` directory, no
requirejs-config, nothing. Authoring is a raw JSON textarea
(`mageos_workflows_form.xml`, "v1 fallback editor"). Meanwhile two ready-made server assets sit
unconsumed: `ActionMetadataInterface::getConfigForm()` is fully populated across all 22 actions
with **no consumer anywhere**, and `TriggerRegistry` holds grouped trigger metadata consumed only
server-side. The canvas is largely the story of finally consuming them.

## 2. Prerequisite: the metadata & validation API (build regardless of canvas choice)

The REST surface today is workflow CRUD + execution reads only (`etc/webapi.xml`). The canvas —
*any* canvas, including third-party ones the open-spec strategy invites — needs:

| Endpoint | Serves | Source |
|---|---|---|
| `GET /V1/workflows/meta/actions` | palette + config-panel generation: code, label, group, applicable entities, ACL-filtered for the current admin, `getConfigForm()` fields | `ActionPool::getMetadata()` |
| `GET /V1/workflows/meta/triggers` | palette trigger section, grouped | `TriggerRegistry::getAll()` |
| `GET /V1/workflows/meta/entity-types`, `…/secrets` (names only) | selects in config panels | existing sources |
| `POST /V1/workflows/validate` | definition + conditions → structural errors, GraphValidator errors/warnings ([branching.md §2](branching.md)), plain-language rendering | `Definition::fromArray` + `GraphValidator` + `PlainLanguageRenderer` |
| `POST /V1/workflows/dry-run` | preview from the canvas | [dry-run.md](dry-run.md) |

Known gap surfaced by this design: `getConfigForm()` field defs are declarative
(`text/textarea/select/multiselect/boolean/integer/secret`) but select **option lists** (order
statuses, email templates, customer groups, cart price rules) live server-side. The metadata
endpoint must resolve option sources at request time (bounded lists) or expose a small
option-search endpoint for large ones (products, rules). Decide per field during endpoint design;
the interface likely grows an optional `getOptionsSource()` hint — an additive SPI change.

These endpoints are useful without the canvas (CI linting via `validate`, external tooling, AI
authoring per [11 §Accessibility](../11-admin-ui.md#merchant-accessibility--openness)) and
de-risk it. Build first, in `module-workflows` / `module-workflows-admin-ui`, not in the canvas
package.

## 3. Approaches for the canvas itself

### C1 — React Flow SPA in the dedicated module (the plan of record)

React 18 + [React Flow](https://reactflow.dev) (`@xyflow/react`, MIT) + TypeScript, built with
Vite, **compiled bundle committed/shipped in the module** (no CDN — offline/proxy-restricted
installs are a core segment; no build step imposed on merchants). Mounted on a dedicated
full-screen adminhtml page ("Open in visual editor" from the form and grid), single `<div>` root,
IIFE/UMD bundle so RequireJS and the bundle ignore each other.

- ✅ React Flow is the de-facto standard (n8n, several Shopify-app builders); pan/zoom,
  drag-connect, selection, minimap, keyboard a11y primitives are solved problems.
- ✅ The definition JSON round-trips cleanly to nodes+edges; a thin mapping layer is the only
  "clever" code we own.
- ✅ Isolated blast radius: separate package, separate page — a canvas bug can't break the form
  UI, and no-canvas installs lose nothing.
- ⚠️ A second toolchain (node/vitest/vite) enters the repo — CI and contributor skill surface
  grow. Mitigation: the toolchain lives entirely inside the canvas package; the rest of the
  product never depends on it.
- ⚠️ React-in-Magento-admin needs CSP care: Magento 2.4.7+ ships admin CSP (report-only by
  default, and increasingly enforced); a self-hosted bundle with no inline scripts and no
  `eval` passes — verify in the spike, don't discover at GA.

### C2 — Magento-native (Knockout/uiComponent + hand-rolled SVG)

- ✅ No new toolchain; theme-consistent.
- ❌ There is no graph-editor primitive in the Magento frontend stack — pan/zoom/drag-connect/
  hit-testing/undo would be built from scratch on a legacy framework the wider ecosystem is
  actively migrating away from. Realistic cost is a multiple of C1 for a worse result. Rejected.

### C3 — Structured tree builder (AutomateWoo-style nested list, no free canvas)

Server-rendered or light-JS nested step list: linear + indented branches, add-step buttons.

- ✅ Much cheaper than a canvas; inherently accessible; covers linear + light branching well.
- ❌ It's the *form UI stretch goal* wearing a new name — it caps out exactly where merchants
  start needing visual help (multi-way switches, wait-step two-path flows, seeing the whole
  graph). It competes with, rather than complements, the committed canvas direction.
- Verdict: don't build as a product line. But its spirit survives as the **read-only outline
  view** inside the canvas page (a11y fallback, see §7) and the plain-language preview on the
  classic form ([branching.md §5](branching.md)).

### C4 — Embed/iframe an existing OSS builder (n8n-style editor, bpmn-js, …)

Licensing was already litigated for n8n ([01 — locked decisions](../01-overview.md#locked-decisions));
bpmn-js (bpmn.io license requires the watermark, BPMN semantics ≠ our format) and similar bring a
foreign format that would need bidirectional translation to definition JSON — the impedance we
built the format to avoid. Rejected.

**Recommendation: C1**, delivered in two phases (§4), with §2 as a hard prerequisite.

## 4. Phased delivery (the risk-management core of this plan)

### Phase A — Read-only graph viewer (~40% of the value, ~20% of the risk)

Render any definition as an auto-laid-out graph (elkjs/dagre — layered layout suits these mostly-
tree graphs): typed nodes (trigger header, action, delay, branch/switch with labeled edges, wait
with `on_event`/`on_timeout` edges, stop), plain-language node summaries, click → read-only
config inspection.

Two read-only killers-apps ship here:

- **Execution overlay:** load an execution (existing `GET /V1/workflow-executions/:id` + steps),
  tint the taken path with per-step status/duration/result — turns support/debugging from log
  archaeology into a picture. Links from the execution grid.
- **Dry-run overlay:** render the [dry-run](dry-run.md) trace on the graph, including the
  both-paths wait exploration as parallel tinted paths.

Phase A has no write path — no layout persistence, no editing state machine, no undo — so it
hardens the mapping layer and the metadata API against real definitions before any mutation code
exists.

### Phase B — Editing

Palette (drag or click-to-add, ACL-filtered), edge connect/reconnect with type-aware handles
(branch: exactly `on_true`/`on_false`; switch: one handle per case + default; wait: two), node
config side panel generated from `getConfigForm()` metadata, variable-picker for `{{ … }}` fields
(context paths from trigger metadata + upstream step outputs), delete/undo/redo, **continuous
server validation** (debounced `POST …/validate`; errors pinned to nodes), save through the
existing Save controller / REST PUT — the same `Definition::fromArray` + GraphValidator + ACL
gauntlet as every other client. The canvas never gets its own save semantics.

## 5. Layout persistence — where do node coordinates live?

| | L1 — nowhere (always auto-layout) | L2 — optional `ui` block in the definition (recommended) | L3 — separate column/table, not exported |
|---|---|---|---|
| Shape | deterministic elkjs every load | `"ui": {"nodes": {"s1": {"x":0,"y":120}, …}}` top-level, non-semantic, optional | `mageos_workflow.canvas_state` JSON |
| Merchant expectation ("it stays where I put it") | ❌ violated on every edit | ✅ | ✅ |
| Survives export/import & git (agencies) | n/a | ✅ — one artifact stays the whole truth | ❌ layout lost on every export/import |
| Spec impact | none | JSON Schema gains an optional, explicitly non-semantic `ui` object; engine ignores it (documented) | none |
| Cost | diff-noise-free | minor export diff noise; snapshot bloat (bytes) | second persistence path + sync bugs |

**Recommendation: L2.** [04](../04-definition-format.md) is explicit that the definition is "the
single artifact that the form UI, the future canvas, import/export, and the executor all read" —
splitting layout into a side table breaks that for the canvas's own data. Rules: the `ui` block
is optional (hand-authored/imported definitions auto-layout on first open, then persist), the
engine parser ignores-and-preserves it, `GraphValidator` never reads it, and the published schema
documents it as non-semantic so third-party tools know they may drop or regenerate it. Ship in
the same schema-3 revision as `switch` ([branching.md §3](branching.md)) — one spec bump, not two.

## 6. The condition-editor problem (highest integration risk — spike first)

The locked position ([11 §v2](../11-admin-ui.md#v2-workflows-canvas)): the **rule widget remains
the condition editor** — it is the only EAV-aware editor that exists, and rebuilding it in React
means reimplementing attribute discovery, operator/type semantics, and cross-entity subtrees
(and maintaining the twin forever). Note the wrinkle: the rule widget isn't wired into even the
*classic* form yet (`conditions_serialized` is a textarea today) — the canvas doesn't inherit a
working embedding, it forces the question for both UIs at once.

Options for branch/switch/root condition editing from the canvas:

- **E1 — Server-rendered slide-out:** node panel's "Edit conditions" opens a Magento slide-out
  (the standard admin pattern) whose content is a server-rendered form containing the rule widget
  bound to that step's tree; on apply, the serialized tree posts back and lands in the canvas
  model. One rule-widget embedding, reused by the classic form's Conditions tab. The widget's
  legacy markup stays quarantined in a layer that's already legacy-styled — consistent with the
  "wrap it, don't inherit its sins" a11y stance ([11 §a11y](../11-admin-ui.md#merchant-accessibility--openness)).
- **E2 — React condition builder** fed by an attribute-metadata endpoint: modern UX, WCAG-clean,
  but duplicates the one component the architecture explicitly decided not to duplicate, and EAV
  semantics (operators per backend type, option attributes, cross-entity subtrees, ANY/ALL item
  matching) are precisely the long tail that made the rule model worth generalizing.
- **E3 — JSON textarea in the panel** (status quo transplanted): acceptable *fallback*, not the
  feature.

**Recommendation: E1, spiked in week one of canvas work** (it's also the plan's biggest unknown:
rule-widget forms assume full-page form scaffolding; the spike proves it renders and posts inside
a slide-out). E3 remains behind an "edit as JSON" toggle for power users. E2 is the eventual
modernization path *if* E1's UX proves unacceptable — but it would be its own funded project.

## 7. Quality, maintainability, reliability

- **Round-trip fidelity is the reliability contract:** parse → graph model → serialize must be
  lossless, including **unknown fields** (a schema-4 definition opened by an older canvas must
  not be silently stripped — preserve-unknown-keys in the mapping layer, and refuse *editing*,
  offering read-only, when `schema` exceeds the canvas's known version). Enforced by CI
  round-tripping every fixture in `spec/fixtures/` byte-for-byte (modulo `ui`).
- **Server is the only authority:** the canvas validates continuously for UX but the save path
  re-validates everything; ACL filtering of the palette is convenience, `authorizeActionCodes`
  on save is the gate. No engine semantics are reimplemented client-side (plain language,
  validation messages, dry-run all come from endpoints) — the known cost is chattier UX, the
  payoff is zero drift.
- **Accessibility:** React Flow keyboard support + our own focus order for panels gets the new
  surface to WCAG 2.1 AA; the classic form + JSON editor remains the fully supported
  no-canvas path (it must not rot: it's also the no-JS, headless, and emergency-edit path). Add
  a read-only outline/list rendering of the graph on the canvas page for screen readers (C3's
  legacy).
- **Testing:** TypeScript mapping layer under vitest (fixture round-trips, edge-type rules);
  endpoint tests PHP-side like every other module; one Playwright smoke (load fixture → edit →
  save → assert stored JSON) wired to the existing CI; visual polish verified manually per
  release, not pixel-tested.
- **Maintainability:** pin React Flow major; the canvas package owns its npm lockfile and
  release cadence decoupled from the engine (the definition format is the interface —
  [02 §Rationale](../02-packages.md#separation-rationale) explicitly allows the canvas to lag).
  Contributor docs must cover the second toolchain honestly (build, test, release-artifact
  policy: dist committed per release tag).
- **Dependency posture:** two runtime deps (react, @xyflow/react) + elkjs; no CDN, no telemetry,
  no fonts/assets fetched at runtime — same on-prem posture as the engine.

## 8. Sequencing & effort

| Order | Item | Effort |
|---|---|---|
| 0 | Spikes: React-in-adminhtml + CSP proof; rule-widget slide-out (E1) | ~1–1.5 wk |
| 1 | Metadata + validate endpoints (§2) — useful standalone | ~1.5–2 wk |
| 2 | Phase A viewer: mapping layer, auto-layout, node renderers, execution + dry-run overlays | ~2.5–3 wk |
| 3 | Phase B editor: palette, connect rules, config panels from metadata, undo, save loop | ~3–4 wk |
| 4 | Condition slide-out integration (E1) on canvas + classic form Conditions tab | ~1.5–2 wk |
| 5 | a11y pass, outline view, docs, Playwright smoke, packaging/release wiring | ~1.5 wk |

Total ≈ 11–14 wks — comfortably the largest Phase-3 item, which is why Phase A is cut to ship
alone: if Phase B slips, the viewer + overlays + endpoints are independently valuable.
Dependencies: [branching.md](branching.md) items (GraphValidator, `switch`, schema 3 incl. `ui`
block) and [dry-run](dry-run.md) should land first; the [template gallery](template-gallery.md)
is independent.

## 9. Open questions

1. Node/edge rendering of a *degraded* install (action code present in a definition but no
   longer registered — module removed): render as an error node, allow deletion but not
   configuration. Needs a decision + fixture.
2. Does Phase B allow editing `wait`/`switch` on a schema-1 workflow (auto-bumping `schema`)?
   Proposal: yes, with an explicit "this upgrades the definition to schema N" confirmation —
   matches the parser's enforced-declaration rule.
3. Mobile/tablet admin use: out of scope for AA, but confirm the page degrades to the outline
   view rather than an unusable canvas.
4. Release artifact policy — committed `dist/` vs composer-packaged build artifacts vs
   packagist-side build. Leaning committed dist per tag (Magento-ecosystem norm, auditable).
