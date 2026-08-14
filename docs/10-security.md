# 10 — Security Model

This engine executes stored merchant intent later, with system privileges, and can call out to the network. That makes security posture a design input, not a hardening pass. GA blockers are marked as such.

## Deferred privilege escalation (the core threat model)

**Workflows are stored intent executed later with system privileges** — the same class of problem as cron-injected code. Mitigations beyond authoring ACL:

- **Attribute allowlists per action:** the set-customer-attribute action refuses system attributes — `password_hash`, `is_active`, ACL-relevant fields — via a deny-by-default list of attribute codes shipped in config.
- **Scope-check at execution time**, not just authoring time: a workflow scoped to website 1 whose author lost website-1 access gets suspended, not silently escalated. **Status: not implemented** ([#14](https://github.com/rhoerr/magento-workflows/issues/14)). The dispatcher does scope-check the *entity's* website against the workflow's `website_ids` (`Dispatcher::matchesScope`), but that is a different check; no author is recorded on a definition today (`mageos_workflow` has no author column), so there is currently no principal to re-authorize. The only suspension path that exists is the failure-driven `CircuitBreaker`.
- **Authoring ACL** per action group ([Scope & ACL](09-scope-acl-observability.md#acl)): you can't author a step you couldn't perform yourself.

## SSRF hardening (the webhook action)

The webhook action ships **hardened, not hardenable**:

- **HTTPS only by default** (HTTP behind a config flag with warning).
- **DNS pinning:** resolve DNS *then* connect to the resolved IP (defeats rebinding).
- **Private-range denial:** reject private/link-local/loopback ranges (RFC1918, 169.254.0.0/16, ::1, cloud metadata endpoints) unless the host is on an explicit admin-configured allowlist — the same posture Shopify Flow takes.
- **Redirect re-validation + per-hop pinning:** deny redirects across the private-range boundary (Guzzle `on_redirect` re-validation), and pin each redirect hop's connection to the IP that passed validation — the rebinding window stays closed on every hop, not just the first request.
- **Response caps:** 256KB body, JSON depth ≤ 10; parse failures capture `{parse_error: true}` rather than raw bytes.
- **Explicit trust boundary:** captured responses are attacker-influenceable data. They are usable in branch conditions and variable interpolation but **never as action identifiers** (no `{{ steps.x.response.action_code }}` resolving which action runs), **never in attribute codes**, and always type-coerced at the condition comparator. Documented in the SDK: action configs interpolate *values*, never *structure*.
- **Optional response JSON Schema per step** — mismatch = step failure, keeping garbage out of downstream branches.

## Condition-tree instantiation gate

A stored condition tree's node `type` strings reach Magento's condition factory — an
ObjectManager `create()` of a class name from stored data (trust boundary `::manage`, plus
data patches that bypass save-time validation). Before `loadArray`, the evaluator now
refuses any `type` naming an **existing class outside the registered condition surface**
(`ConditionTypeAllowlist`: both pools' combine/leaf classes, the engine's related-entity
combine and trigger-data leaf, plus a di.xml-extensible `additional` list for third-party
condition packs). Inert non-class marker strings still pass — they instantiate nothing and
appear in real trees. The point is that a hostile type's constructor fires *before* any
`instanceof` check could object; the gate runs before the factory ever sees the string.

## Secrets

- Dedicated ACL resource for secret CRUD.
- Values encrypted via `EncryptorInterface`.
- **Write-only in the UI** — never re-displayed.
- Definitions reference secrets **by name only** — exports never contain values.

### Redaction coverage on stored execution detail

Definitions never hold secret values, but *executions* can: step `result`, step `error` and
the execution `context` bag are written by the production executor from **interpolated**
action config, so a webhook URL carrying its token, or a raw HTTP-client exception message,
lands in them verbatim. Every surface that shows those fields to a human runs them through
one shared filter — `StepDetailRedactor`, fed the server-side secret map by
`ExecutionDetailRedactor` — which masks known secret values as `***<name>***` (matched both
as plaintext and in their JSON-escaped form, because the fields are stored as JSON) and then
masks generic credential shapes (URL userinfo, `Bearer` tokens, `token=`/`password=`/
`api_key=` pairs) as defence in depth.

All four read paths are covered, and all of them are reachable at the *weakest* grant,
`MageOS_Workflows::view`:

| Path | Where it redacts |
| --- | --- |
| `GET /V1/workflow-executions/:id/steps` (and the canvas overlay controller that delegates to it) | `WorkflowExecutionStepsProvider` |
| `GET /V1/workflow-executions/:id` | `WorkflowExecutionReader` |
| `GET /V1/workflow-executions` | `WorkflowExecutionReader` |
| Admin execution-detail page | `WorkflowsAdminUi\Block\Adminhtml\Execution\View` (in the block, not the template) |

The REST redaction sits in a **read model in front of the repository**, never in the
repository: `WorkflowExecutionRepositoryInterface` is also how the engine loads executions
to resume them, and a masked context would resume the workflow against corrupted data.
`definition_snapshot` is deliberately *not* redacted — it holds secret *names* only.

Redaction into ES documents by key prefix is a separate, unbuilt control (see
[PII containment](#pii-containment) #2); nothing indexes executions today.

## Import is untrusted input

- Validate against the published JSON Schema.
- Reject unknown action codes.
- **Re-authorize against the importing admin's ACL** — an imported definition containing actions the importer can't author fails loudly.
- The same check applies to programmatic creation via data patches (documented: patches run as system; agencies own that risk).

## Subscription ownership

The hidden async-events subscriptions created for event triggers are owned via their **recipient URL**: `workflow:<id>` (and `workflow:<id>:wait:<event>` for wait resumes) is both the dispatch routing key and the ownership marker — there is no separate owner field. `SubscriptionOwnershipPlugin` **refuses mutation of owned subscriptions** (checking both the incoming and the persisted recipient), preventing an out-of-band edit via the async-events admin UI or REST API from redirecting a workflow's event stream. Pinned by `SubscriptionOwnershipPluginTest`.

## PII containment

Execution `context` holds entity snapshots (names, emails, addresses). Three controls — **GA blockers, not fast-follows** (retrofitting redaction into an existing ES index is miserable):

1. **TTL pruning cron** — default 90 days, configurable down to hours.
2. **Field-level redaction config** applied before ES indexing — index metadata + IDs by default, full payload opt-in. **Status: not implemented** ([#16](https://github.com/rhoerr/magento-workflows/issues/16)) — and neither is the thing it guards: execution records are not indexed into ES at all yet (that work sits in phase 2 of the [delivery plan](13-delivery-plan.md)). Nothing is leaking to an index today; the requirement is that the redaction layer lands *with* the indexing, not after it.
3. **GDPR erasure hook** into `CustomerRepository::delete` / `deleteById` (`CustomerErasureScrubPlugin` in `mage-os/workflows-customer`, backed by `ExecutionPiiScrubber`): after a successful deletion, executions rooted on the deleted customer (workflow `entity_type=customer`, matching `entity_id`) have their context trigger snapshot and step outputs replaced wholesale with a `{"gdpr_redacted": true}` marker, and executions of *any* entity type whose context carries the customer's email (order/quote snapshots' `customer_email`, address `email` fields) get targeted redaction — every email occurrence plus the person-field siblings of each match (name parts, dob, taxvat, telephone, street, ...). Execution rows themselves survive as the audit trail (status, timestamps, workflow id, step keys); step-row result JSON and error text are scrubbed the same way. Known limits: snapshots carrying the customer's PII *without* their email anywhere in the same context cannot be attributed safely, and aggregation batch items are not scrubbed — both fall to TTL pruning (#1). Pinned by `ExecutionPiiScrubberTest` / `CustomerErasureScrubPluginTest`.

## Manual mass-run

**Current scope: single-entity manual runs only.** Every manual-run surface that ships today takes exactly one entity ID: the workflow edit page's "Run Now" button (`RunNowButton` opens the `RunNowModal` picker, which asks for one ID) into `WorkflowsAdminUi\Controller\Adminhtml\Workflow\Run`, which rejects a missing or non-numeric `entity_id`, and the `workflow:run --entity-id` CLI command. There is no grid mass-action, no REST run route, and no other multi-entity manual dispatch path — so there is no mass surface to cap or preview yet.

**Manual run is a POST.** Dispatching fires real side effects — refunds, customer emails, outbound webhooks — against a caller-chosen entity id, so `Workflow\Run` implements `HttpPostActionInterface` like every other mutating controller in the suite. That is the CSRF control, not a style preference: `Magento\Backend\App\AbstractAction::_processUrlKeys()` validates the admin **form key** on every POST from a logged-in admin, and falls back to the secret URL key only on non-POST requests. The secret key is not a CSRF defence — merchants routinely switch it off (Advanced > Admin > Security), and it leaks through Referer headers and browser history — so while this action was a GET, any page a logged-in admin visited could fire a live run with an `<img>` tag. The Run Now modal submits through `mage/utils/misc`'s `submit()`, which stamps the form key into a detached form; no controller here implements `CsrfAwareActionInterface`. Pinned by `WorkflowActionMethodContractTest` and `RunNowModalContractTest`.

What exists of the controls below: the **dedicated ACL resource** `MageOS_Workflows::manual_run` gates both the button and the controller (and is deliberately not implied by `::dry_run`). The cap is *configured* — `mageos_workflows/guards/manual_run_cap`, default 1000, exposed under Guards — but has no consumer while manual runs are one entity at a time; it currently serves as the vocabulary the approvals addon's mass-decide cap borrows.

When a mass-run surface is built it must carry:

- Confirmation modal with matched-count preview. *(not built — no mass surface yet)*
- Per-run cap (default 1k, configurable) — the existing `manual_run_cap` setting. *(configured, unconsumed)*
- Dedicated ACL resource. *(shipped)*
- Full audit log entry (admin, workflow, entity ID list hash). *(not built)*

Tracked in [#17](https://github.com/rhoerr/magento-workflows/issues/17).
