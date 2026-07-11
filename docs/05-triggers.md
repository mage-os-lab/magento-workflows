# 05 — Trigger Layer

Three trigger types share one dispatch path: **event** (async-events notifier), **schedule** (cron + query), and **manual** (grid mass-action / CLI). All spawn identical executions through the same dispatcher.

## Event triggers — ride the async-events notifier seam

`mageos-async-events` delivers events to destinations via **notifiers** resolved from subscription `metadata` (upstream ships `http`; the pool is a DI array keyed by the metadata value). We add one under the key `workflow`:

```php
// Verified against mage-os/mageos-async-events @ b249976
// (Service/AsyncEvent/NotifierInterface.php). The subscription is the real
// AsyncEventInterface (not AsyncEventDisplayInterface) and the event is a
// CloudEventImmutable; the return narrows ResultInterface to NotifierResult.
class WorkflowNotifier implements NotifierInterface   // metadata: "workflow"
{
    public function notify(AsyncEventInterface $asyncEvent, CloudEventImmutable $event): NotifierResult
    {
        // Workflow id rides in the recipient marker "workflow:<id>" (there is no
        // arbitrary subscription-data bag on AsyncEventInterface); the trigger
        // snapshot is the CloudEvent payload $event->getData().
        return $this->dispatcher->dispatch(
            $this->extractWorkflowId($asyncEvent), $event->getData()
        );
    }
}
```

Enabling a workflow programmatically creates a hidden async-event subscription (`event_name` = trigger ref, `metadata` = `workflow`, recipient = workflow ID). Disabling deactivates it. Consequences, all favorable:

- The engine inherits async-events' **queue transport, quadratic backoff retry, dead-lettering, UUID tracing, replay, and ES-indexed searchability** with zero code. A failed workflow dispatch is just a failed delivery — replayable from the existing admin grid.
- The **payload arrives pre-hydrated** by the event's declared service class (`OrderRepositoryInterface::get` etc.) — the same snapshot an HTTP subscriber would get. This becomes the trigger snapshot placed into execution `context.trigger`.
- **Trigger coverage = `async_events.xml` definitions.** `mageos-common-async-events` covers customer/order/invoice/shipment basics; `workflows-triggers-core` fills gaps: order status change (with from→to in payload), stock threshold crossed², review submitted, cart abandoned¹, customer group changed, credit memo, RMA if present.

¹ Cart abandonment isn't an event — it's a query ("quote updated > N hours ago, no order"). It lives in the scheduler (below), which then *publishes* a `quote.abandoned` async event, keeping one dispatch path.

² Same query shape, same answer: `StockThresholdDetector` (scheduler cron, every 10 minutes) publishes `inventory.stock_threshold_crossed` when a managed product's qty drops to or at `mageos_workflows/scheduler/stock_threshold` (default 5; `0` disables the detector). Hysteresis via the `mageos_workflow_stock_flag` table: a product is flagged on the downward crossing and unflagged only once qty recovers *above* the threshold, so a product hovering at the boundary fires once, not every 10 minutes.

The hidden subscriptions carry an `owner=workflow:<id>` marker; the async-events admin UI and REST API refuse mutation of owned subscriptions ([Security §Subscription ownership](10-security.md#subscription-ownership)).

Wait steps (definition schema 2) add a second class of hidden subscription: one per waited-on event, recipient `workflow:<id>:wait:<event>`, reconciled at workflow save time. These deliver to `Dispatcher::resumeWaiting()` — waking parked executions for the matching entity — rather than spawning a fresh dispatch, and they exist independently of the workflow's own trigger type: a schedule- or manual-triggered workflow with wait steps still needs its wait events delivered ([Execution Model §Wait steps](08-execution-model.md#wait-steps-schema-2)).

### Trigger metadata

Trigger metadata for the UI (labels, entity type, payload hints) is declared in `workflow_triggers.xml`:

```xml
<trigger event="sales.order.created" entity="sales_order"
         label="Order Created" group="Sales"
         resolver="MageOS\Workflows\Model\Resolver\OrderResolver"/>
```

The `resolver` is the repository-backed hydration entry point used by the condition engine's Phase-2 pass ([Conditions §Two-phase evaluation](06-conditions.md#two-phase-evaluation-the-eav-at-scale-answer)).

### Trigger-level fan-out

An event trigger can optionally declare a **fan-out** clause (`fan_out` = `{relation, cap}`): one event on the source entity expands, in the notifier, into N ordinary single-entity executions — one per member of a declared relation (e.g. *customer group changed → each of the customer's open orders*). The workflow's entity type is the relation **target**, so its conditions and actions author naturally against each fanned-out entity; the causing event is recorded in each child's `origin` context ([Definition Format §Trigger payload context](04-definition-format.md)). Schedule-type triggers cannot fan out (they already fan out over their match query). See [discovery/fan-out.md](discovery/fan-out.md) and the ops [Fan-out section](15-operations.md#fan-out).

## Scheduled triggers (`workflows-scheduler`)

A schedule-type workflow = cron expression + entity type + the same rule-condition tree used as a **query**.

Key trick: `Magento\Rule\Model\Condition\*` supports `collectValidatedAttributes()` / SQL generation in the CatalogRule lineage — but that path is only reliable for products. Pragmatic approach:

- Map the condition tree to `SearchCriteria` where operators translate cleanly (scalar/set/date on selectable attributes)
- Fall back to load-and-filter in batches of 500 where they don't

All four entity roots are queryable, including `quote` (`CartRepositoryInterface` wired in the scheduler's `di.xml`); quote schedules watermark on `updated_at`.

Each matching entity spawns a normal execution through the same dispatcher.

**Guard rails:**

- Per-run match cap (default 5k)
- A `last_run_watermark` so "orders older than 72h" doesn't reprocess the same rows
- Dedupe on `(workflow_id, entity_id)` within a configurable window

Schedules evaluate in *store* timezone with the store recorded on the execution — see [Risks §Timezones](14-risks.md), the #1 support-ticket generator in every scheduler ever shipped.

### Aggregate (batch) triggers

The inverse of fan-out: an aggregation clause (`aggregation` = `{mode, …}`) collapses **N events into one digest execution** rather than dispatching per entity. Two modes ship (implemented July 2026, pending live-install verification): **collected** — a scheduled sweep runs the condition tree as a query and emits a single execution carrying the whole matched collection; and **window** — an event-window accumulator appends matching events to an open batch and flushes it on a cron/interval boundary. The batch execution carries the collection in `context.trigger.items` (rendered with the `count`/`pluck`/`join`/`table`/`json` collection formatters) and shows `entity_id = 0`. See [Execution Model §Aggregated (batch) workflows](08-execution-model.md) and [discovery/batch-aggregation.md](discovery/batch-aggregation.md).

## Manual triggers

- Admin mass-action on order/customer/product grids ("Run workflow…")
- `bin/magento workflow:run <id> --entity-id=…`

Spawns a standard execution with `trigger_type=manual` recorded. This doubles as the developer test harness during development and the merchant's test harness after.

Manual mass-run is guarded: confirmation modal with matched-count preview, per-run cap (default 1k, configurable), a dedicated ACL resource, and a full audit log entry ([Security §Manual mass-run](10-security.md#manual-mass-run)).
