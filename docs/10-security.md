# 10 — Security Model

This engine executes stored merchant intent later, with system privileges, and can call out to the network. That makes security posture a design input, not a hardening pass. GA blockers are marked as such.

## Deferred privilege escalation (the core threat model)

**Workflows are stored intent executed later with system privileges** — the same class of problem as cron-injected code. Mitigations beyond authoring ACL:

- **Attribute allowlists per action:** the set-customer-attribute action refuses system attributes — `password_hash`, `is_active`, ACL-relevant fields — via a deny-by-default list of attribute codes shipped in config.
- **Scope-check at execution time**, not just authoring time: a workflow scoped to website 1 whose author lost website-1 access gets suspended, not silently escalated.
- **Authoring ACL** per action group ([Scope & ACL](09-scope-acl-observability.md#acl)): you can't author a step you couldn't perform yourself.

## SSRF hardening (the webhook action)

The webhook action ships **hardened, not hardenable**:

- **HTTPS only by default** (HTTP behind a config flag with warning).
- **DNS pinning:** resolve DNS *then* connect to the resolved IP (defeats rebinding).
- **Private-range denial:** reject private/link-local/loopback ranges (RFC1918, 169.254.0.0/16, ::1, cloud metadata endpoints) unless the host is on an explicit admin-configured allowlist — the same posture Shopify Flow takes.
- **Redirect re-validation:** deny redirects across the private-range boundary (Guzzle `on_redirect` re-validation).
- **Response caps:** 256KB body, JSON depth ≤ 10; parse failures capture `{parse_error: true}` rather than raw bytes.
- **Explicit trust boundary:** captured responses are attacker-influenceable data. They are usable in branch conditions and variable interpolation but **never as action identifiers** (no `{{ steps.x.response.action_code }}` resolving which action runs), **never in attribute codes**, and always type-coerced at the condition comparator. Documented in the SDK: action configs interpolate *values*, never *structure*.
- **Optional response JSON Schema per step** — mismatch = step failure, keeping garbage out of downstream branches.

## Secrets

- Dedicated ACL resource for secret CRUD.
- Values encrypted via `EncryptorInterface`.
- **Write-only in the UI** — never re-displayed.
- Redacted in execution logs and ES documents by key prefix.
- Definitions reference secrets **by name only** — exports never contain values.

## Import is untrusted input

- Validate against the published JSON Schema.
- Reject unknown action codes.
- **Re-authorize against the importing admin's ACL** — an imported definition containing actions the importer can't author fails loudly.
- The same check applies to programmatic creation via data patches (documented: patches run as system; agencies own that risk).

## Subscription ownership

The hidden async-events subscriptions created for event triggers carry an `owner=workflow:<id>` marker; the async-events admin UI and REST API **refuse mutation of owned subscriptions**, preventing an out-of-band edit from redirecting a workflow's event stream.

## PII containment

Execution `context` holds entity snapshots (names, emails, addresses). Three controls — **GA blockers, not fast-follows** (retrofitting redaction into an existing ES index is miserable):

1. **TTL pruning cron** — default 90 days, configurable down to hours.
2. **Field-level redaction config** applied before ES indexing — index metadata + IDs by default, full payload opt-in.
3. **GDPR erasure hook** into `CustomerRepository::delete` / erasure flows that scrubs matching execution contexts.

## Manual mass-run

- Confirmation modal with matched-count preview.
- Per-run cap (default 1k, configurable).
- Dedicated ACL resource.
- Full audit log entry (admin, workflow, entity ID list hash).
