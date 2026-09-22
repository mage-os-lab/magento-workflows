---
name: workflow-authoring
description: Author a MageOS_Workflows workflow definition (trigger → conditions → step graph) for this Magento/Mage-OS store, and take it through the mandatory generate → validate → dry-run → install-disabled loop. Use whenever someone asks to build, generate, draft, or change a workflow/automation ("when an order is created, if …, then …"), or to turn a plain-English automation request into workflow definition JSON. Not for explaining an existing workflow — use workflow-review for that.
---

# Authoring a workflow definition

You produce **definition JSON**, not code. The engine's whole safety story assumes you
follow the loop below in order and stop at the human.

## Hard rules — no exceptions

1. **Never enable a workflow.** You install disabled (`status: 0`) or, if the human
   explicitly asks for live-traffic observation, shadow (`status: 2`). Never pass
   `--activate` to `workflow:import` / `workflow:template:install`. Note that the REST save
   path is gated by `::manage`, not by `MageOS_Workflows::enable` (which only covers the
   admin grid's mass enable/disable) — so writing `status: 1` is *technically* possible with
   an authoring credential. It is still forbidden. Enabling is a human decision made after
   reading the plain-language rendering.
2. **Never read, write, guess, or echo a secret value.** Definitions reference secrets by
   **name only** — `{{ secrets.<name> }}`. Discover names from
   `GET /V1/workflows/meta/secrets` (names only; values never leave the server) or
   `bin/magento workflow:secret:list`. Never run `workflow:secret:set`, and never put a
   literal API key/token/password into a definition. Never put `{{ secrets.* }}` into an
   `approval` step's `title`/`instructions` — that is a hard save error
   (`APPROVAL_SECRET_IN_PROMPT`).
3. **Always dry-run before proposing.** A definition you have not validated *and*
   dry-run against a real entity is a draft you may show, not a workflow you may install.
4. **Never invent action codes, trigger events, entity types, condition attributes, or
   config keys.** Read them from the metadata endpoints (step 1). An unregistered action
   code is a save error (`ACTION_UNKNOWN`); an invented config key fails
   `additionalProperties: false` in the schema.
5. **Do not touch the engine.** Authoring is a data activity. If a request needs a new
   action, trigger, or relation, say so and stop — that is a PHP change, not a workflow.
6. **Surface, never suppress, warnings.** Validation warnings travel with the save. Report
   every finding (code + message + step) to the human verbatim.

## The loop

### 1 — Discover what actually exists on *this* install

Never author from memory; the pools are DI-registered and installation-specific.

| What | Endpoint (ACL `MageOS_Workflows::view`) |
|---|---|
| Actions (code, label, group, applicable entities, config form, ACL resource) | `GET /V1/workflows/meta/actions[?entityType=sales_order]` |
| Triggers (event, entity, label, group) | `GET /V1/workflows/meta/triggers` |
| Entity types (code, label) | `GET /V1/workflows/meta/entity-types` |
| Relations for cross-entity conditions / fan-out | `GET /V1/workflows/meta/relations` |
| Secret **names** | `GET /V1/workflows/meta/secrets` |
| Option values for a config field | `GET /V1/workflows/meta/options?source=<code>&query=<text>` |

The `configForm` field on each action entry is a JSON **string** — parse it to learn the
action's legal config keys, types, and option sources. That is the authoritative config
contract; `docs/07-actions.md` is prose about it.

If you cannot reach the REST API, fall back to reading `src/*/etc/di.xml` (`ActionPool`
type-arrays) and `src/*/etc/workflow_triggers.xml` in the repo, and **say** that you did —
a repo read tells you what the code supports, not what this store has installed.

### 2 — Generate the definition

Follow `reference/definition-format.md` in this skill. Non-negotiables:

- `{"schema": 4, "entry": "<step key>", "steps": {…}}`; every step object is
  `additionalProperties: false`, so an extra key is a hard failure.
- Terminate every path — the last step's `next` (or `on_*` edge) is `null`, or a `stop`
  step. Terminal edges are normal; dangling references to a non-existent key are not.
- **No cycles.** The engine has no loop semantics; a reachable cycle is `GRAPH_CYCLE`,
  an error.
- Any `branch`/`switch` after a `delay` or `approval` must set `revalidate_entity: true`
  unless the human explicitly wants the frozen trigger snapshot (otherwise:
  `GRAPH_POST_DELAY_STALE`).
- Interpolation is `{{ trigger.* }}`, `{{ steps.<key>.* }}`, `{{ workflow.* }}`,
  `{{ secrets.<name> }}` in **values only**, never in keys and never as an action code or
  attribute code.
- Prefer the smallest graph that satisfies the request. Do not add "helpful" extra
  notifications, comments, or delays nobody asked for — every step is a side effect on a
  production store.

### 3 — Schema-validate (server is the only authority)

```
POST /V1/workflows/validate          # ACL: MageOS_Workflows::manage
{"definition": "<definition JSON as a STRING>",
 "conditionsSerialized": "<root condition tree JSON or null>",
 "triggerType": "event", "triggerRef": "sales.order.created",
 "entityType": "sales_order"}
```

Runs the real save-time pipeline without persisting. Response:
`valid` (bool — warnings do **not** invalidate), `messages[]`
(`severity`, `code`, `message`, `stepKey`, `edge`), `plainLanguage` (the merchant
sentence). Note this call skips per-action ACL re-authorization — it is not an authoring
path, so a definition that validates here can still be rejected on save with
`ACTION_UNAUTHORIZED`.

Never hand-roll the rules client-side. If `valid` is false, fix and re-post. Do not
proceed to step 4 with errors outstanding.

### 4 — Dry-run against a real entity

```
POST /V1/workflows/dry-run           # ACL: MageOS_Workflows::dry_run
{"definition": "<JSON string>", "entityType": "sales_order",
 "conditionsSerialized": "<JSON string or null>", "entityId": 12345}
```

Side-effect-free: no queue, no execution row for unsaved definitions, no real secrets
resolved. Use a **real** entity id that plausibly matches the conditions (ask the human
for one; there is no entity search in this API). `triggerPayload` (a synthetic object,
snapshot-only fidelity) is a CI fallback, not a substitute — it cannot exercise
hydration-requiring conditions.

Response: `valid`, `skipped` (root conditions did not match — that is a *finding*, not a
pass), `truncated`, `messages[]`, `steps[]` where each entry carries `stepKey`, `type`,
`status` (`would_run` | `would_fail` | `skipped` | `production_stops_here`), `would`
(plain-language), `edgeTaken`, `notes[]`, and JSON-string `config` (interpolated,
secrets redacted), `condition`, `timing`.

Read the trace. `would_fail` anywhere, or `skipped: true`, means iterate — do not present
it as working. For a saved workflow, the CLI equivalent is
`bin/magento workflow:run <id> --entity-id <n> --dry-run`.

### 5 — Install **disabled**

Only after a clean validate and a dry-run the human has seen.

- REST: `POST /V1/workflows` with `status: 0` (disabled) — or `2` (shadow) if asked.
  ACL `MageOS_Workflows::manage`; every referenced action is re-authorized against the
  acting admin.
- CLI: write a `mageos-workflow-export/1` envelope and
  `bin/magento workflow:import <file>` — **no `--activate`**. Note the CLI import path runs
  with system privileges and does *not* re-authorize actions against an admin ACL; prefer
  REST or the admin UI when an ACL boundary matters, and say which path you used.

### 6 — Hand off to the human

End your turn with:

- the **plain-language rendering** from the validate response (the round-trip check: does
  the English that came back match the English they asked for?),
- the dry-run trace summary,
- every warning, and
- an explicit statement that the workflow is **disabled** and that **they** must review and
  enable it.

Then stop. Do not enable, do not run it, do not "just test it live".

## Reference

- `reference/definition-format.md` — step types, edges, interpolation, condition trees,
  validation codes.
- `reference/api-surface.md` — every endpoint and CLI command with its ACL, verified
  against `src/module-workflows/etc/webapi.xml` and `Console/Command/`.
- Project docs and schemas live in the source repository, not in a Magento install — read
  them there (or on GitHub) when this skill is installed into a store: `docs/04-definition-format.md`,
  `docs/05-triggers.md`, `docs/06-conditions.md`, `docs/07-actions.md`, `docs/10-security.md`,
  `docs/21-ai-assisted-authoring.md`; machine-readable `spec/workflow-definition.schema.json`,
  `spec/workflow-export.schema.json`, `spec/workflow-template.schema.json`, and worked
  examples in `spec/fixtures/`.
- The installation itself always outranks both: the metadata endpoints are the authority on
  which actions, triggers and options actually exist here.
