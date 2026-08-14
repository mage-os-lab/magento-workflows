<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Action\Order;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\Data\CreditmemoCommentCreationInterface;
use Magento\Sales\Api\Data\CreditmemoCommentCreationInterfaceFactory;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterface;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\RefundOrderInterface;
use Magento\Sales\Model\Order;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * order.create_creditmemo — OFFLINE refund via the sales RefundOrder service
 * (no payment gateway call).
 *
 * Two independent guards, both required:
 *
 *  - canCreditmemo() is a STATE guard: it stops a refund the order cannot take
 *    (nothing left refundable, wrong state). It is NOT a redelivery guard —
 *    that claim held only for `full` mode. percent/fixed issue adjustment-only
 *    refunds with no item lines, which leave the order perfectly
 *    creditmemo-able, so under at-least-once delivery (docs/08) a redelivered
 *    message sailed past canCreditmemo() and refunded real money a second time.
 *  - The dedupe MARKER is the redelivery guard, for EVERY mode including full
 *    (defence in depth): each memo this action creates carries a credit-memo
 *    comment holding an invisible HTML-comment marker with the execution+step
 *    dedupe key (the same pattern as order.add_comment), and every run scans
 *    the order's existing memos for its own marker first. The marker is written
 *    by RefundOrder inside the SAME transaction as the memo — an offline refund
 *    makes no gateway call, so there is no window where money moved but the
 *    marker did not.
 *
 * The comment is created with is_visible_on_front FALSE and RefundOrder's
 * appendComment flag FALSE, so it is never shown on the storefront, never set
 * as a notified customer note, and never reaches the refund email (which
 * renders customer_note only when customer_note_notify is set). It is an
 * admin-side audit line, exactly like order.add_comment's marker.
 *
 * Modes (config `mode`, default `full`):
 *  - full    — refund every refundable item (empty item list). Unchanged v1
 *              behavior.
 *  - percent — refund `percent` (1..100) of the order's total_paid as a pure
 *              ADJUSTMENT refund with no item lines. Amount =
 *              round(total_paid * percent / 100, 2). No cap: a percentage that
 *              exceeds what is still refundable (prior partial refunds) is a
 *              terminal LocalizedException from RefundOrder, surfaced honestly
 *              rather than silently shrunk.
 *  - fixed   — refund a fixed `amount` (> 0) as an adjustment refund with no
 *              item lines, CAPPED at the refundable remainder
 *              (total_paid - total_refunded). Capping (not failing) is the
 *              chosen policy: "refund $50" when only $45 remains refunds $45,
 *              matching the usual merchant intent; the applied figure is
 *              reported in the result. A remainder of 0 or less is a terminal
 *              failure — there is nothing to refund.
 *
 * The partial modes compute the adjustment amount IN THE ACTION from static
 * config (percent/amount are plain config keys, never structure), so the
 * resolver stays computation-free. Amounts are ORDER-CURRENCY figures
 * (total_paid/total_refunded and the adjustment_positive the creditmemo carries
 * are all order currency); rounding is half-up to 2 decimals (round()).
 *
 * A partial refund is expressed as adjustment_positive on the
 * CreditmemoCreationArguments with an EMPTY item list, so the creditmemo grand
 * total is exactly the adjustment (no item/tax/shipping proration) — a clean
 * "refund $X of goodwill" against the order.
 */
class CreateCreditmemo extends AbstractOrderAction implements SimulateableActionInterface
{
    private const MODE_FULL = 'full';
    private const MODE_PERCENT = 'percent';
    private const MODE_FIXED = 'fixed';
    private const MODES = [self::MODE_FULL, self::MODE_PERCENT, self::MODE_FIXED];

    /**
     * Same marker shape order.add_comment uses: an HTML comment carrying the
     * execution+step dedupe key.
     */
    private const MARKER_FORMAT = '<!-- mageos-workflows:%s -->';

    /**
     * The comment body the marker rides in. Human-readable first so the admin
     * credit-memo history explains itself.
     */
    private const MARKER_COMMENT_FORMAT = 'Refund issued by a Mage-OS workflow. %s';

    /**
     * The marker guard is money safety, not a nicety: the repository and the
     * comment factory are REQUIRED constructor dependencies so it can never be
     * silently absent. (Magento's ObjectManager does not auto-inject a
     * parameter that has a default value, so an optional dependency here would
     * arrive null in production unless di.xml wired it by hand.)
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        private readonly RefundOrderInterface $refundOrder,
        private readonly CreditmemoRepositoryInterface $creditmemoRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly CreditmemoCommentCreationInterfaceFactory $commentFactory,
        private readonly ?CreditmemoCreationArgumentsInterfaceFactory $argumentsFactory = null
    ) {
        parent::__construct($orderRepository);
    }

    public function getCode(): string
    {
        return 'order.create_creditmemo';
    }

    public function getLabel(): string
    {
        return (string)__('Create Credit Memo');
    }

    public function getConfigForm(): array
    {
        return [
            ['name' => 'mode', 'label' => 'Refund Mode', 'type' => 'select', 'required' => false,
                'default' => self::MODE_FULL,
                'options' => [
                    ['value' => self::MODE_FULL, 'label' => 'Full (all refundable items)'],
                    ['value' => self::MODE_PERCENT, 'label' => 'Percent of paid total'],
                    ['value' => self::MODE_FIXED, 'label' => 'Fixed amount'],
                ]],
            ['name' => 'percent', 'label' => 'Percent to refund (1-100)', 'type' => 'text', 'required' => false],
            ['name' => 'amount', 'label' => 'Fixed amount to refund', 'type' => 'text', 'required' => false],
            ['name' => 'notify', 'label' => 'Notify Customer', 'type' => 'boolean', 'required' => false,
                'default' => false],
        ];
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $mode = $this->resolveMode($config);
        if ($mode === null) {
            return ActionResult::failure(
                (string)__('Invalid credit memo mode "%1" (full|percent|fixed)', $this->stringConfig($config, 'mode'))
            );
        }
        $notify = $this->boolConfig($config, 'notify');

        $order = $this->loadOrder($ctx);
        if ($order instanceof ActionResult) {
            return $order;
        }

        // Redelivery guard FIRST, before the state guard: it is the only one
        // that holds for every mode, and its answer is the informative one —
        // it hands back the memo this step already created instead of a
        // state-derived "cannot be refunded".
        $marker = sprintf(self::MARKER_FORMAT, $ctx->getDedupeKey($this->stepKey($ctx)));
        try {
            $existingId = $this->findMarkedCreditmemoId((int)$order->getEntityId(), $marker);
        } catch (\Exception $e) {
            // Never refund on an unanswered dedupe question: park for retry.
            return ActionResult::failure(
                'Could not check for an existing workflow credit memo: ' . $e->getMessage(),
                true
            );
        }
        if ($existingId !== null) {
            return ActionResult::skipped(
                sprintf(
                    'Order %s was already refunded by this step (dedupe marker present)',
                    $order->getIncrementId()
                ),
                ['creditmemo_id' => $existingId]
            );
        }

        if (!$order->canCreditmemo()) {
            return ActionResult::skipped(sprintf(
                'Order %s cannot be refunded (state "%s")',
                $order->getIncrementId(),
                $order->getState()
            ));
        }

        // Compute the adjustment amount + creation arguments for the partial
        // modes BEFORE touching the refund service; a bad config is a terminal
        // failure that never reaches RefundOrder.
        $adjustment = null;
        $arguments = null;
        if ($mode !== self::MODE_FULL) {
            $amountOrError = $this->partialAmount($mode, $order, $config);
            if ($amountOrError instanceof ActionResult) {
                return $amountOrError;
            }
            $adjustment = $amountOrError;
            $arguments = $this->buildArguments($adjustment);
            if ($arguments instanceof ActionResult) {
                return $arguments;
            }
        }

        try {
            // Empty items = refund everything refundable (full) or, with
            // adjustment arguments, an adjustment-only memo whose grand total
            // IS the adjustment. Both carry the dedupe comment, and both leave
            // appendComment false so the marker is stored on the memo without
            // becoming a notified customer note.
            $creditmemoId = $this->refundOrder->execute(
                (int)$order->getEntityId(),
                [],
                $notify,
                false,
                $this->buildMarkerComment($marker),
                $arguments
            );
        } catch (LocalizedException $e) {
            // Configuration/state problems will not resolve on redelivery
            return ActionResult::failure('Could not create credit memo: ' . $e->getMessage());
        } catch (\Exception $e) {
            // Infrastructure flakiness (locks, connection drops) may succeed on retry
            return ActionResult::failure('Could not create credit memo: ' . $e->getMessage(), true);
        }

        $output = [
            'creditmemo_id' => (int)$creditmemoId,
            'notify' => $notify,
        ];
        if ($mode !== self::MODE_FULL) {
            $output['mode'] = $mode;
            $output['refund_amount'] = $adjustment;
        }
        return ActionResult::success($output);
    }

    /**
     * No marker scan here, deliberately: simulate() never creates a memo, so no
     * memo can carry THIS execution+step's marker and the scan could only ever
     * miss. Keeping it out leaves simulation query-free as well as side-effect
     * free.
     */
    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $mode = $this->resolveMode($config);
        if ($mode === null) {
            return ActionResult::failure(
                (string)__('Invalid credit memo mode "%1" (full|percent|fixed)', $this->stringConfig($config, 'mode'))
            );
        }

        $order = $this->loadOrder($ctx);
        if ($order instanceof ActionResult) {
            return $order;
        }
        if (!$order->canCreditmemo()) {
            return ActionResult::skipped(sprintf('Order %s cannot be refunded', $order->getIncrementId()));
        }

        if ($mode === self::MODE_FULL) {
            return $this->simulated(sprintf(
                'Create offline credit memo for order %s (notify %s)',
                $order->getIncrementId(),
                $this->boolConfig($config, 'notify') ? 'yes' : 'no'
            ));
        }

        $amountOrError = $this->partialAmount($mode, $order, $config);
        if ($amountOrError instanceof ActionResult) {
            return $amountOrError;
        }
        return $this->simulated(sprintf(
            'Create %s adjustment credit memo of %s for order %s (notify %s)',
            $mode,
            number_format($amountOrError, 2),
            $order->getIncrementId(),
            $this->boolConfig($config, 'notify') ? 'yes' : 'no'
        ));
    }

    /**
     * The id of an existing credit memo on this order that already carries
     * THIS execution+step's marker, or null when there is none.
     *
     * Lookup mechanism: the credit-memo repository filtered on order_id (an
     * indexed column), then each memo's own comments. Both hops go through
     * service contracts — the repository and CreditmemoInterface::getComments()
     * (which lazy-loads the comment collection) — so the whole scan is
     * mockable with plain doubles, unlike an order creditmemo COLLECTION.
     * Filtering the comment table by marker text instead would be one query
     * but a LIKE scan across every memo in the store, and it would lose the
     * order scoping that keeps the search index-bound.
     *
     * Per-step by construction: the dedupe key is execution UUID + step key
     * (docs/08), so two refund steps in one workflow never dedupe each other.
     */
    private function findMarkedCreditmemoId(int $orderId, string $marker): ?int
    {
        $this->searchCriteriaBuilder->addFilter('order_id', $orderId, 'eq');
        $result = $this->creditmemoRepository->getList($this->searchCriteriaBuilder->create());

        foreach ($result->getItems() as $creditmemo) {
            foreach ((array)$creditmemo->getComments() as $comment) {
                $text = $comment->getComment();
                if (is_string($text) && str_contains($text, $marker)) {
                    return (int)$creditmemo->getEntityId();
                }
            }
        }
        return null;
    }

    /**
     * The credit-memo comment carrying the dedupe marker. Explicitly NOT
     * visible on front; RefundOrder is called with appendComment false so the
     * marker also never becomes a notified customer note (and so never reaches
     * the refund email, which renders customer_note only when
     * customer_note_notify is set).
     */
    private function buildMarkerComment(string $marker): CreditmemoCommentCreationInterface
    {
        $comment = $this->commentFactory->create();
        $comment->setComment(sprintf(self::MARKER_COMMENT_FORMAT, $marker));
        $comment->setIsVisibleOnFront(false);
        return $comment;
    }

    /**
     * The requested mode (default full), or null when an explicit mode is
     * unrecognized.
     */
    private function resolveMode(array $config): ?string
    {
        $mode = $this->stringConfig($config, 'mode', self::MODE_FULL);
        return in_array($mode, self::MODES, true) ? $mode : null;
    }

    /**
     * Compute the adjustment amount for a partial mode, or a terminal
     * ActionResult failure when its config is invalid / nothing is refundable.
     *
     * @return float|ActionResult
     */
    private function partialAmount(string $mode, Order $order, array $config)
    {
        $paid = (float)$order->getTotalPaid();

        if ($mode === self::MODE_PERCENT) {
            $percent = $this->intConfig($config, 'percent');
            if ($percent === null || $percent < 1 || $percent > 100) {
                return ActionResult::failure(
                    (string)__('Percent refund requires a "percent" between 1 and 100')
                );
            }
            $amount = round($paid * $percent / 100, 2);
            if ($amount <= 0.0) {
                return ActionResult::failure(
                    (string)__('Order %1 has no paid total to refund', $order->getIncrementId())
                );
            }
            return $amount;
        }

        // fixed
        $amount = $this->positiveFloatConfig($config, 'amount');
        if ($amount === null || $amount <= 0.0) {
            return ActionResult::failure(
                (string)__('Fixed refund requires an "amount" greater than 0')
            );
        }
        $remaining = round($paid - (float)$order->getTotalRefunded(), 2);
        if ($remaining <= 0.0) {
            return ActionResult::failure(
                (string)__('Order %1 has nothing left to refund', $order->getIncrementId())
            );
        }
        // Cap at the refundable remainder (documented policy).
        return round(min($amount, $remaining), 2);
    }

    /**
     * Creation arguments carrying the adjustment_positive, or a terminal failure
     * when the arguments factory is unavailable (partial modes need it).
     *
     * @return CreditmemoCreationArgumentsInterface|ActionResult
     */
    private function buildArguments(float $adjustment)
    {
        if ($this->argumentsFactory === null) {
            return ActionResult::failure(
                (string)__('Partial credit memos require the creditmemo arguments factory')
            );
        }
        $arguments = $this->argumentsFactory->create();
        $arguments->setAdjustmentPositive($adjustment);
        return $arguments;
    }

    /**
     * Strictly-positive numeric config value as float, or null.
     */
    private function positiveFloatConfig(array $config, string $key): ?float
    {
        $value = $config[$key] ?? null;
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return (float)$value;
    }
}
