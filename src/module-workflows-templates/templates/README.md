# Workflow template pack

Each `*.json` file in this directory is one gallery template — a
`mageos-workflow-template/1` envelope validated by
[`spec/workflow-template.schema.json`](../../../spec/workflow-template.schema.json).
`MageOS\Workflows\Model\Template\BundledTemplateSource` scans this directory
(registered via `etc/di.xml`: this module's name is added to the
`BundledTemplateSource::packDirectories` array argument, and the source resolves
it to `<module>/templates`). A third-party pack ships the same way — one module,
one di.xml entry.

## Envelope shape

```jsonc
{
  "format": "mageos-workflow-template/1",
  "template": {
    "code": "abandoned-cart-recovery-coupon",   // kebab-case, unique across the pack
    "title": "Abandoned cart recovery with coupon",   // string OR {"en_US": "...", "de_DE": "..."}
    "description": "Reminds after 4h, follows up with a coupon.",
    "category": "Cart recovery",
    "version": "1.0.0",                          // semver of the template content
    "requires": {
      "schema": 2,                               // min definition schema version
      "triggers": ["sales.order.created"],       // trigger event names (TriggerRegistry)
      "actions": ["notify.email", "marketing.generate_coupon"],  // action codes (ActionPool)
      "modules": [],                             // module names that must be enabled
      "edition": "any"                           // any | community | enterprise | b2b
    },
    "parameters": [
      {"key": "reminder_wait", "label": "Wait before reminder", "type": "duration", "default": "PT4H"},
      {"key": "coupon_rule_id", "label": "Cart price rule", "type": "entity:salesrule", "required": true}
    ]
  },
  "workflow": {
    // The export envelope's FIELDS, minus the `format` tag — the installer lifts
    // these into a synthetic mageos-workflow-export/1 envelope. NOT itself a
    // valid export envelope.
    "name": "Abandoned cart recovery",
    "entity_type": "sales_order",
    "trigger_type": "event",
    "trigger_ref": "sales.order.created",
    "conditions_serialized": null,
    "definition": { "schema": 2, "entry": "...", "steps": { } },
    "loop_guard_depth": 1
  }
}
```

## Authoring rules

- **Parameters** are substituted into the `workflow` body as `%param.<key>%`
  tokens — deliberately **not** the runtime `{{ … }}` syntax. Every token must
  map to a declared parameter or the install fails with a hard error. Types:
  `string`, `select` (with inline `options: [{value,label}]`), `duration`,
  `secret` (references a secret by key name; the value is supplied at install
  time and never stored in the template), and `entity:*` sources (e.g.
  `entity:salesrule`) which render a picker.
- **Localization:** `title`/`description`/parameter `label` may be a plain
  string or a `{locale: string}` map. If a map, include the `en_US` default —
  a template with no copy for the install locale or the default is flagged
  incompatible (a `MISSING_LOCALE` compat reason).
- **Secrets never carry values.** A `secret` parameter names a secret; the
  installer creates/picks it after a successful save.
- Keep templates to **≤ 3 parameters** and pick flows that showcase a distinct
  engine capability (discovery §6).

## CI fixture test (the honesty mechanism)

Each shipped template MUST carry a fixture test — treat a failing template test
like a failing engine test, not content debt. Follow the pattern in
`src/module-workflows/Test/Unit/Model/Template/BundledTemplateSourceTest.php`
and `TemplateInstallerTest.php`:

1. **Schema-validate** — the envelope parses and matches
   `spec/workflow-template.schema.json` (structure; the standalone runner asserts
   the `template`/`workflow` split and format tag).
2. **Compat against the real pools** — `CompatibilityChecker` reports the
   template compatible against `ActionPool` / `TriggerRegistry` (this is what
   keeps the catalog honest as actions evolve).
3. **Install against the shim harness** — `TemplateInstaller` with fake importer/
   provenance/secrets: default-substituted body lifts into a valid export
   envelope with no leftover tokens.
4. **Dry-run smoke** — the substituted definition walks through `DryRunService`
   without error (see the dry-run test fixtures).

The catalog manifest (per-template code, category, `requires`, ≤3 params) lands
as the **first** PR of the seed-pack stage, before any template is authored.
