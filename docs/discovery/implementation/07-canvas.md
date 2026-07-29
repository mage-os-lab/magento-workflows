# 07 — Canvas: Implementation Plan

**Discovery:** [canvas.md](../canvas.md) · **Foundations used:** F1 (schema 3 + `ui` block), F2, F6
**Modules touched:** new `module-workflows-canvas` (+ its npm workspace) — naming per convention: dir `src/module-workflows-canvas`, composer `mage-os/workflows-canvas`, module `MageOS_WorkflowsCanvas`, namespace `MageOS\WorkflowsCanvas` — plus `module-workflows` (endpoints via F6), `module-workflows-admin-ui` (entry links, condition slide-out)

## Intent

React Flow viewer-first, in a quarantined optional package. The definition JSON is the only
contract between canvas and engine; the server stays the sole authority (validation, ACL,
plain language, dry-run all come from endpoints). The riskiest integrations — React-in-adminhtml
under CSP, and the rule-widget slide-out — are proven by spikes before feature work commits.

## Package & toolchain shape

```
src/module-workflows-canvas/
  etc/, Controller/Adminhtml/Canvas/, view/adminhtml/layout+templates   thin Magento shell:
      full-screen page, one mount <div>; config (endpoint URLs, ACL grants, workflow id) reaches
      the JS via data-* attributes on the mount element — NOT an inline <script> (CSP); the spike
      confirms this channel. ADMIN_RESOURCE: MageOS_Workflows::view for the read-only viewer
      controller, ::manage for the editor controller; no new menu node — entry links live on the
      workflow grid/form and execution view
  view/adminhtml/web/js/dist/canvas.js built IIFE bundle, committed per release tag (must live
                                       under view/<area>/web/ — a module-root web/ is not a
                                       static-file location in Magento)
  app/                                 the npm workspace (TS + React 18 + @xyflow/react + elkjs,
                                       Vite build, vitest) — never executed by Magento tooling
```

Toolchain rules: no CDN/runtime fetches; pinned majors; the npm lockfile lives in this module
only. CI: a **new** `.github/workflows/canvas.yml` (the existing `lint.yml` has no Node at all)
— setup-node, `npm ci && npm run build` in `app/`, then
`git diff --exit-code src/module-workflows-canvas/view/adminhtml/web/js/dist` (drift = failing
check), plus the
vitest run; the Playwright smoke gets its browser-install step here too.

## Components

| Component | Intent |
|---|---|
| Spikes (gate) | (a) mount React on an adminhtml page under Magento 2.4.7 CSP — no inline script, no eval; (b) rule-widget-in-slide-out proof (server-rendered form fragment posting a serialized tree back). Both throwaway; both decide go/no-go on their integration shape |
| Mapping layer (TS) | `definition JSON ⇄ {nodes, edges}` — the only "clever" owned code. Version gate: `schema > known` → read-only mode. No unknown-field preservation (schema-evolution posture per discovery §7); `ui` block read/written per F1. CI round-trips every `spec/fixtures/` file through it byte-for-byte (modulo `ui`) |
| Node renderers | trigger header, action, delay, branch (2 labeled handles), switch (per-case + default handles), wait (event/timeout handles), stop; plain-language node summaries fetched, not computed client-side |
| Auto-layout | elkjs layered; runs when no `ui` block; positions persist into `ui` on first manual move |
| **Phase A read-only overlays** | Execution overlay — **requires a new endpoint**: step rows are not REST-exposed today (`WorkflowExecutionInterface` carries no steps, there is no `…/steps` route, and `WorkflowExecutionStepInterface` even lacks `getStartedAt()`/`getFinishedAt()` getters). Stage 3 therefore ships `GET /V1/workflow-executions/:executionId/steps` (ACL `::view`) returning `{step_key, status, started_at, finished_at, result, error}` plus the two missing getters; duration is derived client-side. Dry-run overlay: [03](03-dry-run.md) trace → tinted paths incl. wait both-path trees |
| **Phase B editing** | Palette from `meta/actions|triggers` (ACL-filtered display; server re-gates on save); connect rules from the F1 edge model (handle counts per type); config side panel generated from `getConfigForm()` metadata + the **recorded** F6 option-source union (inline `options` vs `options_search` → `meta/options`); variable picker (context paths from trigger metadata + upstream step outputs); undo/redo local to the session; debounced `POST /V1/workflows/validate` with messages pinned to nodes via `target.step_key` |
| Save loop | Canvas posts the definition through the existing Save/REST path — never its own endpoint; a save-path test asserts canvas-save === textarea-save for identical input |
| Condition slide-out (E1) | Built in **admin-ui**, shared by the canvas node panel and the classic form's Conditions tab (both currently textareas); canvas embeds it via the standard slide-out mechanism; "edit as JSON" toggle retained. Two honesty notes for the implementer: this is admin-ui's **first** JS/web asset (the module has zero today — the `web/` dir and requirejs wiring are net-new), and the rule widget isn't wired into the classic form either — the classic-form Conditions tab is new work sharing the one embedding, not a retrofit |
| A11y + outline view | Keyboard nav per React Flow primitives + owned focus order; a read-only outline/list rendering of the graph on the same page (screen-reader path); classic form remains the fully-supported no-canvas path |

## Stages

| # | Stage | Notes / done-when |
|---|---|---|
| 0 | Both spikes | Go/no-go gates; ~1–1.5 wk total. Done when: React mounts under enforced admin CSP with the data-attribute config channel, and a rule-widget tree round-trips through a slide-out post |
| 1 | Remaining F6 endpoints (`meta/actions|triggers|entity-types|secrets`, `meta/options` — per the F6 ownership table, whatever 01/02/06 haven't built) + package scaffold + CI (`canvas.yml`, build-drift check) | Done when: drift check fails on an uncommitted dist change |
| 2 | Mapping layer + fixture round-trip CI + version gate | Pure TS; no UI yet. Done when: every `spec/fixtures/*` round-trips byte-for-byte (modulo `ui`); a schema-4 mock document renders the read-only gate |
| 3 | **Phase A**: renderers, auto-layout, read-only page, execution-steps endpoint + execution overlay, dry-run overlay, entry links from form/grid | Independently shippable release. Done when: an execution's taken path renders from the new steps endpoint |
| 4 | **Phase B**: palette, connect rules, config panels, variable picker, undo, validate loop, save loop | The editor. Done when: the save-path equivalence test passes (canvas-save === textarea-save for identical definitions) |
| 5 | Condition slide-out (E1) wired into canvas panel **and** classic form Conditions tab | Retires the conditions textarea both places |
| 6 | A11y pass, outline view, Playwright smoke (load fixture → edit → save → assert stored JSON), release/packaging docs | |

## Tests

Vitest: mapping round-trips (all fixtures), edge-rule table (per step type), version-gate,
`ui`-block merge behavior. PHP: endpoint contract tests (F6), save-path equivalence test.
One Playwright smoke in CI; visual polish stays a manual per-release check.

## Compatibility notes

- Hard dependencies: 01 complete (schema 3 + `ui` preservation in `Definition` — without it the
  canvas cannot persist layout), F6 endpoints, 03 for the dry-run overlay (Phase A degrades
  gracefully without it: overlay button hidden).
- No-canvas installs lose nothing: the module is optional, the classic form + JSON editor stay
  first-class, and nothing else may ever *require* the canvas module (enforced by review
  convention: no other module depends on `module-workflows-canvas`).
- Degraded definitions (unregistered action code): render as an error node, deletable but not
  configurable — fixture added with this shape.
