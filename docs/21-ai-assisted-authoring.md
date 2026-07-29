# 21 — LLM-Assisted Authoring

Merchants describe automations in English; the definition format is JSON. An LLM is a good
translator between the two — and a bad one to trust unsupervised. This engine happens to
already ship the entire verification harness a machine-generated definition needs, built for
other reasons:

| Existing capability | What it does for generated definitions |
|---|---|
| Published, strict JSON Schemas ([`spec/`](../spec/)) — `additionalProperties: false` throughout | Structural output is checkable, not merely plausible. The rigidity that makes the format unforgiving for humans is exactly what makes machine output verifiable |
| Save-time validation pipeline reachable read-only at `POST /V1/workflows/validate` ([04 §Save-time validation](04-definition-format.md#save-time-validation)) | Server-authoritative semantic check — graph soundness, action codes, condition shape — with stable machine codes an agent can branch on |
| Side-effect-free dry-run, `POST /V1/workflows/dry-run` + `bin/magento workflow:run <id> --entity-id=<n> --dry-run` ([08 §Dry-run](08-execution-model.md#dry-run-synchronous-preview)) | Behavioral check against a *real* entity before anything is installed |
| Install-disabled and shadow-mode semantics ([11 §Shadow mode](11-admin-ui.md#shadow-mode-v1-nearly-free)) | Nothing generated can run without a deliberate human enablement |
| [`PlainLanguageRenderer`](../src/module-workflows/Model/PlainLanguageRenderer.php) | Round-trip check: English intent → generated JSON → rendered English. The merchant reviews a sentence, not a graph |

None of that was built for LLMs. All of it is what LLM-assisted authoring needs. Phases 1–2
of this capability are therefore **documentation and agent instructions over the existing
substrate — zero engine surface**.

## The sanctioned loop

```
describe → discover → generate → schema-validate → dry-run → install DISABLED
        → human reads the plain-language rendering → human enables
```

1. **Discover.** The action, trigger, entity-type, relation, secret-name and option pools are
   DI-registered and installation-specific. An agent reads them from the metadata endpoints
   (`GET /V1/workflows/meta/{actions,triggers,entity-types,relations,secrets,options}`,
   all `MageOS_Workflows::view`, declared in
   [`src/module-workflows/etc/webapi.xml`](../src/module-workflows/etc/webapi.xml)) — never
   from memory, and never from the docs alone. Each action entry carries its `configForm`
   field definitions, which are the authoritative config contract.
2. **Generate.** Definition JSON per [04 — Definition Format](04-definition-format.md) and
   [`spec/workflow-definition.schema.json`](../spec/workflow-definition.schema.json).
3. **Schema-validate.** `POST /V1/workflows/validate` (`::manage`) runs the real save-time
   pipeline without persisting and returns `valid`, `messages[]` (severity, stable code,
   `step_key`, `edge`), and the plain-language rendering. The server is the sole authority —
   clients never re-implement the rules. Note the validate path deliberately **skips**
   per-action ACL re-authorization (it is not an authoring path), so a definition that
   validates can still be rejected on save.
4. **Dry-run.** `POST /V1/workflows/dry-run` (`::dry_run`) against a real entity id — no
   queue, no execution row, no real secrets resolved, secrets redacted in the returned
   interpolated config. `skipped: true` (root conditions did not match) and any
   `would_fail` step are iteration signals, not passes.
5. **Install disabled.** `status = 0`, or `status = 2` (shadow) when the merchant wants a
   week of live-traffic observation first. The CLI paths default to disabled too —
   `workflow:import` and `workflow:template:install` create disabled unless `--activate` is
   passed, which an agent never passes.
6. **Human review.** The plain-language rendering is the review artifact: the merchant reads
   the sentence the engine produces from the generated JSON and confirms it matches what
   they asked for. Warnings travel with the save and must be surfaced verbatim.
7. **Human enables.** Gated by `MageOS_Workflows::enable`
   ([09 §ACL](09-scope-acl-observability.md#acl)). Not an agent action, ever.

## What agents may and may not do

The posture follows [10 — Security Model](10-security.md) exactly: **a generated workflow is
stored intent that will later execute with system privileges**, so it gets the same treatment
as an imported one — no more, and no less.

**May:**

- Read the metadata endpoints, the schemas under `spec/`, existing workflows, and execution
  history (all `::view`).
- Generate and iterate on definition JSON.
- Call `POST /V1/workflows/validate` (`::manage`) and the dry-run endpoints (`::dry_run`).
- Create a workflow **disabled or in shadow** via `POST /V1/workflows` (`::manage`) or
  `bin/magento workflow:import` without `--activate`.
- Explain and audit an existing workflow via the renderer + dry-run.

**May not:**

- **Enable a workflow, or change its status to enabled.** Enabling is a human decision made
  after reading the rendering. Today this is a *rule*, not a gate: `MageOS_Workflows::enable`
  covers the admin grid's mass enable/disable controllers, while the edit-form Save
  controller and the REST save path are both `::manage` — so an authoring credential can
  technically write `status = 1` (see [Residual gaps](#residual-gaps)).
- **Dispatch a real execution** — `bin/magento workflow:run` without `--dry-run`, or the
  admin mass-run. `MageOS_Workflows::dry_run` deliberately does **not** imply `::manual_run`
  ([09 §ACL](09-scope-acl-observability.md#acl)); previewing is not running, and the split
  exists so a preview-only credential is expressible.
- **Touch secret values.** Definitions reference secrets by name only; values are write-only
  and never leave the server ([10 §Secrets](10-security.md#secrets)). `GET
  /V1/workflows/meta/secrets` and `workflow:secret:list` return **names**. An agent never
  runs `workflow:secret:set` / `:delete`, never embeds a literal credential in a definition,
  and never places `{{ secrets.* }}` in an approval gate's `title`/`instructions` (a hard
  save error, `APPROVAL_SECRET_IN_PROMPT`).
- **Propose a definition it has not dry-run.** An unvalidated draft may be shown as a draft;
  it may not be installed.
- **Change the engine.** A request that needs a new action, trigger, or relation is a PHP
  change ([07 §Contract](07-actions.md#contract)), not an authoring task.

### Generated workflows are not privileged

There is no agent-specific code path, and deliberately so. A generated definition:

- goes through the **same `WorkflowRepositoryInterface::save` pipeline** as an admin form
  save, a REST save, a CLI import, and a gallery install
  ([04 §Save-time validation](04-definition-format.md#save-time-validation));
- is **re-authorized per action against the acting admin's ACL** on save — the
  per-action-group gates (`::action_sales`, `::action_customer`, …) apply unchanged, so an
  agent operating under a token that cannot author a cancel-order step cannot generate its
  way around that ([10 §Deferred privilege escalation](10-security.md#deferred-privilege-escalation-the-core-threat-model));
- is subject to the same **execution-time scope re-check** — a workflow whose author lost
  website scope gets suspended, not silently escalated;
- carries the same **import re-authorization** semantics: agent-generated JSON handed to
  `workflow:import` is untrusted input like any other
  ([10 §Import is untrusted input](10-security.md#import-is-untrusted-input)).

Two consequences worth stating plainly:

- **The agent's credential is the blast radius.** Give agent-driven authoring a role scoped
  to `::view` + `::manage` + `::dry_run` and the action groups it should be able to author —
  and **not** `::enable`, **not** `::manual_run`, **not** `::secrets`. The ACL is the
  enforcement; the skill rules are the reminder.
- **CLI import bypasses admin ACL re-authorization.** `bin/magento workflow:import` and
  `workflow:template:install` run with system privileges and do not re-authorize against an
  admin ACL (both commands print this warning themselves —
  [`ImportCommand.php`](../src/module-workflows/Console/Command/ImportCommand.php)). For
  agent-generated definitions, prefer the REST/admin path, where the gates actually fire.

## Prompt-ready format reference

The material an agent needs, in the order it needs it — all of it derived from `spec/` and
verifiable against the code:

| Topic | Source of truth |
|---|---|
| Step graph, step types, edges, `ui` block | [`spec/workflow-definition.schema.json`](../spec/workflow-definition.schema.json), [04](04-definition-format.md) |
| Export envelope (`mageos-workflow-export/1`) | [`spec/workflow-export.schema.json`](../spec/workflow-export.schema.json) |
| Template envelope (`mageos-workflow-template/1`) | [`spec/workflow-template.schema.json`](../spec/workflow-template.schema.json) |
| Worked, schema-conformant examples | [`spec/fixtures/`](../spec/fixtures/) — six real definitions incl. wait, switch, approval, relation conditions |
| Validation codes | `src/module-workflows/Model/Validation/Check/*.php`; tabulated in [04 §Save-time validation](04-definition-format.md#save-time-validation) |
| Interpolation grammar + formatter whitelist | [07 §Variable resolution](07-actions.md#variable-resolution) |
| Condition-tree shape, relations, EAV coverage | [06 — Condition Engine](06-conditions.md) |
| REST/CLI contract shapes | `src/module-workflows/Api/**` (the `@api` interfaces define the field names), `etc/webapi.xml`, `Console/Command/` |
| Revision history of the published format | [`spec/CHANGELOG.md`](../spec/CHANGELOG.md) |

Three format facts cause most machine-generation failures, so state them early in any prompt:

1. **`additionalProperties: false` everywhere.** An invented config key is a hard failure,
   not a warning. Read the action's `configForm` before writing its `config`.
2. **No loops.** A cycle reachable from `entry` is `GRAPH_CYCLE`, an error — the engine has
   no loop semantics ([01 §Non-goals](01-overview.md#non-goals-for-v1)). Terminate paths with
   `null` edges or a `stop` step.
3. **Interpolation supplies values, never structure.** `{{ … }}` is legal in config *values*
   only — never in keys, never as an action code, never as an attribute code
   ([10 §SSRF hardening — trust boundary](10-security.md#ssrf-hardening-the-webhook-action)).

## In-repo agent skills

Two skills ship in [`.claude/skills/`](../.claude/skills/), so an agent working in a checkout
of this repo — or in a customer project vendoring it — picks up the discipline automatically
rather than inferring it:

- **`workflow-authoring`** — the format, pool discovery, the validate → dry-run →
  install-disabled loop, and the hard rules (never enable, never touch secrets, always
  dry-run before proposing, never invent codes, never change the engine). Carries two
  reference files: `reference/definition-format.md` (step types, edges, interpolation,
  condition trees, validation codes) and `reference/api-surface.md` (every route and command
  with its ACL).
- **`workflow-review`** — read-only. Explains an existing workflow via the plain-language
  renderer plus a dry-run, and flags the review-worthy properties: enablement state,
  irreversible actions, outbound webhooks and captured-response usage, stale-snapshot
  branches, long parks, approval-timeout consequences, fan-out blast radius, and referenced
  secret names.

The skills are instructions, not enforcement. The ACL is the enforcement.

## Round-trip review, concretely

The renderer makes generated JSON auditable without reading JSON:

> *When Order Created, if 2 conditions, then: Add Order Comment, wait 1 hour, stop.*

Ask the merchant to compare that sentence to what they originally said. It is a weaker check
than reading the graph and a far stronger one than reading nothing — and it is the check
merchants will actually perform. Two caveats worth passing on: the renderer caps at 25 steps,
and it is deliberately defensive — a clause it cannot parse is **omitted**, not errored
([`PlainLanguageRenderer`](../src/module-workflows/Model/PlainLanguageRenderer.php) is called
from grid rendering, where throwing would break the listing). So a suspiciously short
sentence is itself a finding.

## Residual gaps

Honest accounting of where the harness stops short of the posture above. None is a blocker
for skills-and-docs; all are worth knowing before pointing an agent at production.

- **No ACL gate on "enable" outside the grid.** As noted above, `::manage` can write
  `status = 1` over REST and from the edit form; `MageOS_Workflows::enable` only gates
  `MassEnable`/`MassDisable`. If enable-by-agent must be *impossible* rather than
  *forbidden*, that is a save-path change (re-check `::enable` when the status transitions
  toward enabled) — out of scope here, and worth its own issue.
- **Skills are advisory.** An in-repo `SKILL.md` shapes behavior; it does not constrain it.
  The ACL, the validation pipeline, and install-disabled defaults are the controls that hold
  when the instructions are ignored or absent.
- **Dry-run fidelity is not execution fidelity.** A dry-run walks the graph against one
  entity: it does not exercise queue redelivery, real webhook responses, delay/wait resumes,
  or approval decisions. Shadow mode over live traffic is the stronger check before
  enablement, and the plain-language rendering is a summary — it caps at 25 steps.
- **`triggerPayload` dry-runs are snapshot-only.** Synthetic payloads cannot exercise
  hydration-requiring conditions (relations, EAV, customer aggregates), so a CI-only
  dry-run proves less than one against a real entity id.
- **CLI paths run as system.** `workflow:import` / `workflow:template:install` do not
  re-authorize actions against an admin ACL, so an agent with shell access has more
  authoring reach than the same agent with a scoped integration token.

## Future work (not in scope here)

Tracked on [issue #11](https://github.com/rhoerr/magento-workflows/issues/11); to be spun out
separately if pursued:

- An **MCP server** wrapping the existing REST CRUD / validate / dry-run endpoints, so
  agencies can drive authoring from Claude or IDE tooling without hand-rolling HTTP. It adds
  no engine surface — it is a transport over routes that already exist, and it would inherit
  the same ACL story (the server holds an integration token; the token's role is the blast
  radius).
- A **"describe your automation" box** in the admin template gallery
  ([11 §Template gallery](11-admin-ui.md#template-gallery-marketing--workflow-templates)),
  installing through the same `TemplateInstaller` → `WorkflowImporter` path in
  `ADMIN_CONTEXT` — disabled or shadow, never auto-enabled.

Both are additive. Neither changes the loop above.
