# 07 — Canvas: Implementation Plan

**Discovery:** [canvas.md](../canvas.md) · **Foundations used:** F1 (schema 3 + `ui` block), F2, F6
**Modules touched:** new `module-workflows-canvas` (+ its npm workspace), `module-workflows` (endpoints via F6), `module-workflows-admin-ui` (entry links, condition slide-out)

## Intent

React Flow viewer-first, in a quarantined optional package. The definition JSON is the only
contract between canvas and engine; the server stays the sole authority (validation, ACL,
plain language, dry-run all come from endpoints). The riskiest integrations — React-in-adminhtml
under CSP, and the rule-widget slide-out — are proven by spikes before feature work commits.

## Package & toolchain shape

```
src/module-workflows-canvas/
  etc/, Controller/Adminhtml/Canvas/, view/adminhtml/layout+templates   thin Magento shell:
      full-screen page, one mount <div>, config JSON (endpoint URLs, ACL grants, workflow id)
  web/js/dist/canvas.js                built IIFE bundle, committed per release tag
  app/                                 the npm workspace (TS + React 18 + @xyflow/react + elkjs,
                                       Vite build, vitest) — never executed by Magento tooling
```

Toolchain rules: no CDN/runtime fetches; pinned majors; the npm lockfile lives in this module
only; CI job builds `app/` and asserts the committed dist is current (drift = failing check).

## Components

| Component | Intent |
|---|---|
| Spikes (gate) | (a) mount React on an adminhtml page under Magento 2.4.7 CSP — no inline script, no eval; (b) rule-widget-in-slide-out proof (server-rendered form fragment posting a serialized tree back). Both throwaway; both decide go/no-go on their integration shape |
| Mapping layer (TS) | `definition JSON ⇄ {nodes, edges}` — the only "clever" owned code. Version gate: `schema > known` → read-only mode. No unknown-field preservation (schema-evolution posture per discovery §7); `ui` block read/written per F1. CI round-trips every `spec/fixtures/` file through it byte-for-byte (modulo `ui`) |
| Node renderers | trigger header, action, delay, branch (2 labeled handles), switch (per-case + default handles), wait (event/timeout handles), stop; plain-language node summaries fetched, not computed client-side |
| Auto-layout | elkjs layered; runs when no `ui` block; positions persist into `ui` on first manual move |
| **Phase A read-only overlays** | Execution overlay (existing `GET /V1/workflow-executions/:id` + steps → tint taken path, per-step status/duration); dry-run overlay ([03](03-dry-run.md) trace → tinted paths incl. wait both-path trees) |
| **Phase B editing** | Palette from `meta/actions|triggers` (ACL-filtered display; server re-gates on save); connect rules from the F1 edge model (handle counts per type); config side panel generated from `getConfigForm()` metadata + the F6 option-source mechanism; variable picker (context paths from trigger metadata + upstream step outputs); undo/redo local to the session; debounced `POST /V1/workflows/validate` with errors pinned to nodes |
| Save loop | Canvas posts the definition through the existing Save/REST path — never its own endpoint; a save-path test asserts canvas-save === textarea-save for identical input |
| Condition slide-out (E1) | Built in **admin-ui**, shared by the canvas node panel and the classic form's Conditions tab (both currently textareas); canvas embeds it via the standard slide-out mechanism; "edit as JSON" toggle retained |
| A11y + outline view | Keyboard nav per React Flow primitives + owned focus order; a read-only outline/list rendering of the graph on the same page (screen-reader path); classic form remains the fully-supported no-canvas path |

## Stages

| # | Stage | Notes |
|---|---|---|
| 0 | Both spikes | Go/no-go gates; ~1–1.5 wk total |
| 1 | F6 endpoints (if not already landed via 01/02) + package scaffold + CI build-drift check | |
| 2 | Mapping layer + fixture round-trip CI + version gate | Pure TS; no UI yet |
| 3 | **Phase A**: renderers, auto-layout, read-only page, execution + dry-run overlays, entry links from form/grid | Independently shippable release |
| 4 | **Phase B**: palette, connect rules, config panels, variable picker, undo, validate loop, save loop | The editor |
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
