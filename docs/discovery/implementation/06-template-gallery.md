# 06 — Template Gallery: Implementation Plan

**Discovery:** [template-gallery.md](../template-gallery.md) · **Foundations used:** F2, F3, F6 (plain language + option-source), F8 (provenance table)
**Modules touched:** new `module-workflows-templates` (content pack) — naming per the existing convention: dir `src/module-workflows-templates`, composer `mage-os/workflows-templates`, module `MageOS_WorkflowsTemplates`, namespace `MageOS\WorkflowsTemplates` — plus `module-workflows` (installer), `module-workflows-admin-ui` (gallery UI)

## Intent

Bundled-first gallery (hybrid G3): the install pipeline is the existing untrusted-import path
via `WorkflowImporter` (F3), never auto-enabling; the format is a thin envelope over the export
format; the source abstraction keeps a future signed remote feed from requiring rework. Package
split: **UI in admin-ui** (ships everywhere), **content in `module-workflows-templates`**
(trimmable; third-party packs are peers) — resolving discovery §9 Q1 in that direction.

## Components

| Component | Home | Intent |
|---|---|---|
| Template envelope + schema | `spec/workflow-template.schema.json` | `mageos-workflow-template/1`: `template{code,title,description,category,version,requires,parameters}` + `workflow{…}` — where `workflow` carries the export envelope's *fields* but is **not** itself a valid envelope (the real export format is flat with a `format` tag and `additionalProperties:false`): `TemplateInstaller` lifts `template.workflow` into a synthetic top-level envelope (injecting `format: mageos-workflow-export/1`) before handing it to `WorkflowImporter`, and the template schema `$ref`s the export schema's *property definitions*, not the whole envelope. Localization: `title`/`description` are `oneOf` string \| `{locale: string}` map with `minProperties: 1` — "default locale present" is **runtime** validation (JSON Schema can't know the install's locale), a typed error in the compat stage. Published + semver'd like the sibling schemas; extend the CI json-lint glob, which currently scans `src/**` only, to cover `spec/` |
| `TemplateSourceInterface` + `BundledTemplateSource` | `Model/Template/` (core) | `list()`/`get(code)`; bundled source reads pack directories registered as a di.xml **array argument on `BundledTemplateSource`** (`<argument name="packDirectories" xsi:type="array">` — the `ActionPool` type-array pattern; a third-party pack adds one item). Remote source is a *future implementor*, not scaffolding built now |
| Compatibility checker | `Model/Template/` | Evaluates `requires` (triggers via `TriggerRegistry`, actions via `ActionPool`, `schema ≤ SCHEMA_VERSION`, module presence, edition); returns typed reasons for greyed-out cards |
| Parameter engine | `Model/Template/` | `%param.key%` token substitution (deliberately distinct from runtime `{{ }}`); leftover tokens = hard error before any write. `type` is a **closed set** — `string \| number \| url \| duration \| select \| secret` or `entity:<alias>` — and it drives two things. (a) **Validation, globally:** the engine checks every submitted value on *all* install paths (admin form, REST, CLI), not just in the browser — `number` numeric and within the parameter's `min`/`max`, `duration` parseable as a `\DateInterval`, `url` an absolute `http`/`https` URL, and `entity:*` existence-checked against its option source (skipped when that source's pack is not installed). Values stay strings end to end, so a `number` default is still a JSON string. (b) **Real widgets, not text inputs:** fields render from the **recorded F6 option-source union** (inline `options` > `options_search` > the `entity:*` mapping) — bounded sources become selects rendered from the full, uncapped list (an oversized "bounded" list degrades to the picker rather than truncating), search sources a picker over the admin options proxy, `duration` an amount/unit composite (raw ISO input only as the no-JS/unrepresentable fallback), plus `secret` pick-or-create. `entity:<alias>` resolves through `EntityOptionSourceRegistry` (core, DI array map alias → `{source, bounded?, min_chars?}`: `entity:salesrule` → `cart_price_rules`, `entity:customer_group` → `customer_groups`, …), so a pack registers its aliases beside the sources it owns. Optional `note` (authored help text, replaces the widget-derived note), `min`/`max` (server-validated), `step` (client hint). If 06 lands before 07, this stage builds the `meta/options` endpoint (first-consumer rule) |
| `TemplateInstaller` | `Model/Template/` | Orchestrates: envelope schema-validate → compat check → params → substitution → `WorkflowImporter` (F3, `ADMIN_CONTEXT` mode) → status disabled/shadow → provenance write → **deferred secret creation** (after successful save; failed install leaves no orphaned secrets — review finding) |
| Provenance (F8) | `mageos_workflow_template_install` | template code+version, params snapshot, installer, timestamp; powers "installed from X v1.2" and nothing else in v1 (fork-on-install; no upgrade path — documented) |
| Gallery UI | admin-ui | New adminhtml controllers under the existing route: `Template/Index` (cards), `Template/View` (detail: plain-language rendering of the defaults-substituted body via the core renderer, `requires` panel), `Template/Install` (the parameter form + POST target); "install as shadow" default-on; post-install redirect to edit form with dry-run/enable next-steps. ACL: reuse `MageOS_Workflows::manage` |
| CLI + patch helper | core | `workflow:template:list`, `workflow:template:install <code> --param k=v/--params-file` (SYSTEM mode, same warning as import); `InstallTemplatePatch::forTemplate()` for agency deployments |
| Seed pack | `module-workflows-templates` | 12–15 templates seeded from the discovery §6 candidates (that list is ~10 loose candidates — deliberately not enough to build from). **Stage 5 therefore opens with a reviewed catalog manifest**: per template — code, category, `requires` (triggers/actions/edition), and its ≤3 parameters with types — committed as the stage's first PR before any template is authored. Each template then ships with a CI fixture test: schema-validates, installs against the shim harness pools, dry-run smoke |
| Localization decision | envelope | Per-locale title/description keys decided **before** schema publication (breaking after — discovery §9 Q3); v1: `title`/`description` accept either a string or a `{locale: string}` map, default locale required |

## Stages

| # | Stage | Notes / done-when |
|---|---|---|
| 1 | F3 `WorkflowImporter` extraction (CLI + Save converge) | The load-bearing refactor; independently valuable. **"Behavior-preserving" defined as a per-path matrix, because the two paths genuinely differ today**: CLI import stays create-only, SYSTEM mode (ACL skipped, warning printed), no `website_ids` (they are out-of-envelope — export never emits them; templates install unscoped and scoping is a post-install step, documented); Save keeps upsert + `ADMIN_CONTEXT` + `website_ids` handling (upsert and website assignment stay in the controller, around the importer). Two deliberate strengthenings, called out, not silent: the conditions-shape check now runs on CLI import too, and (from 01 stage 2) GraphCheck runs on both. Done when: CLI import output is byte-identical on both spec fixtures, and this stage absorbs the F2 call that 01 stage 2 wired into `ImportCommand` |
| 2 | Envelope schema + source interface + bundled source + compat checker | Format published. Done when: compat matrix tests pass (missing trigger/action/module/edition/schema-too-new → specific typed reasons) |
| 3 | Parameter engine + `TemplateInstaller` + provenance + CLI + patch helper | Headless install complete. Done when: installer-ordering test proves failed save leaves zero writes (secrets deferred until after save) |
| 4 | Gallery UI + post-install flow | Merchant surface |
| 5 | Catalog manifest (reviewed first PR) → seed pack + per-template CI tests + copy/screenshots | Content; parallelizable with 4 |

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
