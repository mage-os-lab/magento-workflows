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

## Contextual entry points (entity grids)

A compact, ACL-gated summary strip renders in `page.main.actions` on the native Orders,
Customers, Products, Reviews, Invoices, Shipments, and Credit Memos grids — *"Workflows: 3 active
for Orders · View · Create workflow"*, or *"Workflows: none yet for Orders · Create one"* when
none exist. No ui_component surgery: the strip renders beside the grid via stable layout handles,
so grid-replacement extensions are unaffected. Two deep links do the work:

- **View** opens the workflow grid pre-filtered to the entity type (via `filters_modifier`).
- **Create workflow** opens the workflow edit form with `entity_type` preselected, gated on
  `MageOS_Workflows::manage`.

The strip itself is gated on `MageOS_Workflows::view` (renders nothing without it), and on a
config toggle, `mageos_workflows/general/expose_on_entity_grids` (Yes/No, default **Yes**). Counts
come from a cached `WorkflowCountProvider`, invalidated on workflow save/delete.

Ships in the optional **`MageOS_WorkflowsAdminExtension`** module
(`mage-os/workflows-admin-extension`) — disable it and the native grids revert byte-for-byte. See
[Discovery — Entity-Grid Visibility](discovery/entity-grid-visibility.md) for the full design and
rationale.

## Shadow mode (v1, nearly free)

Enable a workflow in `shadow` status: conditions evaluate on live traffic, actions log their would-be effect via `simulate()`, nothing mutates.

This is the **single highest-leverage confidence feature** for merchants ("run it for a week, look at what it *would have* done") and it costs one enum value plus the simulate path that dry-run already needs. Ship it before dry-run — it's the same machinery with a status flag.

## Dry-run (edit form)

A **"Dry run"** button on the workflow edit form (gated on `MageOS_Workflows::dry_run`) previews what the *currently edited* definition — saved or not — would do to one entity, with no side effects. It stashes the in-progress definition/conditions via `sessionStorage` and opens the dry-run page, which restores them, so a preview never requires a save.

The page carries an **entity picker** backed by a per-entity-type `RecentEntityProvider` (most-recent, up to 20, no condition filtering in v1; manual id entry always available) and, on run, renders the **trace panel**: one row per visited step with a plain-language label ("Would run", "Would fail", "Skipped", "Production would stop before here"), the `would` summary, the edge taken, and a **"Technical details"** expander (interpolated + redacted config, condition detail, delay timing, notes, path ids). Waits show **both** outcomes; a failed step does not stop the preview, so all problems surface at once.

Dry-runs of *saved* workflows are persisted as `mode=dry_run` execution rows by default (audit + reuse of the execution view), pruned aggressively; unsaved-definition runs are transient. See [08 — Execution Model](08-execution-model.md#dry-run-synchronous-preview) for the walker semantics and [15 — Operations](15-operations.md#retention--pii-pruning) for the retention knob.

## Template gallery (`Marketing → Workflow Templates`)

A bundled-first gallery ([implementation/06](discovery/implementation/06-template-gallery.md)): a merchant picks a template, answers a few questions, and installs a working workflow — **created disabled or in shadow mode**, never auto-enabled — that they dry-run, inspect in plain language, and enable.

- **Card grid** (`template/index`): one server-rendered card per template with a category, description, version, and compatibility badge. Incompatible templates render greyed-out with the *reason* ("Requires the `marketing.generate_coupon` action, which is not installed") — `CompatibilityChecker` evaluates `requires` (triggers via `TriggerRegistry`, actions via `ActionPool`, `schema ≤ SCHEMA_VERSION`, module presence, edition, and a default-locale check). No `ui_component` grid for ~15–50 items.
- **Detail** (`template/view`): the plain-language rendering of the workflow with its *default* parameter values substituted (the same `PlainLanguageRenderer` the grid uses), a `requires` panel, and the parameter list.
- **Install form** (`template/install`): one field per parameter, rendered from the [F6 option-source union](discovery/implementation/00-foundations.md#f6--rest-metadata--validate-endpoints-plainlanguagerenderer-relocation) — a bounded source ships inline `options` (a select); a large/search source (`options_search`, or an `entity:*` type) renders a text input with a note in v1 (the canvas package upgrades it to a searchable picker). `duration` fields note the ISO-8601 format; `secret` fields name a secret and optionally carry a new value. **"Install as shadow" is default-on.** POST installs and redirects to the workflow edit form with a "dry-run, review, then enable" next-steps notice.
- **Pipeline:** the install path *is* the untrusted-import path — `TemplateInstaller` schema-validates the envelope, compat-checks, substitutes `%param.*%` tokens (leftover = hard error), lifts `template.workflow` into a synthetic `mageos-workflow-export/1` envelope, and hands it to `WorkflowImporter` in `ADMIN_CONTEXT` (so every action re-authorizes against the current admin). Provenance ("installed from X v1.2") records to `mageos_workflow_template_install`; pick-or-create secrets are written **after** a successful save, so a failed install leaves no orphaned secrets.
- **CLI / patches:** `bin/magento workflow:template:list` and `workflow:template:install <code> --param k=v --params-file f.json` (SYSTEM mode, the same untrusted-import warning); `InstallTemplatePatch::forTemplate('code', [...])` for agency data patches.
- **ACL:** reuses `MageOS_Workflows::manage` — no new resource; installing a template creates a workflow, and the importer's per-action gates still apply.

Content ships in the peer `mage-os/workflows-templates` pack (trimmable); the UI ships here (everywhere). The template format is published in [`spec/workflow-template.schema.json`](../spec/workflow-template.schema.json) so third parties author templates and ship packs the same way they ship actions.

## v2 (`workflows-canvas`)

React Flow reading/writing the same [definition JSON](04-definition-format.md). Node palette from trigger/action metadata endpoints. The definition format is the API boundary — the canvas is purely presentational, no engine changes.

### Canvas package & delivery (implementation)

Ships as the **optional** `mage-os/workflows-canvas` module (`MageOS_WorkflowsCanvas`). It is purely presentational: **nothing else may depend on it** (enforced by review), and no-canvas installs lose nothing — the classic form + JSON editor stay first-class. The admin-ui entry links ("Visual" grid action, "Open in visual editor" form button, "Open in visual canvas" on the execution view) all check `Module\Manager::isEnabled('MageOS_WorkflowsCanvas')` and simply vanish when the module is absent.

- **Toolchain (quarantined):** the module owns an `app/` npm workspace — TypeScript + React 18 + `@xyflow/react` + `elkjs`, built by Vite into a single self-contained **IIFE** bundle at `web/js/dist/canvas.js` (+ `canvas.css`). Pinned exact versions, its own lockfile, no CDN, no runtime fetch to anything but the same-origin admin endpoints. The dist is committed per release and CI (`.github/workflows/canvas.yml`) is authoritative: it rebuilds, fails on drift (`git diff --exit-code web/js/dist`), runs the vitest suite, runs the Playwright smoke (Chromium only — `npx playwright install --with-deps chromium`, the sole test browser), and greps the bundle for `eval`/`new Function` (CSP gate). The only build-time-added dependency for Phase B is `@playwright/test` (a devDependency, exact-pinned); no runtime deps were added.
- **Release / packaging:** the committed `dist/` per tag is the release artifact (Magento-ecosystem norm, auditable, offline-installable — no merchant build step). Cutting a release: `npm ci --ignore-scripts && npm run build` in `app/`, commit the rebuilt `web/js/dist`, tag; the drift check enforces that the committed bundle matches source. The `app/` workspace (source, tests, e2e, node lockfile) ships in the repo but is never executed by Magento tooling and can be excluded from a Composer dist export.
- **CSP mount contract:** config reaches JS **only** through one `escapeHtmlAttr`'d `data-config` attribute on the mount `<div>` — no inline `<script>`, no server-generated JS. The bundle loads via a same-origin `<script src>`; styles via a same-origin `<link>`. All server-provided strings (names, summaries, errors) render as React text nodes — never `dangerouslySetInnerHTML`.
- **Server is the only authority:** the mapping layer is the only owned "clever" code (definition JSON ⇄ nodes/edges; edge model mirrors `Definition::getStepEdges` incl. switch `case:<key>`). A definition declaring a schema newer than the canvas understands renders **read-only** with a banner (mirrors the engine's enforced version list). The `ui` layout block is read on load and merged on write; nothing outside `{schema, steps, entry, ui}` is carried into a save (no unknown-field passthrough).
- **Phase A (shipped): read-only viewer + overlays.** Typed node renderers (trigger/action/delay, branch with 2 labeled handles, switch per-case + default handles, wait event/timeout, stop); elkjs auto-layout when no `ui` block; a screen-reader outline list on the same page. **Execution overlay** tints the taken path from a new endpoint — `GET /V1/workflow-executions/:id/steps` — that returns a *safe, narrow* projection (`step_key, status, started_at, finished_at, edge_taken, error_summary`): raw step `result` blobs (which the live executor may fill with interpolated secrets) are never returned; `edge_taken` is derived only from whitelisted routing keys, and `error_summary` is a redacted, truncated first line. **Dry-run overlay** renders the [03 dry-run](discovery/implementation/03-dry-run.md) trace (both-path wait trees tinted). Both overlays fetch from same-origin, session-authed admin JSON controllers that delegate to the same core services the REST endpoints use.
- **Layout persistence is read-only in Phase A** (auto-layout each load; a present `ui` block is honored). Persisting a manual move through the existing Save path (gated `::manage`, so the repository validation plugin fires) is a **Phase B** item — the canvas never gets its own save endpoint.
- **Metadata endpoints (F6, `module-workflows`):** `GET /V1/workflows/meta/{actions,triggers,entity-types,secrets,options}` under `::view`. `meta/secrets` returns names only. Select fields carry the option-source union — inline `options` (bounded) or `options_search: {source, min_chars}` resolved via `meta/options?source=&q=` (a DI-registered option-source pool).

**Phase B (shipped): the editor.** The full editing surface, gated by `::manage` (a sibling `canvas/edit` controller; `::view`-only admins get the read-only viewer). Everything below routes editing convenience through the client but keeps the server the sole authority.

- **Palette + connect rules + config panels.** The palette is built from the bootstrapped `meta/actions` (ACL-filtered *for display* by the provider — hiding an action is never the gate; `authorizeActionCodes` on save is) + `meta/triggers`. Drag-or-click adds a node; type-aware connect rules reject invalid drags client-side as UX (handle set per step type from the F1 edge model — branch `on_true/on_false`, switch per-case + `default`, wait `on_event/on_timeout`), the server's `GraphValidator` staying authoritative. Config side panels are generated from `getConfigForm()` metadata including the F6 `options`/`options_search` union (searches hit the same-origin `meta/options?source=&q=` proxy). A variable picker lists context paths (trigger entity + reverse-reachable upstream step outputs) and secret **NAMES only** (values are write-only, never bootstrapped). Undo/redo is session-local.
- **Continuous validation.** A debounced `POST` to a same-origin admin JSON proxy (`Data/Validate`, `::manage`, form key) delegating to the shared `DefinitionValidationInterface` — the same pipeline a save runs. Findings return with `step_key`/`edge` and are pinned to nodes; all message text renders as React text nodes (never `innerHTML`).
- **Save loop (no bespoke endpoint).** The canvas posts the mapped definition through the **existing** admin Save controller (`mageos_workflows/workflow/save`, `::manage`, form key) via a hidden-form POST — a real navigation the browser follows through the controller's redirect, exactly like the classic form's textarea. The canvas owns only the `definition` field (+ its `ui` block); every general field (name/status/trigger/conditions/fan-out/website scope/loop guard) is round-tripped **unchanged** from the bootstrap. Because it reuses that controller verbatim, the repository before-plugin (`ValidateWorkflowOnSave` — the single F2 chokepoint) fires unchanged, and byte-identity of the stored definition is guaranteed by `Definition::fromJson()->toJson()` normalization. Pinned by a PHP save-path equivalence test (canvas-save === textarea-save) + a plugin-fires proof + a vitest asserting `toDefinition` equals the textarea definition on every fixture.
  - **Concurrency posture (documented, not a bug):** round-tripping the general fields from the page-load bootstrap means a canvas save can overwrite a change made in a *different* classic-form tab opened after the canvas page loaded — standard two-tab last-write-wins, identical to two classic-form tabs today. The canvas never edits those fields; it only carries them so its definition-only save doesn't blank them.
- **Layout persistence (was the Phase-A gate).** The first manual node move arms a layout-dirty flag; saving persists node positions into the non-semantic `ui` block through that same Save path (`Definition` preserves `ui` verbatim). No side table, no second write path.
- **Condition slide-out (E1) — spike verdict: JSON fallback shipped, rule widget deferred with evidence.** The plan spiked the stock salesrule-style rule widget rendering inside a slide-out. Evidence gathered: the rule-widget **rendering** layer (`Magento\Rule\Block\Conditions` + the `VarienRulesForm` prototype.js client that drives its interactive attribute/child-row AJAX) is **not a dependency** of `module-workflows-admin-ui` (composer requires only backend/ui/framework), is absent from the repo, and was **never wired into the classic form** either (`conditions_serialized` was a bare textarea) — so there was no working embedding to reuse and no way to evidence a round-trip here. Per the plan's do-not-force rule, the shipped editor is the **JSON condition editor** (E3) in a shared slide-out — admin-ui's **first** web asset (`requirejs-config.js` + `web/js/conditions-slideout.js`, a CSP-safe `data-mage-init` modal), wired into **both** the classic form's Conditions tab (the bare textarea is retained as the "edit as JSON" fallback) **and**, via the identical serialized-tree contract, the canvas node panel. Both post to / are validated by the shared `Conditions` controller (`::manage`, form key) which shape-checks the tree through the same F2 pipeline and echoes the normalized tree back. **The seam is stable:** when a full install adds the rule widget it renders into that same slide-out and posts to that same endpoint — the contract does not move. Completing true E1 (the rule widget) requires: adding `magento/module-rule` to admin-ui, a `newChildUrl` child-row controller, and `VarienRulesForm` initialized against the fragment — verifiable only against a real Magento admin, out of scope for this repo's CI.
- **A11y + smoke.** React Flow keyboard primitives; the read-only outline list doubles as a keyboard/screen-reader focus path into each step's config panel (the outline's step keys become real buttons in the editor); validation status is an `aria-live` region; the editor root is `role="application"`. One Playwright smoke (`app/e2e/`) drives the real built bundle through the mount contract — load fixture → add a step → save → assert the posted definition JSON — against a **mocked** admin page (no Magento). A **full** end-to-end against a real store (real auth/ACL/Save controller) is a separate, unautomated per-release check: it needs a Magento fixture store, an authenticated admin session, and the smoke re-pointed at the live `canvas/edit` URL.

Also v2:

- **Template library** — curated JSON definitions installable from a gallery; the import pipeline is already the mechanism (shipped: see the Template gallery section above).
- **Dry-run mode** — execute with a `simulate` flag; actions render their would-be effect into step results without side effects. Requires `ActionInterface::simulate()`, added to the contract in v1 as an optional interface so the core library is ready.

Note: the canvas replaces the *layout* only. The condition editor is a shared slide-out sharing one serialized-tree contract across the canvas and the classic form; it ships as the JSON tree editor (E1 spike verdict above), with the stock EAV-aware rule widget as the deferred upgrade that drops into the same slide-out + endpoint when a full install can host it.

## Merchant Accessibility & Openness

The engine is only "merchant-facing" if a non-developer can trust and understand it:

- **Plain-language rendering:** auto-generate a sentence from any definition — *"When an order is created on US Store, if grand total > $500 and customer group is Wholesale, then: add order comment, wait 1 hour, if still unpaid notify #fraud."* Rendered on the grid, the form header, the confirmation modal, and change-history entries. Cheap (walk the graph, template per node type), enormous comprehension payoff, and doubles later as the target/source representation for AI-assisted authoring.
- **Change history with diffs:** definitions are versioned already ([Domain Model §Versioning](03-domain-model.md#versioning-semantics)) — expose it. Who changed what, when, rendered as plain-language before/after. Merchants audit; agencies debug "it worked last month."
- **Failure UX:** step errors surfaced as merchant-readable messages with remediation hints (*"The webhook endpoint took longer than 5s"*, not a Guzzle trace; the trace lives behind a "technical details" expander). Daily failure digest email per store, opt-out.
- **a11y & i18n:** all new UI keyboard-navigable and WCAG 2.1 AA (the legacy rule widget won't be — wrap it, don't inherit its sins into new components); every trigger/action/condition label runs through `__()` from day one so the ecosystem can ship label packs.
