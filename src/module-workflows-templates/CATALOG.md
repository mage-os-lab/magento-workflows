# Seed template catalog (06 stage 5)

Reviewed manifest, committed **before** any template JSON is authored (plan
06 stage 5 discipline: docs/discovery/implementation/06-template-gallery.md
row "Seed pack"). Fourteen templates grown from the ~10 loose candidates in
[discovery/template-gallery.md §6](../../docs/discovery/template-gallery.md)
plus best picks from [docs/17-use-cases.md](../../docs/17-use-cases.md),
spread across five merchant value areas: cart recovery, fraud/risk, customer
lifecycle, operations, and marketing. Every `requires.triggers`/`.actions`
entry below is checked against the real `TriggerRegistry`
(`src/module-workflows-triggers-core/etc/workflow_triggers.xml`) and
`ActionPool` (`src/module-workflows-actions-core/etc/di.xml`) by the CI
fixture test — nothing here is invented.

Schema versions: 1 unless noted. Two templates deliberately require schema 2
for a **wait** step (`order-stuck-in-processing-escalation`) and schema 2 for
business-day-aware delays (`abandoned-cart-recovery-coupon`,
`post-purchase-review-request`); one requires schema 3 for a **switch** step
(`product-review-triage`) — the engine-capability showcases the README asks
stage 5 to hit.

| # | Code | Category | Pitch | Schema | Trigger | Requires (actions) | Edition |
|---|---|---|---|---|---|---|---|
| 1 | `abandoned-cart-recovery-coupon` | Cart recovery | Skip low-value carts, remind the rest, follow up with a generated coupon. | 2 | event `quote.abandoned` | `notify.email`, `marketing.generate_coupon` | any |
| 2 | `high-value-order-fraud-hold` | Fraud & risk | Hold orders over a value threshold, alert the fraud team by webhook, escalate if still unresolved after a review window. | 1 | event `sales.order.created` | `order.add_comment`, `order.hold`, `notify.webhook`, `notify.admin` | any |
| 3 | `vip-auto-group-assignment` | Customer lifecycle | Promote a customer to a VIP group once lifetime spend crosses a threshold. | 1 | event `customer.updated` | `customer.assign_group`, `notify.email` | any |
| 4 | `new-customer-welcome-series` | Customer lifecycle | Welcome new customers, then follow up days later with a first-purchase coupon. | 1 | event `customer.created` | `notify.email`, `marketing.generate_coupon` | any |
| 5 | `post-purchase-review-request` | Marketing & post-purchase | Ask for a review a business-day-aware number of days after shipment. | 2 | event `sales.shipment.created` | `notify.email` | any |
| 6 | `stock-threshold-supplier-webhook` | Catalog & inventory | Notify a supplier iPaaS by signed webhook when a managed SKU crosses its stock threshold. | 1 | event `inventory.stock_threshold_crossed` | `notify.webhook`, `notify.admin` | any |
| 7 | `order-stuck-in-processing-escalation` | Operations | Wait for an order's status to change; escalate to admin + an internal comment if it's still stuck after the SLA window. | 2 (wait) | event `sales.order.status_changed` | `notify.admin`, `order.add_comment` | any |
| 8 | `unpaid-order-cleanup-sweep` | Operations | Hourly sweep: cancel pending orders unpaid longer than a configurable age. | 1 | schedule (cron) | `order.cancel`, `order.add_comment` | any |
| 9 | `refund-follow-up` | Orders & fulfillment | Check in with the customer a few days after a credit memo is created. | 1 | event `sales.creditmemo.created` | `notify.email` | any |
| 10 | `b2b-net-terms-payment-reminder` | B2B (edition-gated) | Remind a net-terms buyer their invoice is due soon. Edition-gated on purpose — a greyed-out card on Community installs. | 1 | event `sales.invoice.created` | `notify.email` | b2b |
| 11 | `gdpr-anonymize-on-request` | Compliance & data | Manual-trigger erasure flow: unsubscribe, then anonymize with the hard-required `confirm: true` speed bump. | 1 | manual | `customer.newsletter`, `customer.anonymize` | any |
| 12 | `guest-order-registration-invite` | Customer lifecycle | Invite a still-guest customer to register a few days after checkout, revalidated against a related-entity lookup so a meanwhile-registered customer is skipped. | 1 | event `sales.order.created` | `notify.email` | any |
| 13 | `product-review-triage` | Marketing & reviews | Switch on review score: reward five-star reviewers with a coupon, alert CX on a low score. | 3 (switch) | event `catalog.product.review_submitted` | `marketing.generate_coupon`, `notify.email`, `notify.admin` | any |
| 14 | `vip-order-notification` | Notifications & internal alerts | Tag an internal comment and alert admin the moment a known VIP customer group places an order. | 1 | event `sales.order.created` | `order.add_comment`, `notify.admin` | any |

## Parameters (≤3 each)

| Code | Parameters (key: type, default/required) |
|---|---|
| `abandoned-cart-recovery-coupon` | `coupon_rule_id`: entity:salesrule, required · `min_cart_total`: string, default `50` · `reminder_delay`: duration, default `P1D` |
| `high-value-order-fraud-hold` | `value_threshold`: string, default `1000` · `fraud_webhook_url`: string, required · `fraud_signing_secret`: secret, required |
| `vip-auto-group-assignment` | `spend_threshold`: string, default `1000` · `vip_group_id`: string, required |
| `new-customer-welcome-series` | `followup_wait`: duration, default `P3D` · `incentive_rule_id`: entity:salesrule, required |
| `post-purchase-review-request` | `request_wait`: duration, default `P7D` · `reminder_subject`: string, default `How did we do?` |
| `stock-threshold-supplier-webhook` | `supplier_webhook_url`: string, required · `supplier_signing_secret`: secret, required |
| `order-stuck-in-processing-escalation` | `escalation_wait`: duration, default `P5D` · `escalation_note`: string, default `Escalated: order stuck in processing beyond SLA.` |
| `unpaid-order-cleanup-sweep` | `stale_hours`: string, default `72` |
| `refund-follow-up` | `followup_wait`: duration, default `P2D` |
| `b2b-net-terms-payment-reminder` | `reminder_wait`: duration, default `P25D` |
| `gdpr-anonymize-on-request` | *(none — the erasure confirm is deliberately hardcoded, not parameterized)* |
| `guest-order-registration-invite` | `grace_period`: duration, default `P3D` |
| `product-review-triage` | `alert_threshold`: string, default `2` · `reward_rule_id`: entity:salesrule, required |
| `vip-order-notification` | `vip_group_id`: string, required |

## Dropped candidates (future notes)

- **Payment-risk vendor branch on returned verdict** (docs/17 "Capture a
  payment-risk vendor's response and branch the workflow on its returned
  verdict"): the condition engine's registered condition classes
  (`Order\Attribute`, `Customer\Attribute`, `Product\Attribute`,
  `Quote\Attribute`, `RelatedEntity\Combine`, `TriggerData`) validate against
  entity attributes and the trigger snapshot — there is no registered
  condition source for a *step's own output* (e.g. a captured webhook
  response), so a template cannot branch on `notify.webhook`'s `capture_as`
  result today. `high-value-order-fraud-hold` calls the fraud webhook and
  escalates on a subsequent order-state check instead. Revisit once a
  step-output condition source exists — that's an engine change, out of
  scope here.
- **B2B PO approval / credit-limit / quote-expiry flows** (docs/17 "B2B"
  section): no B2B action codes are registered in `ActionPool` (no
  `module-workflows-actions-b2b` exists yet) and no B2B-specific trigger
  events are registered in `TriggerRegistry`. `b2b-net-terms-payment-reminder`
  keeps the `requires.edition: b2b` gating example (a real, useful UX to
  showcase — the greyed-out card) but had to stay on generic sales actions;
  true B2B flows (negotiable quotes, company credit) need a B2B action/
  trigger pack first.
- **MSI source-scoped stock alerts** (docs/17 "Set stock status on a specific
  MSI source when a warehouse feed marks it depleted"): `product.set_stock`
  supports `source_code`, but there's no registered trigger for a warehouse
  feed event — only `inventory.stock_threshold_crossed` is registered, and it
  already fits `stock-threshold-supplier-webhook`. Dropped rather than
  invented a trigger.
- **Canvas thumbnails / screenshots**: out of scope per the task's plan-07
  boundary (a parallel agent owns `module-workflows-canvas`); the plain-
  language blurb above stands in per discovery §6, which explicitly treats
  canvas thumbnails as later polish.
