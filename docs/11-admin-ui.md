# 11 — Admin UI

## v1 (adminhtml, ships with MVP)

Grid + tabbed form:

| Tab | Contents |
|---|---|
| General | Name, status, scope, loop guard |
| Trigger | Grouped select from trigger metadata; schedule builder for cron type |
| Conditions | The stock rule widget — ugly, familiar, free |
| Actions | `dynamicRows`; each row's fieldset rendered from `getConfigForm()` metadata; delay and stop are just row types. v1 exposes linear + delays + a single optional post-delay branch |
| Logs | Embedded execution grid |

Plus grid mass-actions and the manual-run modal ([Triggers §Manual](05-triggers.md#manual-triggers)).

## Shadow mode (v1, nearly free)

Enable a workflow in `shadow` status: conditions evaluate on live traffic, actions log their would-be effect via `simulate()`, nothing mutates.

This is the **single highest-leverage confidence feature** for merchants ("run it for a week, look at what it *would have* done") and it costs one enum value plus the simulate path that dry-run already needs. Ship it before dry-run — it's the same machinery with a status flag.

## Dry-run (edit form)

A **"Dry run"** button on the workflow edit form (gated on `MageOS_Workflows::dry_run`) previews what the *currently edited* definition — saved or not — would do to one entity, with no side effects. It stashes the in-progress definition/conditions via `sessionStorage` and opens the dry-run page, which restores them, so a preview never requires a save.

The page carries an **entity picker** backed by a per-entity-type `RecentEntityProvider` (most-recent, up to 20, no condition filtering in v1; manual id entry always available) and, on run, renders the **trace panel**: one row per visited step with a plain-language label ("Would run", "Would fail", "Skipped", "Production would stop before here"), the `would` summary, the edge taken, and a **"Technical details"** expander (interpolated + redacted config, condition detail, delay timing, notes, path ids). Waits show **both** outcomes; a failed step does not stop the preview, so all problems surface at once.

Dry-runs of *saved* workflows are persisted as `mode=dry_run` execution rows by default (audit + reuse of the execution view), pruned aggressively; unsaved-definition runs are transient. See [08 — Execution Model](08-execution-model.md#dry-run-synchronous-preview) for the walker semantics and [15 — Operations](15-operations.md#retention--pii-pruning) for the retention knob.

## v2 (`workflows-canvas`)

React Flow reading/writing the same [definition JSON](04-definition-format.md). Node palette from trigger/action metadata endpoints. The definition format is the API boundary — the canvas is purely presentational, no engine changes.

### Canvas package & delivery (implementation)

Ships as the **optional** `mage-os/workflows-canvas` module (`MageOS_WorkflowsCanvas`). It is purely presentational: **nothing else may depend on it** (enforced by review), and no-canvas installs lose nothing — the classic form + JSON editor stay first-class. The admin-ui entry links ("Visual" grid action, "Open in visual editor" form button, "Open in visual canvas" on the execution view) all check `Module\Manager::isEnabled('MageOS_WorkflowsCanvas')` and simply vanish when the module is absent.

- **Toolchain (quarantined):** the module owns an `app/` npm workspace — TypeScript + React 18 + `@xyflow/react` + `elkjs`, built by Vite into a single self-contained **IIFE** bundle at `web/js/dist/canvas.js` (+ `canvas.css`). Pinned exact versions, its own lockfile, no CDN, no runtime fetch to anything but the same-origin admin endpoints. The dist is committed per release and CI (`.github/workflows/canvas.yml`) is authoritative: it rebuilds, fails on drift (`git diff --exit-code web/js/dist`), runs vitest, and greps the bundle for `eval`/`new Function` (CSP gate).
- **CSP mount contract:** config reaches JS **only** through one `escapeHtmlAttr`'d `data-config` attribute on the mount `<div>` — no inline `<script>`, no server-generated JS. The bundle loads via a same-origin `<script src>`; styles via a same-origin `<link>`. All server-provided strings (names, summaries, errors) render as React text nodes — never `dangerouslySetInnerHTML`.
- **Server is the only authority:** the mapping layer is the only owned "clever" code (definition JSON ⇄ nodes/edges; edge model mirrors `Definition::getStepEdges` incl. switch `case:<key>`). A definition declaring a schema newer than the canvas understands renders **read-only** with a banner (mirrors the engine's enforced version list). The `ui` layout block is read on load and merged on write; nothing outside `{schema, steps, entry, ui}` is carried into a save (no unknown-field passthrough).
- **Phase A (shipped): read-only viewer + overlays.** Typed node renderers (trigger/action/delay, branch with 2 labeled handles, switch per-case + default handles, wait event/timeout, stop); elkjs auto-layout when no `ui` block; a screen-reader outline list on the same page. **Execution overlay** tints the taken path from a new endpoint — `GET /V1/workflow-executions/:id/steps` — that returns a *safe, narrow* projection (`step_key, status, started_at, finished_at, edge_taken, error_summary`): raw step `result` blobs (which the live executor may fill with interpolated secrets) are never returned; `edge_taken` is derived only from whitelisted routing keys, and `error_summary` is a redacted, truncated first line. **Dry-run overlay** renders the [03 dry-run](discovery/implementation/03-dry-run.md) trace (both-path wait trees tinted). Both overlays fetch from same-origin, session-authed admin JSON controllers that delegate to the same core services the REST endpoints use.
- **Layout persistence is read-only in Phase A** (auto-layout each load; a present `ui` block is honored). Persisting a manual move through the existing Save path (gated `::manage`, so the repository validation plugin fires) is a **Phase B** item — the canvas never gets its own save endpoint.
- **Metadata endpoints (F6, `module-workflows`):** `GET /V1/workflows/meta/{actions,triggers,entity-types,secrets,options}` under `::view`. `meta/secrets` returns names only. Select fields carry the option-source union — inline `options` (bounded) or `options_search: {source, min_chars}` resolved via `meta/options?source=&q=` (a DI-registered option-source pool).

**Phase B (not yet built):** the editing surface (palette, type-aware connect rules, config side panels from `getConfigForm()` metadata, variable picker, undo/redo, debounced `POST /validate`, save through the existing REST/Save path), layout persistence, and the **rule-widget slide-out spike** (E1) — the condition editor is embedded, not rebuilt. Those two — layout write-through-Save and the slide-out spike — are the open Phase-B gates.

Also v2:

- **Template library** — curated JSON definitions installable from a gallery; the import pipeline is already the mechanism.
- **Dry-run mode** — execute with a `simulate` flag; actions render their would-be effect into step results without side effects. Requires `ActionInterface::simulate()`, added to the contract in v1 as an optional interface so the core library is ready.

Note: the canvas replaces the *layout*; the rule widget remains the condition editor even in v2 — it's the only EAV-aware editor that exists.

## Merchant Accessibility & Openness

The engine is only "merchant-facing" if a non-developer can trust and understand it:

- **Plain-language rendering:** auto-generate a sentence from any definition — *"When an order is created on US Store, if grand total > $500 and customer group is Wholesale, then: add order comment, wait 1 hour, if still unpaid notify #fraud."* Rendered on the grid, the form header, the confirmation modal, and change-history entries. Cheap (walk the graph, template per node type), enormous comprehension payoff, and doubles later as the target/source representation for AI-assisted authoring.
- **Change history with diffs:** definitions are versioned already ([Domain Model §Versioning](03-domain-model.md#versioning-semantics)) — expose it. Who changed what, when, rendered as plain-language before/after. Merchants audit; agencies debug "it worked last month."
- **Failure UX:** step errors surfaced as merchant-readable messages with remediation hints (*"The webhook endpoint took longer than 5s"*, not a Guzzle trace; the trace lives behind a "technical details" expander). Daily failure digest email per store, opt-out.
- **a11y & i18n:** all new UI keyboard-navigable and WCAG 2.1 AA (the legacy rule widget won't be — wrap it, don't inherit its sins into new components); every trigger/action/condition label runs through `__()` from day one so the ecosystem can ship label packs.
