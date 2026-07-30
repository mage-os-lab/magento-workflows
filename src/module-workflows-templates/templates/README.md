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
      "schema": 4,                               // min definition schema version
      "triggers": ["sales.order.created"],       // trigger event names (TriggerRegistry)
      "actions": ["notify.email", "marketing.generate_coupon"],  // action codes (ActionPool)
      "modules": [],                             // module names that must be enabled
      "edition": "any"                           // any | community | enterprise | b2b
    },
    "parameters": [
      {"key": "reminder_wait", "label": "Wait before reminder", "type": "duration", "default": "PT4H"},
      {"key": "coupon_rule_id", "label": "Cart price rule", "type": "entity:salesrule", "required": true},
      {"key": "min_total", "label": "Minimum cart total", "type": "number", "min": 0, "default": "50",
       "note": "Carts below this never enter the flow."}   // note replaces the widget-derived hint
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
    "definition": { "schema": 4, "entry": "...", "steps": { } },
    "loop_guard_depth": 1
  }
}
```

## Authoring rules

- **Parameters** are substituted into the `workflow` body as `%param.<key>%`
  tokens — deliberately **not** the runtime `{{ … }}` syntax. Every token must
  map to a declared parameter or the install fails with a hard error. Their
  `type` comes from a closed set — see [Parameter type
  contract](#parameter-type-contract) below.
- **Localization:** `title`/`description`/parameter `label` (and `note`) may be
  a plain string or a `{locale: string}` map. If a map, include the `en_US`
  default — a template with no copy for the install locale or the default is
  flagged incompatible (a `MISSING_LOCALE` compat reason).
- **Secrets never carry values.** A `secret` parameter names a secret; the
  installer creates/picks it after a successful save.
- Keep templates to **≤ 3 parameters** and pick flows that showcase a distinct
  engine capability (discovery §6).

## Parameter type contract

`type` is a **closed set**: one of `string`, `number`, `url`, `duration`,
`select`, `secret`, or an `entity:<alias>` source matching
`^entity:[a-z0-9_]+$`. Anything else fails schema validation. Each type picks
the install-form widget *and* the server-side value check — and that check runs
on **every** install path (admin form, REST, `workflow:template:install`), not
just in the browser:

| `type` | Widget | Server-side validation |
|---|---|---|
| `string` | Text input | None (free text) |
| `number` | Number input (`min`/`max`/`step` applied) | Numeric, and within `min`/`max` when declared |
| `url` | URL input | `FILTER_VALIDATE_URL` plus an `http`/`https` scheme |
| `duration` | Composite (days/hours/minutes) with an ISO-8601 escape hatch | Parses as a `\DateInterval` (e.g. `PT4H`, `P3D`) |
| `select` | Select over the declared inline `options` | — |
| `secret` | Pick-or-create secret name (value never stored in the template) | — |
| `entity:<alias>` | Bounded select or searchable picker, decided by the registry entry | The value must exist in the mapped option source (skipped when that source's pack is not installed) |

**Values are strings end to end.** A `number` default is still a JSON string
(`"default": "50"`, not `50`) — the substituted token lands in a definition
that is JSON text.

Optional companions:

- `note` — authored help text under the field. It **replaces** the note the
  widget would derive on its own, so only add one when it says something the
  widget cannot (what actually happens to matching records, what an endpoint
  receives). Same localizedText shape as `label`.
- `min` / `max` — numbers, meaningful for `number` parameters; **validated
  server-side**.
- `step` — number > 0, a **client-side hint only** (input granularity); never
  enforced server-side.
- `required` — boolean, default `false`. (There is no `optional` property; it
  was removed from the schema — `required` was always the only flag read.)

**Option-source precedence** (F6 union), highest first:

1. inline `options: [{value, label}]`
2. `options_search: {source, min_chars}`
3. the `entity:*` type's registry mapping

The first one present wins; the shipped pack uses only #3.

`entity:*` aliases resolve through
`MageOS\Workflows\Model\Option\EntityOptionSourceRegistry` (core, a DI array
map of alias → `{source, bounded?, min_chars?}`). Each pack registers the
aliases for the sources it owns:

| Alias | Option source | Registered by |
|---|---|---|
| `entity:salesrule` | `cart_price_rules` (searchable) | `mage-os/workflows-sales` |
| `entity:order_status` | `order_statuses` (bounded) | `mage-os/workflows-sales` |
| `entity:customer_group` | `customer_groups` (bounded) | `mage-os/workflows-customer` |
| `entity:website` | `websites` (bounded) | `mage-os/workflows-catalog` |
| `entity:email_template` | `email_templates` (searchable) | `mage-os/workflows` (core) |

A third-party pack adds its own alias the same way. An alias with no registry
entry is still schema-valid: the form degrades to a plain text input and the
existence check is skipped, so a template referencing an uninstalled pack's
source still installs.

## CI fixture test (the honesty mechanism)

Each shipped template MUST carry a fixture test — treat a failing template test
like a failing engine test, not content debt. Follow the pattern in
`src/module-workflows/Test/Unit/Model/Template/BundledTemplateSourceTest.php`
and `TemplateInstallerTest.php`:

1. **Schema-validate** — the envelope parses and matches
   `spec/workflow-template.schema.json` (structure; the standalone runner asserts
   the `template`/`workflow` split and format tag). There is no JSON-Schema
   validator in the repo, so `SeedPackFixtureTest` is the de-facto schema gate:
   it also pins every declared parameter `type` to the closed set above and
   fails on any leftover `optional` key.
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
