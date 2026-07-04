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

Also v2:

- **Template library** — curated JSON definitions installable from a gallery; the import pipeline is already the mechanism (shipped: see the Template gallery section above).
- **Dry-run mode** — execute with a `simulate` flag; actions render their would-be effect into step results without side effects. Requires `ActionInterface::simulate()`, added to the contract in v1 as an optional interface so the core library is ready.

Note: the canvas replaces the *layout*; the rule widget remains the condition editor even in v2 — it's the only EAV-aware editor that exists.

## Merchant Accessibility & Openness

The engine is only "merchant-facing" if a non-developer can trust and understand it:

- **Plain-language rendering:** auto-generate a sentence from any definition — *"When an order is created on US Store, if grand total > $500 and customer group is Wholesale, then: add order comment, wait 1 hour, if still unpaid notify #fraud."* Rendered on the grid, the form header, the confirmation modal, and change-history entries. Cheap (walk the graph, template per node type), enormous comprehension payoff, and doubles later as the target/source representation for AI-assisted authoring.
- **Change history with diffs:** definitions are versioned already ([Domain Model §Versioning](03-domain-model.md#versioning-semantics)) — expose it. Who changed what, when, rendered as plain-language before/after. Merchants audit; agencies debug "it worked last month."
- **Failure UX:** step errors surfaced as merchant-readable messages with remediation hints (*"The webhook endpoint took longer than 5s"*, not a Guzzle trace; the trace lives behind a "technical details" expander). Daily failure digest email per store, opt-out.
- **a11y & i18n:** all new UI keyboard-navigable and WCAG 2.1 AA (the legacy rule widget won't be — wrap it, don't inherit its sins into new components); every trigger/action/condition label runs through `__()` from day one so the ecosystem can ship label packs.
