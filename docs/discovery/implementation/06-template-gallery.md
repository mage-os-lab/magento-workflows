# 06 — Template Gallery: Implementation Plan

**Discovery:** [template-gallery.md](../template-gallery.md) · **Foundations used:** F2, F3, F6 (plain language), F8 (provenance table)
**Modules touched:** new `module-workflows-templates` (content pack), `module-workflows` (installer), `module-workflows-admin-ui` (gallery UI)

## Intent

Bundled-first gallery (hybrid G3): the install pipeline is the existing untrusted-import path
via `WorkflowImporter` (F3), never auto-enabling; the format is a thin envelope over the export
format; the source abstraction keeps a future signed remote feed from requiring rework. Package
split: **UI in admin-ui** (ships everywhere), **content in `module-workflows-templates`**
(trimmable; third-party packs are peers) — resolving discovery §9 Q1 in that direction.

## Components

| Component | Home | Intent |
|---|---|---|
| Template envelope + schema | `spec/workflow-template.schema.json` | `mageos-workflow-template/1`: `template{code,title,description,category,version,requires,parameters}` + `workflow{…verbatim export body}`; published + semver'd like the sibling schemas |
| `TemplateSourceInterface` + `BundledTemplateSource` | `Model/Template/` (core) | `list()`/`get(code)`; bundled source reads registered pack directories (di.xml-registered paths — third-party packs add one line); remote source is a *future implementor*, not scaffolding built now |
| Compatibility checker | `Model/Template/` | Evaluates `requires` (triggers via `TriggerRegistry`, actions via `ActionPool`, `schema ≤ SCHEMA_VERSION`, module presence, edition); returns typed reasons for greyed-out cards |
| Parameter engine | `Model/Template/` | `%param.key%` token substitution (deliberately distinct from runtime `{{ }}`); typed params → form fields (types resolved by the F6 option-source decision: `duration`, `entity:*` pickers, `secret` pick-or-create, `string`/`select`); leftover tokens = hard error before any write |
| `TemplateInstaller` | `Model/Template/` | Orchestrates: envelope schema-validate → compat check → params → substitution → `WorkflowImporter` (F3, `ADMIN_CONTEXT` mode) → status disabled/shadow → provenance write → **deferred secret creation** (after successful save; failed install leaves no orphaned secrets — review finding) |
| Provenance (F8) | `mageos_workflow_template_install` | template code+version, params snapshot, installer, timestamp; powers "installed from X v1.2" and nothing else in v1 (fork-on-install; no upgrade path — documented) |
| Gallery UI | admin-ui | Server-rendered cards + detail view (plain-language rendering of the defaults-substituted body via the core renderer), parameter form, "install as shadow" default-on; post-install redirect to edit form with dry-run/enable next-steps |
| CLI + patch helper | core | `workflow:template:list`, `workflow:template:install <code> --param k=v/--params-file` (SYSTEM mode, same warning as import); `InstallTemplatePatch::forTemplate()` for agency deployments |
| Seed pack | `module-workflows-templates` | 12–15 templates from docs/17 (per discovery §6), each with a CI fixture test: schema-validates, installs against the shim harness pools, dry-run smoke |
| Localization decision | envelope | Per-locale title/description keys decided **before** schema publication (breaking after — discovery §9 Q3); v1: `title`/`description` accept either a string or a `{locale: string}` map, default locale required |

## Stages

| # | Stage | Notes |
|---|---|---|
| 1 | F3 `WorkflowImporter` extraction (CLI + Save converge; behavior-preserving) | The load-bearing refactor; independently valuable |
| 2 | Envelope schema + source interface + bundled source + compat checker | Format published |
| 3 | Parameter engine + `TemplateInstaller` + provenance + CLI + patch helper | Headless install complete |
| 4 | Gallery UI + post-install flow | Merchant surface |
| 5 | Seed pack + per-template CI tests + copy/screenshots | Content; parallelizable with 4 |

## Tests

Importer-extraction regression suite (CLI import behavior byte-identical); envelope validation
(bad format tag, unknown params, leftover tokens); compat matrix (missing trigger/action/module/
edition/schema-too-new → specific reasons); installer ordering (secret deferred; failed save →
zero writes besides nothing); ACL (install requires `::manage`; per-action gates still enforced
by the importer); every seed template's fixture test.

## Compatibility notes

- Depends on 01 stage 2 (validation pipeline — imported/installed graphs get GraphCheck) and
  benefits from 03 (post-install dry-run CTA); neither blocks stages 1–3.
- Remote feed remains future work: when it comes, it implements `TemplateSourceInterface` +
  signature verification behind a default-off config — no gallery/installer changes (the
  signing-scheme decision from discovery §9 Q4 is made *then*, before any fetch code).
- The seed pack's CI test is the mechanism that keeps catalog content honest as the action/
  trigger pools evolve — treat a failing template test like a failing engine test, not content
  debt.
