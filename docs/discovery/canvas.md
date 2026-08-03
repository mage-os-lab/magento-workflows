# Discovery — Canvas (Visual Workflow Builder)

**Status:** Discovery / evaluation · **Feeds:** Phase 3 planning ([13 — Delivery Plan](../13-delivery-plan.md)) · **Implementation plan:** [implementation/07-canvas.md](implementation/07-canvas.md)
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
unconsumed: `ActionMetadataInterface::getConfigForm()` is populated on 19 of the 22 actions
(the three no-config order actions — hold/unhold/cancel — return an empty form by design) with
**no consumer anywhere**, and `TriggerRegistry` holds groupable trigger metadata consumed only
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

- **Execution overlay:** load an execution and its step rows — the execution read exists
  (`GET /V1/workflow-executions/:id`) but step rows are *not* REST-exposed today, so this ships
  a small `…/:id/steps` endpoint alongside — and tint the taken path with per-step
  status/duration/result: support/debugging goes from log archaeology to a picture. Links from
  the execution grid.
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

An honesty note first, because it reprices this whole section: **nothing survives the current
parser except `schema`/`steps`/`entry`.** `Definition::toArray()` re-emits exactly those three
keys (`Definition.php:227–234`), every save and import round-trips through it
(`Save.php:67, 81`; `ImportCommand.php:125, 170`), and the published JSON Schema declares
`additionalProperties: false` at the top level *and* on every step type
(`spec/workflow-definition.schema.json`). Any layout stored "in the definition" therefore
requires a deliberate core change — a whitelisted, preserved `ui` bag in
`fromArray()`/`toArray()` plus a schema relaxation — not a free rider.

| | L1 — nowhere (always auto-layout) | L2 — optional `ui` block in the definition (recommended) | L3 — separate column/table, not exported |
|---|---|---|---|
| Shape | deterministic elkjs every load | `"ui": {"nodes": {"s1": {"x":0,"y":120}, …}}` top-level, non-semantic, optional | `mageos_workflow.canvas_state` JSON |
| Merchant expectation ("it stays where I put it") | ❌ violated on every edit | ✅ | ✅ |
| Survives export/import & git (agencies) | n/a | ✅ *once the parser preserves it* — one artifact stays the whole truth | ❌ layout lost on every export/import |
| Spec impact | none | JSON Schema relaxed to allow exactly one optional, explicitly non-semantic top-level `ui` object | none |
| Engine impact | none | `Definition` carries+re-emits `ui` verbatim (small, contract-level change to the most shared class in the system — the real cost of L2) | none |
| Cost | diff-noise-free | parser change + schema relaxation; minor export diff noise; snapshot bloat (bytes) | second persistence path + sync bugs; loses layout exactly where agencies live (git) |

**Recommendation: still L2, with the cost stated honestly.** [04](../04-definition-format.md) is
explicit that the definition is "the single artifact that the form UI, the future canvas,
import/export, and the executor all read" — splitting layout into a side table breaks that for
the canvas's own data, and L3's "no spec impact" advantage buys a permanent layout-loss bug on
the workflow-as-code path. Rules: the `ui` block is optional (hand-authored/imported definitions
auto-layout on first open, then persist); `Definition` preserves it verbatim and *only* it (no
general unknown-key passthrough — see §7); `GraphValidator` never reads it; the spec documents
it as non-semantic so third-party tools may drop or regenerate it. Versioning: since `ui`
carries no executable semantics, it does **not** gate on a schema declaration (consistent with
[04 §Schema versions](../04-definition-format.md#schema-versions), where the version tracks
executable features only) — the schema *relaxation* simply ships in the same spec release as
schema 3 ([branching.md §3](branching.md)), one spec release, one migration note.

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

**Outcome:** the E1 spike could not be evidenced in this repo (the rule-widget rendering layer is
not a dependency and cannot run in CI — see [11 §v2](../11-admin-ui.md#v2-workflows-canvas)), so
the shipped editor is **E2 on the E1 seam**: a React condition tree builder in the shared
slide-out, driven entirely by a server metadata feed (`mageos_workflows/data/conditionMeta`,
backed by `ConditionMetaProvider`) that projects the *existing* rule-model contract —
`getNewChildSelectOptions()`, `loadAttributeOptions()`, `getInputType()`,
`getValueSelectOptions()`, operator sets — per node type. The "duplicates the rule widget"
objection to E2 is answered by that feed: attribute discovery, operator/type semantics, EAV
options and cross-entity subtrees stay server-side in the condition classes; the client renders
what it is told and preserves verbatim any node type the server cannot describe. The apply
round-trip and the serialized-tree contract are unchanged (the same `workflow/conditions`
endpoint), so a full install that later hosts the stock rule widget still drops into the same
seam. E3 survives as the documented "Edit as JSON" toggle inside the slide-out.

## 7. Quality, maintainability, reliability

- **Round-trip fidelity is the reliability contract:** parse → graph model → serialize must be
  lossless for everything the format defines. Forward compatibility works by **schema
  evolution, not unknown-field preservation** — the published schema is
  `additionalProperties: false` throughout and the server strips anything else on save
  (`Definition::toArray()`), so a canvas built against schema N handles a schema-N+1 document by
  checking the declared version and **refusing to edit** (read-only view offered), never by
  silently carrying fields it doesn't understand into a lossy save. (Note the server-side gate
  is real today: `SCHEMA_VERSIONS = [1, 2]` — the engine itself rejects anything newer, so the
  canvas's version check mirrors an enforced contract, and this behavior depends on
  [branching.md](branching.md)'s version-list bump landing in step.) Enforced by CI
  round-tripping every fixture in `spec/fixtures/` byte-for-byte through the mapping layer
  (modulo `ui`), plus a save-path integration test proving canvas-save === textarea-save for
  identical definitions.
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
Dependencies: [branching.md](branching.md) items (GraphValidator, `switch`, the schema-3 spec
release carrying the `ui` relaxation and the `Definition` ui-preservation change) and
[dry-run](dry-run.md) should land first; the [template gallery](template-gallery.md)
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

## 10. Post-ship outcomes (follow-up pass on PR #35's out-of-scope list)

- **Canvas-first creation shipped.** `canvas/edit` with no `workflow_id` mounts a blank
  workflow plus a `workflowOptions` option catalogue; a "Workflow settings" slide-out edits
  the general fields, and the save posts `back=canvas` so creation round-trips through the
  existing Save controller (still no bespoke endpoint) and back into the canvas.
- **Canvas i18n shipped.** All UI literals go through `t()` backed by a server-injected
  phrase map built from `__()` calls (`Model/I18n/PhraseCatalog.php`); the i18n collector
  scans TS `t('...')` literals and CI gates catalog drift. This resolves the "hardcoded
  English" caveat noted in the Phase B delivery.
- **Config-panel gaps closed.** Multi search-select for `multiselect` + `options_search`;
  option sources wired for email template / cart price rule / attribute-code / carrier
  fields; the dry-run overlay accepts an optional entity id.
