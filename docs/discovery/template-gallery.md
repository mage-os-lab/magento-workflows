# Discovery — Template Gallery

**Status:** Discovery / evaluation · **Feeds:** Phase 3 planning ([13 — Delivery Plan](../13-delivery-plan.md))
**Related:** [04 — Definition Format](../04-definition-format.md) · [10 — Security §Import](../10-security.md#import-is-untrusted-input) · [17 — Use Cases](../17-use-cases.md) · [01 — Overview §Strategy](../01-overview.md#strategy-open-spec-commercial-layers) · [dry-run.md](dry-run.md)

---

## 1. Goal and current state

A merchant opens a gallery, picks "Abandoned cart recovery with coupon", answers three questions
(which email template, which cart price rule, how long to wait), and gets a working workflow —
installed **disabled or in shadow mode** — that they can dry-run, inspect in plain language, and
enable. Agencies get the same via CLI/data patches.

Everything below the UI already exists (verified against source):

| Mechanism | State | Where |
|---|---|---|
| Export envelope (`mageos-workflow-export/1`: name, entity_type, trigger, conditions, definition, loop_guard_depth) | ✅ | `src/module-workflows/Console/Command/ExportCommand.php` (~87–96) |
| Import validation (envelope format, `Definition::fromArray`, unknown action codes rejected, `--activate` opt-in, installs disabled by default) | ✅ CLI | `Console/Command/ImportCommand.php` (~108–176) |
| ACL re-authorization of imported action codes | ✅ in admin Save (`authorizeActionCodes`, `Save.php:232–247`); ⚠️ deliberately skipped in CLI (system context, warning printed) | — |
| Plain-language rendering for previews | ✅ | `src/module-workflows-admin-ui/Model/PlainLanguageRenderer.php` |
| Capability introspection for compatibility checks | ✅ `ActionPool` (registered actions), `TriggerRegistry` (available triggers) | `Model/Action/ActionPool.php`, `Model/Trigger/TriggerRegistry.php` |
| Sample definitions | 2 spec fixtures only — not shipped seed data | `spec/fixtures/` |
| Gallery UI, template format, parameterization, remote feed | ❌ none | — |

The delivery decision is therefore: **template format** (a thin layer over the export envelope),
**distribution model**, **parameterization**, and **UI** — the install pipeline is the existing
import path.

## 2. Template format

Extend — don't fork — the export envelope. New format tag `mageos-workflow-template/1`:

```json
{
  "format": "mageos-workflow-template/1",
  "template": {
    "code": "abandoned-cart-recovery-coupon",
    "title": "Abandoned cart recovery with coupon",
    "description": "Reminds the customer after 4h, follows up next morning with a coupon.",
    "category": "Cart recovery",
    "version": "1.2.0",
    "requires": {
      "schema": 2,
      "triggers": ["quote.abandoned", "sales.order.created"],
      "actions": ["notify.email", "marketing.generate_coupon"],
      "modules": [],
      "edition": "any"
    },
    "parameters": [
      {"key": "reminder_wait", "label": "Wait before first reminder", "type": "duration", "default": "PT4H"},
      {"key": "coupon_rule_id", "label": "Cart price rule for the coupon", "type": "entity:salesrule", "required": true},
      {"key": "fraud_webhook_secret", "label": "Webhook HMAC secret", "type": "secret", "optional": true}
    ]
  },
  "workflow": { …exact export-envelope fields: name, entity_type, trigger_type, trigger_ref,
                conditions_serialized, definition, loop_guard_depth… }
}
```

Design points:

- **`workflow` is a verbatim export envelope body** — one parser/validator for both; a template
  is "an export plus a face". Exporting an existing workflow "as template" becomes a trivial
  authoring path for agencies.
- **`requires` is checked before the install button is enabled**: every trigger in
  `TriggerRegistry`, every action in `ActionPool`, `schema` ≤ engine's `SCHEMA_VERSION`, module
  presence (e.g. MSI for `product.set_stock` with `source_code`), edition for Commerce-only
  actions. Incompatible templates render greyed-out with the *reason* ("requires the B2B pack") —
  which doubles as ecosystem marketing for connector packages.
- **`parameters` use install-time substitution tokens** in the workflow body —
  `%param.reminder_wait%` — deliberately **not** the runtime `{{ … }}` syntax, so template
  authoring can't be confused with (or smuggle values into) runtime interpolation, and an
  un-substituted token is a hard validation error rather than a silently-empty runtime variable.
  Token type drives the install form: `duration` → the delay-builder widget, `entity:salesrule`
  → a rule picker, `secret` → pick-or-create against the secrets store ([10 §Secrets](../10-security.md#secrets)),
  `string`/`select` as expected. Post-substitution, the resulting envelope goes through the
  **full untrusted-import validation** — substitution never bypasses schema/action/ACL checks.
- The template JSON Schema is published and semver'd in `spec/` alongside the definition and
  export schemas — per the open-spec strategy, third parties should be able to author templates
  from day one; the format is the product surface, the curated *catalog* is the commercial layer
  ([01 §Strategy](../01-overview.md#strategy-open-spec-commercial-layers)).

## 3. Distribution model — the strategic decision

### Option G1 — Bundled-only ("starter pack" module)

Templates are JSON files shipped in a new `mage-os/workflows-templates` module; the gallery reads
them from disk (`etc/templates/*.json` via a reader, or virtual-type registration for third-party
packs). Updates arrive via composer like everything else.

- ✅ Fully on-prem, zero network dependency — coherent with the engine's core positioning.
- ✅ Templates are code-reviewed, version-pinned, tested in CI against the engine version they
  ship with. Trust model = composer trust model, already accepted.
- ✅ Third parties ship template packs the same way they ship actions (a `di.xml`/reader
  registration) — the connectors-program story stays uniform.
- ❌ Catalog grows only with releases; no telemetry on popularity; weak commercial surface.

### Option G2 — Remote gallery (hosted index)

Admin fetches a JSON index + template blobs from a mage-os-operated endpoint, cached locally.

- ✅ Catalog evolves without releases; install counts/curation possible; natural commercial layer
  (premium templates behind a key).
- ❌ A remote content feed into an engine that executes stored intent with system privileges is a
  **supply-chain surface** ([10 — Security](../10-security.md)): it demands index signing
  (detached signature over the index, pinned public key shipped in-module — TLS alone is not
  sufficient against a compromised host), per-template checksums, and an operated, available,
  versioned service. That's real ongoing cost for an org shipping an on-prem product.
- ❌ Air-gapped/proxy-restricted installs (a real segment for this audience) see an empty gallery.

### Option G3 — Hybrid: bundled pack now, signed remote feed later (recommended)

Ship G1 as the Phase-3 deliverable with the reader/UI deliberately shaped so a remote,
signed source can register as *a second template source* behind a config toggle later:

```php
interface TemplateSourceInterface {
    public function list(): TemplateSummary[];   // code, title, category, requires, version
    public function get(string $code): string;   // raw template JSON (validated downstream)
}
```

`BundledTemplateSource` ships now; a future `RemoteTemplateSource` implements the same contract
plus signature verification, and the gallery UI/install pipeline don't change. This defers the
signing/hosting investment until the catalog and demand justify it, without painting the
architecture into a corner.

**Evaluation summary:**

| | G1 bundled | G2 remote | G3 hybrid |
|---|---|---|---|
| On-prem coherence | ✅ | ⚠️ | ✅ (remote optional) |
| Supply-chain risk now | none | high | none |
| Catalog velocity | release-bound | live | release-bound now, live later |
| Commercial layer | weak | strong | deferred, preserved |
| Ops burden | none | index hosting + signing keys | none now |

## 4. Install pipeline

One code path for all entry points, extracted from what `ImportCommand` and `Save` each half-own
today:

```
TemplateInstaller
  1. parse + JSON-Schema-validate template envelope
  2. compatibility check (requires.*)            → typed errors for the UI
  3. collect parameters (UI form / CLI --param / data-patch array)
  4. substitute %param.*% tokens (fail on leftovers)
  5. hand the resulting export envelope to a shared WorkflowImporter:
       Definition::fromArray → GraphValidator (branching.md §2)
       → unknown-action rejection → ACL re-authorization (admin context)
       → create with status = disabled (default) or shadow (checkbox, recommended-on)
  6. record provenance: template code + version + parameters snapshot on the workflow
     (new columns or a small mageos_workflow_template_install table)
```

Notes:

- **Extracting `WorkflowImporter` into `module-workflows` is the load-bearing refactor** — today
  import validation lives in the CLI command and ACL re-auth lives in the admin controller;
  gallery install needs both, in admin context. CLI `workflow:import` and the Save controller
  both converge on it (behavior preserved: CLI keeps its documented ACL-skip-with-warning).
- **Never auto-enable.** Install lands disabled (or shadow); the success screen offers
  *Dry-run* ([dry-run.md](dry-run.md)) and *Enable* as next steps. This is the single most
  important safety property of the gallery — combined with dry-run it converts "one-click
  install" from a liability into the trust on-ramp.
- **Provenance** enables "installed from template X v1.2" on the grid, and later "template has
  an update" diffing (explicit non-goal for v1 — merchants fork on install; an updated template
  is a new install, not an upgrade path. Document this).
- **Secrets:** a `secret`-typed parameter never carries a value in the template (exports already
  guarantee this); the install form creates/picks the named secret via the existing secrets ACL.

## 5. Gallery UI

Deliberately boring Magento adminhtml — no new frontend technology (that budget belongs to the
canvas):

- **Marketing → Workflow Templates**: card grid (title, category, description, compatibility
  badge), category filter. Server-rendered blocks; no ui_component grid needed for ~15–50 items.
- **Detail view**: plain-language rendering of the template's workflow (reuse
  `PlainLanguageRenderer` on the substituted-with-defaults body), step list, `requires` panel,
  parameter form, "Install as shadow" checkbox (default on).
- **Post-install**: redirect to the workflow edit form with a "next steps" notice (dry-run /
  review / enable).
- **CLI**: `workflow:template:list`, `workflow:template:install <code> --param key=value…`
  (+ `--params-file`), sharing `TemplateInstaller`. Data-patch helper
  (`InstallTemplatePatch::forTemplate('code', [...params])`) for agency deployments.
- ACL: reuse `MageOS_Workflows::manage` for install (it creates a workflow; the per-action
  authoring gates inside the importer still apply individually). No new resource needed.

## 6. Seed catalog (v1 content)

Content is half this feature's value. Source the initial 12–15 from
[17 — Use Cases](../17-use-cases.md), biased toward flows that showcase distinct engine
capabilities and need ≤ 3 parameters — candidates: abandoned-cart recovery (wait step,
business-day delay, coupon), high-value-order fraud hold (webhook capture + branch), VIP
auto-group-assignment (customer aggregates), post-purchase review request, stock-threshold
supplier webhook, new-customer welcome series, order-stuck-in-processing escalation, refund
follow-up, B2B-ish net-terms reminder (edition-gated example), GDPR anonymize-on-request
(demonstrates `confirm: true` friction deliberately). Each template gets: a conformance-style
fixture test (validates against schema; installs against the shim harness; dry-run path
assertions), and a screenshot/plain-language blurb.

## 7. Quality, maintainability, reliability

- **Security:** template install is *exactly* the untrusted-import trust boundary from
  [10 §Import](../10-security.md#import-is-untrusted-input) — same schema validation, same
  unknown-action rejection, same ACL re-auth, now guaranteed-uniform via `WorkflowImporter`.
  Bundled distribution adds no new remote surface; the future remote source is quarantined
  behind `TemplateSourceInterface` + signature verification and a config toggle.
- **Reliability:** parameter substitution failures abort before any write, and an install whose
  `secret` parameters all reference *existing* secrets is a single workflow save — no partial
  state beyond what save already has. The pick-**or-create** secret path is the exception: secret
  creation is a separate write to `mageos_workflow_secret` (`ConfigSecretsProvider::set()`), so
  the installer orders it *after* successful workflow save (a definition referencing a
  not-yet-created secret is valid — secrets resolve at run time) and surfaces a "create these
  secrets" follow-up on failure, rather than leaving orphaned secrets from a failed install.
  Templates in the bundled pack are CI-tested against the engine version they ship with (the
  compat matrix is composer's, not a runtime guess).
- **Maintainability:** the format layers on the export envelope (one validator lineage); the
  catalog is data, not code — adding a template touches no PHP. Watch-item: templates reference
  action codes and trigger names as strings; the CI fixture test (installs against the real
  pools) is what keeps the catalog honest when actions evolve.
- **Ecosystem/openness:** template schema published in `spec/`; third-party packs register a
  `TemplateSourceInterface` (or drop files into the reader path) — the gallery is a surface for
  the connectors program, not just first-party content.

## 8. Sequencing & effort

| Order | Item | Effort |
|---|---|---|
| 1 | `WorkflowImporter` extraction (shared CLI/admin/gallery pipeline) + tests | ~1 wk |
| 2 | Template format + JSON Schema in `spec/` + `TemplateSourceInterface` + bundled reader | ~1 wk |
| 3 | `TemplateInstaller` (compat check, parameters, substitution, provenance) + CLI + patch helper | ~1–1.5 wk |
| 4 | Gallery UI (cards, detail, install form, post-install flow) | ~1.5 wk |
| 5 | Seed catalog: 12–15 templates + fixture tests + copy | ~1.5–2 wk |

Total ≈ 6–7 wks. Hard dependency: none. Strong soft dependencies: GraphValidator
([branching.md](branching.md) §2 — imported graphs deserve the same safety) and
[dry-run](dry-run.md) shipping first (the install→dry-run→enable flow is the intended UX).
The canvas is *not* a dependency — plain-language previews carry the gallery; canvas thumbnails
are a later polish item.

## 9. Open questions

1. Package placement: new `mage-os/workflows-templates` module (gallery UI + starter pack
   together) vs gallery UI in `workflows-admin-ui` + content-only pack. Leaning content-only
   pack + UI in admin-ui, so the UI ships even where the pack is trimmed, and third-party packs
   are peers of the first-party one.
2. Template *update* semantics — fork-on-install is v1; is "notify when installed template has a
   newer version" worth the provenance plumbing in v1? Leaning: record provenance now (cheap),
   build nothing on it yet.
3. Localization of template copy (title/description) — `__()` won't reach JSON. Likely a
   per-locale key structure in the template envelope; decide before the schema is published
   since it's a breaking change after.
4. Remote-feed signing scheme (when G3's second source activates): minisign/ed25519 detached
   signature over the index vs signed individual templates. Defer, but pick before any remote
   fetch code exists.
