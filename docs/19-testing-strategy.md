# 19 — Testing Strategy: Current State vs. Ideal

An evaluation of the repo's automated testing against a theoretical ideal for
an engine of this shape (stored merchant intent, executed later, with system
privileges, moving real money/goods/mail). Tests in this repo are written to
the **documented contracts** (docs/04–10), not to the current implementation —
when the two disagree, the test fails and the disagreement is recorded here,
in [§Findings registry](#findings-registry).

## Test inventory (after the 2026-07 behavior-coverage pass)

| Lane | What runs | Count | Gate |
|---|---|---|---|
| PHP unit (standalone) | `dev/tests/standalone-runner.php`, PHPUnit-shim + Magento shims, PHP 8.1–8.5 | 1,067 tests | `lint.yml`, blocking |
| PHP unit (real Magento) | same suites under real PHPUnit inside a real Magento install, 2.4.6→2.4.9 matrix | same files | `check-extension.yml`, blocking |
| DI compile | `setup:di:compile` against every supported Magento line | — | `check-extension.yml`, blocking |
| phpcs | Magento2 standard | — | `check-extension.yml`, blocking |
| Canvas unit | vitest, jsdom | 114 tests | `canvas.yml`, blocking |
| Canvas e2e | Playwright smoke against a mocked admin page, Chromium | 2 scenarios | `canvas.yml`, blocking |
| Bundle gates | CSP grep (no eval/new Function), dist drift check | — | `canvas.yml`, blocking |
| Schema/XML/JSON lint | xmllint, JSON decode, PHP syntax | — | `lint.yml`, blocking |

What the unit lanes now pin, by subsystem: executor walk semantics (skip on
false root conditions, persist-before-side-effect, terminal-vs-retryable,
delay parking, branch/switch routing, iteration cap), condition-evaluator
gating (empty ⇒ true, malformed ⇒ throw, vanished entity ⇒ fail-closed),
dispatcher guards (loop guard, atomic debounce, website scope, status gate),
circuit breaker, resume sweeper claim atomicity + zombie recovery, delay
resume, retention pruning (live/dry-run clocks, in-flight never pruned),
webhook SSRF posture end-to-end (private-range denial before any request,
allowlist, redirect re-validation, HTTPS double-opt-in, response caps, secret
containment, 5xx-retryable/4xx-terminal), attribute denylists (customer +
product, pinned against the shipped `etc/di.xml`), send-once email guard,
order-action idempotency (canX ⇒ skipped, dedupe markers, state-machine
validation), anonymize scrub ordering, the whole triggers-core module
(notifier retry classification, subscription lifecycle + ownership refusal,
observer exclusion contracts), scheduler store-timezone cron evaluation +
double-fire guard, stock-threshold hysteresis, abandoned-cart windowing;
canvas graph ops/history/mapping round-trips (including edit-then-serialize
for branch/switch/approval edges), the save/validate client contracts, and a
Playwright smoke that asserts actual edge wiring and ui-position persistence
in the posted definition.

## The ideal, and the distance to it

For this engine the ideal test portfolio is, in priority order:

1. **Behavioral unit tests around every documented guarantee** — the layer
   this pass built out. Status: **largely present**. Remaining unit-level
   holes: repositories/resource models and revision optimistic-concurrency
   (structurally untestable without a DB — belongs in lane 2), trigger
   config XML merge pipeline, `HealthCheck`, console commands, importer
   graph-merge beyond the envelope, admin-ui controllers (Run/mass actions),
   approvals controller `execute()` mapping, canvas PHP controllers beyond
   ACL contracts, `BatchStore`/`MembershipEvaluator`, phase-2 hydrators.
2. **Integration tests against a real database** (Magento's
   `dev/tests/integration`): schema install, repository round-trips, revision
   concurrency, the save-plugin pipeline, debounce/claim UPDATEs under real
   MySQL semantics, queue consumers end-to-end. Status: **absent** — the
   single biggest structural gap. The atomicity tests in the unit lane prove
   the *logic* honors 0-rows-affected; only MySQL can prove the UPDATEs are
   actually atomic under concurrency.
3. **API functional tests** for the REST surface (workflow CRUD, validate,
   dry-run, approvals decide) with real ACL enforcement. Status: **absent**;
   route/ACL contracts are pinned only structurally from `webapi.xml`.
4. **Full-stack canvas e2e** against an installed Magento (real Save
   controller, form key, ACL). Status: **absent and documented as such**
   (`canvas.yml`, docs/11); the mocked-page smoke now covers the mount/save
   contract but trusts the PHP side entirely.
5. **Admin UI acceptance (MFTF or Playwright-vs-real-admin)** for the classic
   form, grid, execution logs. Status: absent; lowest priority of the five —
   the form assembler and controllers should first gain plain unit coverage.
6. **Hygiene**: no coverage measurement or mutation testing anywhere; the
   shim lane's fidelity depends on hand-written shims staying
   signature-faithful (the real-PHPUnit CI lane and `setup:di:compile` are
   the backstops that catch drift).

## Findings registry

Behavior issues surfaced by writing tests to the docs. **Fixed** items were
fixed in this pass; the rest are open, ordered by consequence.

### Fixed
- **Canvas live-validation dropped root conditions** — the editor never sent
  `conditions_serialized` to `Data/Validate`, so condition findings surfaced
  only at save time. Fixed via `buildValidateRequest()` seam; pinned.
- **Canvas edge deletion was never persisted** — delete-key edge removal
  updated only React Flow local state; the next save silently re-posted the
  edge. Fixed with a combined `onDelete` commit; pinned by an e2e scenario.

### Open — documented behavior not implemented
- **Entity deleted during a delay is NOT resumed as `skipped`**
  (docs/08:99). `ResumeConsumer` never re-checks the entity; root conditions
  are evaluated only on first run (`Executor.php:108-123`), so a vanished
  entity surfaces as per-action failures (variously terminal or retryable),
  not the promised execution-level `skipped`. `DryRunService.php:67-68` even
  cites the nonexistent production semantics. Implement in
  `ResumeConsumer::process` or amend docs/08.
- **Execution-time scope re-check / author-lost-access suspension**
  (docs/09, docs/10 "scope-check at execution time") — no implementation
  found; only failure-driven circuit-breaker suspension exists.
- **GDPR erasure hook** scrubbing execution contexts on customer delete
  (docs/10 PII #3, a GA blocker) — not implemented (only the `anonymize`
  action exists).
- **Field-level redaction before ES indexing** (docs/10 PII #2, GA blocker)
  — no implementation found.
- **Manual mass-run cap / matched-count preview / audit-log hash**
  (docs/10 §Manual mass-run) — not evident in the Run controller; unverified
  and untested.
- **Canvas unsaved-changes guard** — no dirty tracking/`beforeunload`
  anywhere; navigating away discards edits silently.
- **Webhook redirect targets are validated but not DNS-pinned**
  (`Webhook.php:223` pins only the original host) — a rebinding DNS server
  could re-resolve a validated redirect hop between validation and connect.

### Open — implementation right, docs stale (amend docs)
- docs/07:50 says a non-shippable/refundable order is "a step failure";
  the actions correctly return `skipped` (at-least-once safety). Reword to
  distinguish "illegal" from "already done".
- docs/08 "dedupes on execution UUID" — the key is UUID **+ step key**
  (finer, better; two comment steps coexist).
- docs/05 pseudocode reads `subscription_data.workflow_id`; the real (and
  pinned) mechanism is the `workflow:<id>` recipient URL, which also **is**
  the docs/10 "owner marker" (no separate field).
- `revalidate_entity` defaults to true for **all** branch/switch steps at the
  executor, not only post-delay (stronger than docs/06 suggests).

### Open — risk notes (working as coded, worth a decision)
- Uncaught action exceptions are always **retryable** (`Executor.php:272`);
  no terminal-exception classification — a permanently-throwing action burns
  redeliveries until the circuit breaker (10 fails) suspends. ChangeStatus
  and AddComment likewise catch broadly and always retry.
- The email send-once guard's check-and-set (`Email.php:106-109`) is not
  atomic — safe for sequential redelivery, but two *concurrent* consumers
  could both send.
- Circuit-breaker admin notification is not gated on the suspend persist —
  a failed save still notifies "suspended" while the workflow stays enabled
  (converges on the next failure).
- The attribute-denylist protection lives entirely in `etc/di.xml` with a
  permissive `[]` constructor default; a DI misconfiguration silently drops
  it. Tests pin the shipped di.xml contents as a tripwire.
- Scheduler timezone resolution degrades **silently to the default timezone**
  on any store/website resolution error (`RunScheduledWorkflows.php:142-152`,
  warning-level log only).
- Wall-clock seams: `Dispatcher::passesDebounce`, `PruneExecutions`, the
  scheduler, and both detectors construct `now` directly — no injectable
  clock; several tests absorb this with wide offsets. A pre-existing
  wall-clock-sensitive suite (`CronScheduleTest` "due now") can flake at
  minute boundaries.
- `SubscriptionManager::findByRecipient` return type relies on the concrete
  async-events model implementing both `AsyncEventInterface` and
  `AsyncEventDisplayInterface`; `aroundDelete` in the ownership plugin is
  latent (no `delete()` in the 4.x repository interface).

## Priorities from here

1. Stand up the Magento integration-test lane (schema, repositories,
   revision concurrency, debounce/claim atomicity under real MySQL) — most
   remaining unit gaps are really integration gaps. Planned in detail in
   [20 — Integration Test Plan](20-integration-test-plan.md).
2. Resolve the four unimplemented GA-blocker-class promises (delay-resume
   skip semantics, scope re-check, GDPR hook, ES redaction) — implement or
   descope in docs before any GA claim.
3. API functional tests for the REST surface; then admin-ui controller units
   (Run/mass actions, importer, HealthCheck, console commands).
4. Canvas: component-level tests for `Editor.tsx`/`ConfigPanel.tsx`
   (add @testing-library), an unsaved-changes guard, and eventually one
   full-stack e2e against a disposable Magento.
5. Docs pass to clear the "docs stale" list above.
