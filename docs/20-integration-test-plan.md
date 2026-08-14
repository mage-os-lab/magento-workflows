# 20 — Magento Integration Test Suite Plan

**Status: DELIVERED (2026-07, PR #21).** All 33 catalog suites are authored
and green: 234 tests / 1,144 assertions against a real Magento 2.4.9 install
(plus 3 `known-divergence` pins quarantined from the blocking gate). The
bring-up surfaced eight shipped product defects — recorded in
[docs/19's findings registry](19-testing-strategy.md#findings-registry).
Environment lessons learned the hard way, now encoded in the harness:

- The integration `phpunit.xml.dist` `<extensions>` block registers
  `Magento\TestFramework\Event\Subscribers` — the framework's ENTIRE
  event wiring. Remove only the Allure bootstrap element, never the block.
- The integration lane runs **PHPUnit 12**: docblock `@group` is ignored;
  quarantine tags must be `#[Group('known-divergence')]` attributes.
- `@magentoConfigFixture` is honored at **method level only** (unlike
  DataFixture/AppArea/AppIsolation, which fall back to class scope).
- Magento's annotation parser regex-scans every docblock line: prose
  mentions of `@magento*` annotations inside test docblocks parse as
  malformed annotations and can abort the whole suite.
- Backend-controller tests leak `State::$_areaCode` to later tests
  (dispatch sets it without updating `Application::$_appArea`); controller
  suites must carry `@magentoAppIsolation enabled`.
- The unit/compile/phpcs jobs are temporarily in ECONOMY MODE (newest line
  only) while the repository's CI-minutes budget recovers; restore
  `outputs.matrix` in `check-extension.yml` when minutes allow. The nightly
  full-matrix + `known-divergence` reporting job (§2.3) is still to be added.

Remaining scope beyond this plan: the api-functional REST lane (§8).

The build-out plan for lane 2 of the ideal portfolio in
[19 — Testing Strategy](19-testing-strategy.md): **behavior tests that run
inside Magento's integration test framework** (`dev/tests/integration`),
against a real MySQL, the real merged DI configuration, real EAV entities,
and the real queue/cron machinery. This is the lane docs/19 calls "the single
biggest structural gap", and it is the gate the README places before any GA
claim ("the code has not been … exercised end-to-end").

Same authoring rule as the unit lane: tests are written to the **documented
contracts** (docs/04–10), not to the current implementation. When they
disagree, the test encodes the doc and the disagreement lands in the
[findings registry](19-testing-strategy.md#findings-registry). Several open
findings are only provable in this lane; they are called out inline below.

## 1. What this lane must prove (and the unit lane cannot)

| Class of behavior | Why unit tests structurally cannot pin it |
|---|---|
| Declarative schema installs; whitelist in sync; FK cascade semantics | No DB. `db_schema.xml` is only lint-checked today |
| Repository round-trips, collections, revision archiving inside a real transaction | Resource models are thin wrappers over the adapter; mocking them proves nothing |
| Atomic claim UPDATEs (`waiting → pending`, sweeper claims, debounce insert) honoring 0-rows-affected **under real MySQL semantics** | The unit lane proves the *logic* checks rowCount; only MySQL proves the UPDATE is conditioned correctly |
| The merged production `di.xml` actually delivers pools/denylists/plugins | Unit tests instantiate classes with hand-fed constructor args; the "permissive `[]` default" risk (docs/19) is exactly a DI-merge question |
| Condition evaluation against real EAV attributes, scopes, and aggregate queries | The shim layer fakes `Magento\Rule`; EAV auto-discovery and order-history aggregates build real SQL |
| Observer → dispatcher wiring via the real event manager and merged `events.xml` | Config-file merge is framework behavior |
| Adminhtml controllers with real ACL, form keys, and routing | `AbstractBackendController` needs a booted application |
| `mageos-async-events` class-name fidelity | Named in the README as unverified; only a real install with the real package can settle it |
| End-to-end: import fixture → dispatch → consume → step rows → resume → terminal status | The full walk crosses repository saves and raw `ResourceConnection` SQL |

Out of scope for this lane (tracked separately in docs/19 priorities):
REST-over-HTTP with real token auth and `webapi.xml` ACL (that is Magento's
*api-functional* framework, a sibling harness — §8), MFTF admin acceptance,
and the full-stack canvas e2e. The Webapi *service classes* are covered here
directly through the object manager.

## 2. Harness and repository wiring

### 2.1 Test location

Tests live at `src/<module>/Test/Integration/…` — the convention is already
established: `src/module-workflows/Test/Integration/DryRun/DualEngineConformanceTest.php`
is authored and skip-gated on exactly this harness. The standalone shim
runner discovers `Test/Unit` only, so integration trees are inert in the
`lint.yml` lane by construction; no exclusion config needed.

Shared fixture scripts live at `src/module-workflows/Test/Integration/_files/`
(workflow definitions, seeded executions) and are referenced cross-module the
same way core Magento shares `Magento/<Module>/_files`.

### 2.2 Execution model

Magento's integration framework runs from `<magento>/dev/tests/integration`
with its own bootstrap: it installs a dedicated test database from
`etc/install-config-mysql.php`, boots the full application per test with
annotation-controlled isolation, and exposes the real object manager via
`Magento\TestFramework\Helper\Bootstrap`.

The monorepo is installed into a Magento project exactly as
`check-extension.yml` already does (wildcard path repo over `src/module-*`,
`composer require` of every package at `@dev`). On top of that, the
integration job:

1. requires `mage-os/mageos-async-events` for real (not shimmed) — this is
   deliberate: verifying fidelity against it is a stated goal;
2. copies this repo's `dev/tests/integration-config/` into the install:
   - `phpunit-workflows.xml` — a copy of Magento's
     `dev/tests/integration/phpunit.xml.dist` whose `<testsuite>` points at
     `../../../vendor/mage-os/workflows*/Test/Integration` (mirrors the unit
     job's sed approach; ship the full file to also drop the allure
     extension and set memory limits);
   - `install-config-mysql.php` — DB credentials for the CI service
     containers, **`amqp` omitted** so the queue framework falls back to the
     `db` connection and consumers can be driven in-process (matches the
     module's own `queue_consumer.xml` comment: deployment decides);
3. stages the monorepo `spec/` dir to `vendor/spec` (same step the unit job
   already performs — the conformance suites read published fixtures from
   there);
4. runs `vendor/bin/phpunit -c dev/tests/integration/phpunit-workflows.xml`.

### 2.3 CI job

New job `integration-test-extension` in `check-extension.yml`, reusing the
existing composite actions (`setup-magento`, `cache-magento`), plus GitHub
service containers: `mysql:8.0` and `opensearch` (mandatory for
`setup:install` on all supported lines). No RabbitMQ service — db queue.

Cost control, since a Magento install + test-framework DB install is the
dominant fixed cost (~6–8 min before the first test):

- **PR gate (blocking): newest supported line only** (single matrix entry).
  The full 2.4.6→2.4.9 matrix already exercises version drift in the unit +
  compile jobs; integration behaviors are far less version-sensitive than
  class signatures.
- **Nightly cron + `workflow_dispatch`: full `supported-version` matrix.**
- Target: complete PR job < 20 minutes; keep the suite deterministic
  (no sleeps — §2.4).

### 2.4 Conventions

- **Isolation defaults**: `@magentoDbIsolation enabled` everywhere it can
  hold (each test in a rolled-back transaction), `@magentoAppIsolation
  enabled` only where singletons/registry are mutated,
  `@magentoAppArea adminhtml` for controller/ACL suites. Use doc-comment
  annotations, not PHP attributes, for 2.4.6-line compatibility.
- **Atomicity tests run on one connection.** MySQL executes the conditioned
  UPDATEs sequentially even inside one transaction, so
  claim-exactly-once is provable as: first UPDATE returns 1 row, an
  identical second returns 0. That is the semantics the code relies on
  (`Dispatcher::resumeWaiting`, `ResumeSweeper`). True two-connection
  parallelism (needs `@magentoDbIsolation disabled` + manual cleanup) is
  reserved for the two places a *unique key*, not a conditioned UPDATE, is
  the guard: the debounce insert and the email send-once marker (§4, §5).
- **No sleeps, no wall-clock waits.** The engine has no injectable clock
  (docs/19 risk note), so time-window suites (debounce expiry, retention,
  zombie cutoff, approval expiry) rewind persisted timestamps with direct
  `UPDATE`s (`created_at`, `claimed_at`, `updated_at`) and re-run the sweep.
  *Enabler refactor, recommended but not blocking: introduce a
  `ClockInterface` seam in `Dispatcher`, `PruneExecutions`, the scheduler,
  and both detectors — it would simplify this lane and de-flake
  `CronScheduleTest` in the unit lane.*
- **Workflow fixtures** are created through the real import path
  (`WorkflowImporter` / `WorkflowRepositoryInterface::save`), sourcing
  `spec/fixtures/*.json` where a published fixture fits and small inline
  definitions otherwise. Saving through the repository means every fixture
  also transits `ValidateWorkflowOnSave` — free validation coverage, and a
  fixture that stops validating is itself a finding.
- **Entity fixtures** come from Magento core data fixtures
  (`Magento/Sales/_files/order.php`, `Magento/Customer/_files/customer.php`,
  `Magento/Catalog/_files/product_simple.php`, quote fixtures), extended
  locally only where a scenario needs attributes core fixtures lack.
- **Side-effect capture**: email through the framework's
  `TransportBuilderMock`; generic action side effects through a
  `RecordingAction` registered into `ActionPool` via a test-scoped
  `Test/Integration/etc/di.xml` (loaded only by the integration framework;
  this is also the pattern third-party connectors use, so it doubles as SDK
  validation). Webhook stays off the network entirely (§5).
- **Queue draining**: invoke `ExecuteConsumer::process()` /
  `ResumeConsumer::process()` directly with the published execution IDs
  (deterministic, no consumer daemon). One smoke test drives the real
  `ConsumerFactory` end-to-end over the db transport to prove the topology
  wiring (§4.12).

## 3. Suite catalog — overview

Priority encodes both risk and dependency order. P0 is the engine spine +
persistence layer (the crash-safety and money-touching guarantees); P1 is
the behavior breadth (conditions, actions, triggers, scheduler, admin); P2
is completeness and whole-system smokes.

| # | Suite (class) | Module | Priority |
|---|---|---|---|
| 1 | `Schema\DeclarativeSchemaTest` | workflows | P0 |
| 2 | `Model\WorkflowRepositoryTest` | workflows | P0 |
| 3 | `Model\WorkflowRevisionTest` | workflows | P0 |
| 4 | `Plugin\ValidateWorkflowOnSaveTest` | workflows | P0 |
| 5 | `Engine\DispatcherGuardsTest` | workflows | P0 |
| 6 | `Engine\DispatcherDebounceTest` | workflows | P0 |
| 7 | `Engine\ExecutorWalkTest` | workflows | P0 |
| 8 | `Engine\DelayResumeTest` | workflows | P0 |
| 9 | `Engine\WaitEventResumeTest` | workflows | P0 |
| 10 | `Engine\CircuitBreakerTest` | workflows | P0 |
| 11 | `Cron\PruneExecutionsTest` | workflows | P0 |
| 12 | `Queue\TopologyTest` | workflows | P0 |
| 13 | `DryRun\DualEngineConformanceTest` (un-gate) | workflows | P0 |
| 14 | `Engine\EntityDeletedDuringDelayTest` (finding pin) | workflows | P0 |
| 15 | `Rule\Condition\{Order,Customer,Product,Quote}ConditionTest` | workflows | P1 |
| 16 | `Variable\ResolverTest` | workflows | P1 |
| 17 | `Secrets\SecretStorageTest` | workflows | P1 |
| 18 | `Action\Order\*Test` | actions-core | P1 |
| 19 | `Action\Notify\EmailTest` | actions-core | P1 |
| 20 | `Action\{Customer,Product,Marketing}\*Test` | actions-core | P1 |
| 21 | `Action\WebhookDiWiringTest` | actions-core | P1 |
| 22 | `Observer\TriggerWiringTest` | triggers-core | P1 |
| 23 | `Service\AsyncEventsFidelityTest` | triggers-core | P1 |
| 24 | `Cron\RunScheduledWorkflowsTest` + detectors | scheduler | P1 |
| 25 | `Controller\Adminhtml\*Test` | admin-ui | P1 |
| 26 | `Model\ApprovalLifecycleTest` + controller | approvals | P1 |
| 27 | `Controller\Adminhtml\{Data,Validate,Save}Test` | canvas | P1 |
| 28 | `Model\TemplateInstallTest` | templates | P2 |
| 29 | `Console\ImportExportRoundTripTest` | workflows | P2 |
| 30 | `Plugin\ImportSuppressionTest` | import-suppression | P2 |
| 31 | `Model\HealthCheckTest` + `Console\*CommandTest` | workflows | P2 |
| 32 | `Webapi\ServiceContractTest` | workflows | P2 |
| 33 | `Plugin\GridVisibilityTest` | admin-extension | P2 |

## 4. P0 — schema, persistence, engine spine

**1. `Schema\DeclarativeSchemaTest`** — the test framework's installer has
already applied `db_schema.xml` by the time any test runs; assert all 11
tables exist with expected columns/indexes (`mageos_workflow`,
`_website`, `_revision`, `_execution`, `_execution_step`, `_debounce`,
`_stock_flag`, `_batch`, `_batch_item`, `_secret`, `_template_install`),
that `db_schema_whitelist.json` matches (regenerating produces no diff),
and FK/cascade behavior: deleting a workflow cascades its website rows;
deleting an execution cascades its step rows; deleting a workflow does
**not** orphan-or-cascade executions in a way that violates the retention
contract (pin whichever the DDL declares — divergence from docs/08 is a
finding). Also covers `module-workflows-approvals`' `mageos_workflow_approval`.

**2. `Model\WorkflowRepositoryTest`** — full CRUD round-trip through
`WorkflowRepositoryInterface` (definition JSON survives byte-for-byte
including utf8mb4/emoji; conditions_serialized round-trips), `getById` on a
missing id throws `NoSuchEntityException`, `getList` with real
`SearchCriteria` filters/sort/paging against the real collection, delete
returns true and the row is gone, `WorkflowIndex::clean` is invoked on
save/delete (observable via cache tag invalidation). Same shape for
`WorkflowExecutionRepository` and the execution-steps provider.

**3. `Model\WorkflowRevisionTest`** — the repository's transaction:
a definition-changing save archives the *prior* definition +
conditions into `mageos_workflow_revision` and bumps `version` by exactly 1,
atomically (force the revision insert to fail via a poisoned resource
preference → assert the workflow save rolled back too); a status-only save
does not archive and keeps `version`; sequential definition saves produce a
contiguous revision history. **Lost-update pin**: load the same workflow
twice, save copy A (definition change), then save stale copy B — pin the
actual outcome. Docs/03 versioning semantics decide whether B must fail
(optimistic concurrency) or last-write-wins is accepted; if the former,
this test is expected to fail and becomes a registry finding.

**4. `Plugin\ValidateWorkflowOnSaveTest`** — the plugin is DI-wired onto the
repository interface; prove the *merged* configuration delivers it (the
whole class of "the protection lives in di.xml" risks): an invalid
definition thrown as `ValidatorException` through a plain repository save;
an author lacking a referenced action's ACL resource gets
`AuthorizationException` (real `Magento\Framework\Authorization` with an
admin-role fixture); **status-only saves skip validation** — persist a
workflow whose definition references a nonexistent action code directly via
the resource model, then flip its status through the repository and assert
the save succeeds (the documented disable-after-uninstall guarantee);
warnings land in `ValidationResultRegistry`, not exceptions.

**5. `Engine\DispatcherGuardsTest`** — with real store/website fixtures:
website-scope gate (order on website B does not dispatch a website-A-scoped
workflow), disabled workflow never dispatches, shadow-status workflow
dispatches and walks but a `RecordingAction` proves no side-effecting action
ran, loop-guard chain-depth cap, status gate on the workflow row read at
dispatch time.

**6. `Engine\DispatcherDebounceTest`** — real `mageos_workflow_debounce`
semantics: first dispatch inserts and passes; second within the window
returns `null` and creates no execution; keying is per (workflow, entity) —
different entity or workflow passes; rewinding the stored timestamp past the
configured window (via `@magentoConfigFixture` for the window value) lets
the next dispatch pass. **Two-connection race** (the one true-concurrency
test in P0, `@magentoDbIsolation disabled`): fire the same
(workflow, entity) insert from two connections; exactly one execution row
exists afterwards. If the schema lacks the unique key to enforce that, the
test fails → registry finding (the unit lane can only assume the key).

**7. `Engine\ExecutorWalkTest`** — the end-to-end spine, one scenario per
documented walk guarantee, driven as: import definition → dispatch with a
real order fixture → `ExecuteConsumer::process($id)` → assert on
execution/step rows. Pins: linear walk executes actions in order with a
step row persisted **before** each side effect (RecordingAction observes the
row already present); root-conditions false ⇒ execution `skipped` with zero
action side effects; terminal action failure ⇒ execution `failed`, later
steps not run; retryable failure ⇒ thrown so the queue redelivers, step row
reflects retry state; a redelivery parked back on an already-`complete` step
row re-runs nothing and completes; branch and switch route by real condition evaluation
against the fixture entity; `revalidate_entity` re-loads the entity on
branch/switch; iteration cap halts a cyclic definition; definition_snapshot
is what executes (mutating the workflow row mid-flight does not change the
walk).

**8. `Engine\DelayResumeTest`** — delay step parks the execution `waiting`
with a step row carrying the resume time; `ResumeSweeper` claims atomically
(`waiting → pending` conditioned UPDATE: run the sweep twice over one due
execution, second claims nothing, exactly one resume publish); publish
failure rolls the claim back (poison the publisher via OM preference) so
the next sweep retries; zombie recovery: a step stuck `running` with
`claimed_at` rewound 31 minutes is re-claimed exactly once and republished;
stranded-execution recovery: an execution left `running` whose only step row
is `complete` with `finished_at` rewound 31 minutes is claimed
(`running → pending`) exactly once, republished, and the redelivered walk
resumes *past* the finished step (assert the action's side effect count does
not increase) — while the same execution with a fresh `finished_at`, or with
a `pending` step row, is left alone;
`ResumeConsumer` continues the walk from the parked step; business-days and
store-local-time delay options compute against real store timezone config.

**9. `Engine\WaitEventResumeTest`** — `Dispatcher::resumeWaiting`: a
matching (workflow, event, entity) claims `waiting → pending` exactly once,
writes the event payload into the wait step's `result` **before** the
publish (assert ordering by poisoning the publisher: payload present,
status rolled back to `waiting`), `on_event` routing in the consumer sees
the payload as step output, non-matching entity/event resumes nothing,
batch limit respected.

**10. `Engine\CircuitBreakerTest`** — drive one workflow to the failure
threshold with a permanently-throwing action through real consumer
redeliveries: the workflow row flips to suspended; subsequent dispatches
refuse; an execution already queued under the now-suspended workflow fails
**terminally** on its next delivery (error names the suspension, no further
redelivery, no step side effect) rather than continuing to burn the retry
queue; pin the docs/19 risk note as a tripwire — if the admin notification
fires while the suspend persist failed, that is the known non-gated notify
(expected-fail pin referencing the registry).

**11. `Cron\PruneExecutionsTest`** — seed terminal, in-flight, waiting, and
dry-run executions with rewound timestamps: live and dry-run retention
clocks apply independently; in-flight/waiting rows are **never** pruned
regardless of age; step rows go with their execution; the run is bounded
(no full-table delete).

**12. `Queue\TopologyTest`** — `communication.xml` / `queue_topology.xml` /
`queue_consumer.xml` / `queue_publisher.xml` parse and merge under the real
framework; both consumers resolve through `ConsumerFactory`; one smoke:
publish `mageos.workflow.execute` through the real db transport, run the
real consumer for one message, execution completes. This is the only test
that exercises the transport rather than calling handlers directly.

**13. `DryRun\DualEngineConformanceTest`** — remove the skip and implement
the four-step checklist already written in its docblock: each
`spec/fixtures/*.json` imports, dispatches in shadow against a seeded
entity, drains execute/resume, and the executor's step sequence must equal
the `DryRunService` primary path (dry-run may be a superset at
waits/failures). Any divergence between the two walkers fails here.

**14. `Engine\EntityDeletedDuringDelayTest`** — executable pin for the
top open finding (docs/08:99): order deleted while the execution is parked
⇒ resume must mark the execution `skipped`. **Expected to fail today**
(`ResumeConsumer` never re-checks the entity). Grouped `@group
known-divergence`, excluded from the blocking run, executed and reported in
the nightly job — the group exists so fixing the finding un-quarantines the
test rather than requiring a new one.

## 5. P1 — conditions, variables, secrets, actions

**15. `Rule\Condition\*ConditionTest`** (one class per entity type) — real
EAV: a custom order/customer/product attribute created by fixture appears in
the condition metadata (auto-discovery) and evaluates correctly; scope-aware
attribute values (store-scoped product attribute evaluates per the
execution's store); customer order-history aggregates (count/sum over
seeded orders, statuses filtered); quote conditions against real quote
fixtures; vanished entity ⇒ fail-closed (evaluates false, execution
`skipped` per docs/06); attribute denylist honored **from the real merged
di.xml** — the customer/product denylist tripwire re-pinned against the
live object manager rather than a copy of the XML.

**16. `Variable\ResolverTest`** — token resolution against real entities:
scalar, nested (`order.customer.email`), EAV attribute tokens, formatting
filters, store-scoped values; unknown token behavior per docs/07; secrets
referenced in templates resolve at execution time and are redacted in
persisted step results/logs (`RedactingSecretsProvider` end-to-end: the
secret value appears in the outbound payload, never in any
`mageos_workflow_execution_step.result` row).

**17. `Secrets\SecretStorageTest`** — `mageos_workflow_secret` rows are
encrypted at rest with the real `EncryptorInterface` (raw column value ≠
plaintext, decrypts round-trip); `SecretKeyValidator` contract at the
storage boundary; `workflow:secret:set|list|delete` via Symfony
`CommandTester` against the real DB (list never prints values).

**18. `Action\Order\*Test`** — against real order fixtures in appropriate
states: `AddComment` writes a real status-history row and its
execution-UUID+step-key dedupe marker survives redelivery (re-run the
consumer: exactly one comment); `ChangeStatus` respects the real state
machine (legal transition applies; illegal ⇒ per docs/07 as amended —
`skipped`, and the docs-stale reword is verified); cancel/refund/ship/invoice:
`canX()` false ⇒ `skipped` not failed; invoice/creditmemo/shipment rows
actually created and totals correct; `CreateCreditmemo` writes its
execution-UUID+step-key marker onto the memo's comment and a redelivery is
skipped by that marker (not by `canCreditmemo()`, which does not hold for the
percent/fixed modes) with the first memo's id handed back. These are the money-touching paths —
each action gets its own test class.

**19. `Action\Notify\EmailTest`** — `TransportBuilderMock` capture: template
resolved store-scoped, variables from the real entity, recipient resolution;
send-once guard persists its marker and a redelivery does not resend.
**Two-connection race pin** for the known non-atomic check-and-set
(`Email.php:106-109`, docs/19 risk note): `@group known-divergence`, nightly.

**20. `Action\{Customer,Product,Marketing}\*Test`** — anonymize scrubs the
documented field set on a real customer (and the scrub ordering vs.
newsletter unsubscribe); newsletter subscribe/unsubscribe mutate real
`newsletter_subscriber` rows idempotently; product attribute-set action
honors the denylist from merged DI; cart-price-rule / coupon actions create
real salesrule rows scoped correctly.

**21. `Action\WebhookDiWiringTest`** — no network. Prove the merged DI
delivers the SSRF configuration to the constructor (denylist ranges,
allowlist, HTTPS policy, response caps non-empty as shipped), and that a
private-range URL is refused before any connection attempt (the guard
throws before DNS/socket work — observable with a URL whose resolution
would fail loudly). Full HTTP behavior stays in the unit lane's
mocked-client suite.

## 6. P1 — triggers, scheduler, admin surfaces

**22. `Observer\TriggerWiringTest`** — dispatch real events
(`sales_order_save_after`, customer/product/quote equivalents) through the
real event manager with fixture entities and assert the dispatcher was
reached with the documented payload shape (RecordingDispatcher via OM
preference); the merged `events.xml` is the subject. Exclusion contracts:
the import-suppression flag set ⇒ observer declines (pairs with #30);
admin-area vs frontend-area event registration matches docs/05.

**23. `Service\AsyncEventsFidelityTest`** — with the real
`mage-os/mageos-async-events` package installed: subscription lifecycle
(create/enable/disable via `SubscriptionManager` produces real async-events
rows), the `workflow:<id>` recipient-URL convention round-trips as the
ownership marker, ownership refusal (foreign subscriptions untouched),
`WorkflowNotifier` implements the real notifier contract and its retry
classification behaves under the real `NotifierResult`. This suite retires
the README's "class-name fidelity … needs verification" caveat and the
docblock assumptions in `module-workflows-triggers-core/etc/di.xml`.

**24. Scheduler suites** — `Cron\RunScheduledWorkflowsTest`: store-timezone
cron evaluation with per-store timezone config fixtures (a workflow due at
09:00 store-local fires when UTC now maps to it, not before), double-fire
guard persists across two runs in the same minute, silent-default-timezone
degrade pinned as a tripwire (broken store config ⇒ default timezone +
warning — pin the actual behavior so a future fix is visible).
`Model\StockThresholdTest`: hysteresis against real `cataloginventory`
stock items (below-threshold fires once, stays-below does not re-fire,
recovery above threshold re-arms via `mageos_workflow_stock_flag`).
`Model\AbandonedCartTest`: real quote fixtures with rewound `updated_at` —
window boundaries, converted/emptied quotes excluded, one dispatch per
quote.

**25. `Controller\Adminhtml\*Test`** (admin-ui, on
`AbstractBackendController`) — grid page renders under the real ACL
resource; Save controller round-trip (form key enforced, definition posted
as the JSON editor does, success message, row persisted, validation error
re-renders with message); Run controller: manual run dispatches, and the
docs/10 mass-run contract (cap, matched-count preview, audit-log hash) is
pinned — **expected to fail** per the open finding, `@group
known-divergence`; mass enable/disable transit the repository (so
validation-skip on status-only holds — pairs with #4); execution log and
step views render for a seeded execution; every route denies without its
`acl.xml` resource (role fixture with the resource removed ⇒ 403/redirect).

**26. Approvals suites** — executor reaching an `approval` step parks the
execution and creates a `mageos_workflow_approval` task (schema covered in
#1); decide controller approve ⇒ resume continues the walk, reject ⇒
execution ends per docs; authorization: only a role holding the decide ACL
may act, the requester/owner rules from the approvals module's
authorization model enforced; expiry cron transitions overdue tasks and
resumes/skips per config; notification emails captured via
TransportBuilderMock.

**27. Canvas controllers** — `Data` returns the definition + metadata
payload the editor mounts from; `Validate` returns findings for an invalid
definition **including root-conditions findings** (the fixed finding, now
pinned server-side); `Save` enforces form key + ACL and persists via the
repository (so #4's pipeline applies). Complements the mocked-page
Playwright smoke, which trusts the PHP side entirely today.

## 7. P2 — completeness and whole-system smokes

**28. `Model\TemplateInstallTest`** — all 14 bundled templates install
against the real DB: `workflow:template:install` creates workflows that
pass live validation (a template drifting from the validator is a release
blocker caught here), `mageos_workflow_template_install` rows written,
re-install is idempotent, uninstalled-module actions in a template are
rejected cleanly. Doubles as the broadest definition-pipeline smoke.

**29. `Console\ImportExportRoundTripTest`** — `workflow:export` of a saved
workflow re-imports to a deep-equal definition (against the export JSON
schema in `spec/`); importer graph-merge cases beyond the envelope; import
of every `spec/fixtures/*.json` succeeds; `workflow:run` dispatches;
`workflow:stats` reads seeded executions. All via `CommandTester`.

**30. `Plugin\ImportSuppressionTest`** — run a real
`Magento\ImportExport` product import with the suppression module enabled:
per-row triggers suppressed during the import window, normal dispatch
resumes after; the flag scoping (pairs with #22).

**31. `Model\HealthCheckTest` + command** — against the real install:
healthy state reports green; seed each detectable degradation (consumer
backlog rows, suspended workflow, stale cron) and assert detection.

**32. `Webapi\ServiceContractTest`** — the classes bound in `webapi.xml`
exercised as service contracts through the object manager: list/get/save/
delete/validate/dry-run/meta endpoints return the documented shapes and
error semantics (`NoSuchEntityException` → 404 mapping is framework-owned;
what we own is the service behavior). Full HTTP+ACL coverage is the
api-functional lane (§8).

**33. `Plugin\GridVisibilityTest`** — admin-extension's native-grid
visibility addon against real grid collections.

## 8. Adjacent lane (planned, separate): api-functional

Magento's `dev/tests/api-functional` framework (real HTTP, real tokens,
real `webapi.xml` ACL) is priority 3 in docs/19 and intentionally **not**
part of this suite: it needs a *running web server* in CI, not just a DB,
and a different bootstrap. The plan hook: the same fixture library from
§2.4 is reusable; the suite would cover the 17 routes in `webapi.xml` plus
approvals' decide route, with admin-token ACL matrices (authorized /
unauthorized / anonymous per route). Defer until P0+P1 above are merged
and stable; revisit sizing then.

## 9. Delivery phasing

| Phase | Contents | Exit criterion |
|---|---|---|
| A — harness | §2 wiring: `dev/tests/integration-config/`, CI job (newest line, blocking), suites #1–2 as harness proof | Green PR gate running 2 real suites against MySQL |
| B — engine spine | #3–14 (P0), including un-gating the conformance test and the `known-divergence` group + nightly full-matrix job | Every docs/08 execution-model guarantee pinned against real MySQL; findings registry updated with whatever #3/#6/#14 surface |
| C — behavior breadth | #15–27 (P1), async-events fidelity retiring the README caveat | Conditions/actions/triggers/scheduler/admin each behavior-pinned; README status paragraph updated (integration lane exists) |
| D — completeness | #28–33 (P2), api-functional sizing decision (§8) | Template gallery + CLI + suppression covered; docs/19 inventory table updated |

Ordering rationale: A/B are the GA gate (crash safety, atomicity,
dual-engine conformance); C is where most *merchant-visible* behavior
lives; D converts remaining unit-lane "structurally untestable" items.

Rough sizing: ~33 test classes, ~200–250 test methods. Phase A ≈ 3–4 days
(CI iteration dominates), B ≈ 1.5–2 weeks, C ≈ 2–3 weeks, D ≈ 1 week.

## 10. Risks

- **Install cost dominates.** Mitigation: single-line PR gate, full matrix
  nightly; `cache-magento` for composer; keep `TESTS_CLEANUP` enabled
  (correctness) and accept the one-time install per job rather than
  chasing DB-dump caching in v1.
- **Wall-clock seams flake.** Mitigation: timestamp rewinding only, no
  sleeps; the `ClockInterface` enabler refactor (§2.4) if rewinding proves
  awkward anywhere (candidates: debounce, zombie cutoff).
- **`known-divergence` drift.** Quarantined expected-fail tests rot if
  nothing watches them. Mitigation: nightly job runs the group and reports;
  each test cites its registry entry; fixing the finding moves the test
  into the blocking set (delete the group tag) in the same PR.
- **async-events version skew** across the supported matrix. Mitigation:
  the fidelity suite (#23) runs on every matrix entry in the nightly job —
  that *is* the detection mechanism; pin the tested version range in the
  integration job's composer require.
- **OpenSearch service flakiness** in CI. Mitigation: health-check the
  service before install; the suite itself never queries search (no ES
  redaction implementation exists yet — when docs/10 PII #2 lands, its
  test joins this lane).
- **Isolation leaks** (tests mutating shared config/singletons without
  `@magentoAppIsolation`). Mitigation: convention review in PR; prefer
  config fixtures over direct writes.
