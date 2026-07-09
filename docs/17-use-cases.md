# 17 — Use Cases: How Merchants & Agencies Use the Workflow Engine

A catalog of 100+ high-level automations the Mage-OS Workflow Engine makes possible.
Each is a merchant- or agency-level outcome expressed in one line; all are composed
from the engine's **trigger → condition → action** primitives (event/schedule/manual
triggers, the four-root condition engine with EAV auto-discovery and related-entity
cross-referencing, the core action pool, delays/waits and `branch`/`switch` multi-way
branching, a human-decision `approval` gate, trigger-level fan-out and batch-digest
aggregation, webhooks, secrets, dry-run, a bundled template gallery, an optional
drag-and-drop canvas, and workflow-as-code).

These are illustrative, not an exhaustive list — the DI-registered action pool and open
definition format mean the combinations are effectively unbounded. The July 2026 capability
wave (branching, cross-referencing, fan-out, batch aggregation, dry-run, template gallery,
canvas, the [approval gate](discovery/approval-gate.md)) is reflected throughout; the flip
side — flows the engine still does *not* support — is catalogued in
[18 — Known Boundaries](18-limitations.md).

## Orders & fulfillment

- Auto-hold every order over $2,000 for a manual finance review before it can ship.
- Add an internal order comment tagging the assigned CS rep when a VIP customer orders.
- Auto-invoice (offline capture) orders paid by bank transfer once payment is confirmed.
- Create the shipment automatically for digital or pre-packed SKUs and notify the customer.
- Cancel unpaid "pending" orders that are still unpaid 72 hours after creation.
- Move orders to a custom "Ready to Pick" status the moment an invoice is created.
- Hold orders shipping to a flagged country until a compliance officer releases them.
- Auto-unhold orders once a captured fraud-score webhook returns a low-risk result.
- Add a gift-wrap prep comment when the order contains any SKU in the "Gift" category.
- Escalate any order that has sat in "Processing" for more than 5 business days.
- Fan out from one customer event to every one of that customer's open orders and act on each (capped fan-out).
- Park a goodwill-credit request for a sales-manager decision; issue the approved amount on approval, send a policy email on rejection, escalate on silence (`approval` gate).

## Fraud, risk & payments

- On high-value orders, call an external fraud-scoring API and hold if the score exceeds 80.
- Flag orders where billing and shipping countries differ and the total exceeds $500.
- Notify a #fraud channel via webhook when a guest checkout places three orders in an hour.
- Wait 1 hour after order creation; if still unpaid, post a fraud-review notification.
- Auto-cancel orders from customer groups previously associated with chargebacks.
- Route orders with mismatched postcode/region into a manual-verification status.
- Hold first-time customers whose first order is above the store's average order value.
- Capture a payment-risk vendor's response and branch the workflow on its returned verdict.
- Alert finance when a single customer's lifetime refunds cross a defined threshold.
- Flag orders using a payment method newly seen for an otherwise long-standing customer.
- Hold a high-risk order and park it for a fraud-team decision: on approve unhold and invoice, on reject cancel and notify, on 4-hour silence escalate to a second reviewer (`approval` gate).

## Customer lifecycle & segmentation

- Promote customers to a "Loyal" group automatically once lifetime sales exceed $1,000.
- Move customers to a "Wholesale" group after their order count passes 10.
- Tag customers who haven't ordered in 180 days as "At Risk" for a win-back campaign.
- Welcome-email new customers, wait 3 days, then send a first-purchase incentive.
- Send a birthday coupon a week ahead: on `customer.birthday_upcoming`, generate a one-time coupon and email it (`birthday_upcoming` → `generate_coupon` → `notify.email`); tune the lead time with the Birthday Look-Ahead (days) setting.
- Condition a birthday campaign on `days_until_birthday` / `birthday_month` to stagger offers by month.
- Downgrade a loyalty tier when average order value drops below a set floor over time.
- Add a "High-AOV" attribute flag to customers whose average order value exceeds $250.
- Assign a dedicated account manager attribute when a B2B buyer's order history qualifies.
- Auto-subscribe customers to the newsletter after their second completed order.
- Build a "New Parent" segment when a customer buys from the Baby category twice.
- Re-engage lapsed VIPs with a personalized offer the day they cross 90 days inactive.
- Invite a guest checkout to register when their email has no account yet, or nudge them to log in when it does (entity cross-referencing).
- Spot a guest placing their third order under the same email and route them into an account-creation offer (cross-referenced order history).

## Cart abandonment & recovery

- Detect carts idle over 4 hours with no order and email a gentle reminder.
- Abandoned-cart series: remind at 1 hour, offer 5% at 24 hours, offer 10% at 72 hours.
- Only send the recovery email if the cart total exceeds $100 (skip low-value carts).
- Generate a one-time coupon from a cart price rule and drop it into the recovery email.
- Revalidate before sending: cancel the reminder if the customer completed checkout meanwhile.
- Notify sales when a known wholesale buyer abandons a cart above $1,000.
- Send a "back in stock — finish your order" nudge when an abandoned cart's SKU restocks.
- Escalate high-value abandoned carts to a human callback task instead of an email.
- Suppress recovery emails for customers who abandoned more than 3 carts this week.
- Wait for a `sales.order.updated` event per cart and thank the customer if they convert.

## Catalog & inventory

- Auto-disable any product whose stock reaches zero on the default source.
- Re-enable products automatically when replenishment pushes stock above the threshold.
- Alert merchandising via webhook when a managed SKU's quantity drops to 5 or below.
- Move out-of-stock products out of their sale category and into a "Coming Soon" category.
- Set stock status on a specific MSI source when a warehouse feed marks it depleted.
- Tag products that have been out of stock for over 30 days for discontinuation review.
- Add newly-created products missing a description to a "Needs Content" category.
- Flag products with a cost above price (negative margin) for a pricing-team review.
- Nightly: scan the catalog and disable products with no image or empty required attributes.
- Auto-assign seasonal products to the "Holiday" category as a scheduled campaign kicks off.

## Pricing & promotions

- Apply a special price with automatic from/to dates when a flash sale window opens.
- Clear expired special prices in bulk on the morning after a promotion ends.
- Set a clearance price automatically when a product's stock exceeds an overstock threshold.
- Generate and email a unique coupon to customers who cross a lifetime-spend milestone.
- Drop a win-back discount coupon into a re-engagement email for lapsed customers.
- Match a competitor feed via webhook and adjust special price within guardrails.
- Roll out a store-scoped price change only for a specific website, leaving others untouched.
- Auto-apply a loyalty-tier price attribute when a customer is promoted to a VIP group.
- Schedule a "release at 09:00 store time" price drop the morning of a product launch.
- End a promotion and restore regular pricing after exactly 7 business days.
- Detect a price drop steeper than 30% and revert it unless a merchandiser confirms within 24 hours (`approval` gate with a required timeout).

## Marketing, reviews & post-purchase

- Send a review-request email 7 days after a shipment is created, if not yet reviewed.
- Thank customers and email a coupon when they submit a 5-star product review.
- Alert the CX team when a review of 2 stars or fewer is submitted on a hero product.
- Trigger a replenishment reminder timed to a consumable product's typical reorder cycle.
- Cross-sell: email accessory recommendations 3 days after a device ships.
- Send a "how's it going?" check-in email 14 days after first-time customers' first order.
- Enroll high-AOV buyers into a VIP early-access list via a segment action (Commerce).
- Ask for a referral after a customer's third successful, non-refunded order.
- Post-delivery: request an NPS score via webhook to a survey platform.
- Congratulate customers on a purchase anniversary with a loyalty bonus each year.
- Win back an opt-out: when a subscriber's status changes to Unsubscribed, post to an ESP win-back webhook — guests and account holders alike (`newsletter.subscription_changed`).
- Welcome a brand-new newsletter signup the moment they subscribe, even without an account, via `notify.email` on the guest-safe subscription event (`newsletter.subscription_changed`, `from_status` null).

## Notifications & internal alerts

- Post an admin-inbox notification when any order over $5,000 is placed.
- Webhook a Slack/Teams channel on every new order from a strategic account.
- Email the warehouse a summary the moment an order flips to "Ready to Ship."
- Notify a store manager when daily orders from one customer exceed a set count.
- Alert merchandising by email when a top-seller crosses its low-stock threshold.
- Ping on-call ops via webhook when the circuit breaker suspends a critical workflow.
- Notify the account owner in the admin inbox when a wholesale customer is created.
- Email a nightly digest of every product that went out of stock that day (aggregate trigger).
- Send a compliance officer an alert when an order ships to a restricted region.
- Notify finance when a customer's outstanding refunds exceed their lifetime sales ratio.

## Compliance, data & governance

- Anonymize a customer's PII on request, with an explicit confirm step, for GDPR erasure.
- Unsubscribe and scrub customers who submit a data-deletion request via a support webhook.
- Wait for a legal-hold-cleared event before allowing an anonymization workflow to proceed.
- Auto-unsubscribe customers from the newsletter when they downgrade to a "Do Not Contact" group.
- Log an immutable order-time snapshot (revalidate off) for audit of what the order looked like.
- Flag orders to embargoed countries and stop the workflow with an audit comment.
- Redact stored card-adjacent notes by scrambling a custom attribute after N days.
- Route data-subject-access requests to an external DSAR system via signed webhook.
- Enforce retention by pruning execution history on a schedule (operations cron).
- Require a secret-backed signature on every outbound webhook so receivers can verify origin.

## Integrations & external systems (webhook → iPaaS)

- POST new orders to an ERP and capture the returned ERP order ID onto the Magento order.
- Sync new customers to a CRM and store the returned CRM contact ID as an attribute.
- Push shipment events to a 3PL and branch on the label-generation response.
- Call a tax-validation service on B2B orders and hold if the VAT ID fails.
- Send order data to a loyalty platform and apply returned points as a customer attribute.
- Trigger an email-service-provider journey via webhook on segment membership changes.
- Bridge to an iPaaS (Make/Zapier/n8n) with a signed webhook instead of a native connector.
- Verify addresses via a validation API and hold orders that fail before fulfillment.
- Notify a marketplace connector when inventory crosses a threshold to pause listings.
- Capture a fraud vendor's JSON response and validate it against a per-step JSON Schema.

## Scheduled & time-based automations

- Nightly sweep: cancel quotes/orders abandoned longer than the store's SLA window.
- Daily: promote all customers whose order history now qualifies them for a new group.
- Hourly: detect stock thresholds and publish alerts with hysteresis (fires once, not repeatedly).
- Weekly: flag products with zero sales in 90 days for a merchandising review.
- Every morning: release scheduled price changes at 09:00 in each store's local time.
- Month-end: email finance a roll-up of held and cancelled high-value orders.
- Quarterly: re-segment the customer base against updated lifetime-value tiers.
- Run "orders older than 72h and still unpaid" as a scheduled query, not a live event.
- Business-days-aware delays so a "wait 2 days" reminder skips the weekend.
- Time a follow-up to land exactly N business days after invoice, at a fixed local hour.

## B2B (Adobe Commerce pack)

- Assign a sales rep automatically when a negotiable quote is created above a threshold.
- Notify the company admin when a purchase order is submitted for approval.
- Auto-approve POs under a per-company spending threshold; route larger ones to a human.
- Alert finance when a company's available credit drops below a safety margin.
- Adjust a company's credit limit via action when their payment history qualifies.
- Welcome-onboard a newly created company with a templated multi-step sequence.
- Escalate a negotiable quote that is about to expire without a response.
- Notify the account team when a requisition list is updated for a strategic account.
- Branch workflows on buyer role so approvers and purchasers get different treatment.
- Reject POs that exceed a company's credit balance and notify the buyer with the reason.

## Agencies, workflow-as-code & platform

- Author workflows once and export them as versioned JSON to deploy across client stores.
- Ship a curated automation pack to a client via a data patch on `composer install`.
- Git-version workflow definitions and deploy them through CI without SSH access.
- Manage workflow CRUD entirely through the REST API from a deployment pipeline.
- Roll out a new automation in shadow mode first, reviewing simulated actions before going live.
- Dry-run a definition against a real order — from the CLI, the REST API, or the admin trace panel — and read step-by-step what it would do before enabling it.
- Design complex multi-way branching visually on the optional drag-and-drop canvas, then export the very same JSON definition.
- Install a vetted recipe from the built-in template gallery — parameterized, in shadow mode — then dry-run and enable it.
- Surface which workflows already run on the Orders/Customers/Products grids, right where merchants work, via the entity-grid visibility addon.
- Re-authorize imported definitions against the importing admin's ACL to enforce least privilege.
- Reuse one definition across websites, letting each store's timezone and scope resolve locally.
- Package a custom action as one class plus a `di.xml` entry — the connector SDK, no new plumbing.
- Test a workflow safely with a manual grid mass-action and a matched-count preview before enabling.
- Lint client workflow definitions against the published JSON Schema in CI as a quality gate.

## Reliability & safety patterns (built in)

- Debounce duplicate events so a rapid double-save can't fire the same workflow twice.
- Cap mass-runs and scheduled matches so a bulk import can't detonate the engine.
- Auto-suspend a workflow whose webhook keeps failing, before it burns the retry queue for days.
- Guard against loops when an action's mutation would re-trigger the same workflow.
- Clamp a fat-fingered `P1Y` delay to the max-delay ceiling instead of parking work for a year.
- Replay a failed workflow dispatch straight from the async-events admin grid.
</content>
</invoke>
