<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Action\Order;

use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderCommentSender;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\ActionResult;
use MageOS\Workflows\Model\Idempotency\SendOnceGuard;

/**
 * order.send_email — (re)send a transactional order email. Two modes via config
 * `email_type` (default `order_confirmation`):
 *
 *  - order_confirmation — resend the order confirmation through
 *    OrderManagementInterface::notify($orderId). notify() is chosen over
 *    Order\Email\Sender\OrderSender because it is the public Sales API contract
 *    (an interface, trivially mockable and DI-swappable) that already wraps the
 *    OrderSender send + "email sent" bookkeeping; the action depends on the
 *    contract, not the concrete sender.
 *
 *  - comment — email the customer the LATEST customer-visible order comment
 *    through OrderCommentSender. This composes with order.add_comment (ORD-A1)
 *    and the sales.order.comment_added trigger (ORD-T2) rather than duplicating
 *    them: order.add_comment RECORDS the comment (no email), and
 *    order.send_email(comment) NOTIFIES the customer of the latest visible one.
 *    It deliberately does NOT accept comment_text-that-adds — that would
 *    re-implement order.add_comment. When the order carries no visible comment
 *    the step is SKIPPED (nothing to send), never a failure.
 *
 * Guard (both modes): a CANCELED order is a terminal failure — resending a
 * confirmation or comment for a canceled order is almost always an authoring
 * mistake, and it will not become valid on redelivery.
 *
 * Send-once guard (both modes): a DURABLE claim on the execution+step dedupe
 * key, taken BEFORE the send and backed by UNIQUE(claim_key) on
 * mageos_workflow_send_log — the same core SendOnceGuard notify.email uses,
 * because the hazard is identical and one policy is better than two. Mail
 * cannot be unsent, so under at-least-once delivery (docs/08) the executor's
 * resume-past-complete guard is not enough on its own: a crash INSIDE this
 * step, after the mail left but before the step row was marked complete, would
 * otherwise re-notify the customer on redelivery.
 *
 * The claim is taken only once there is something to send — after the mode,
 * order-state and (comment mode) "is there a visible comment" checks — so a
 * step that skips for lack of a comment leaves no claim behind and a later
 * legitimate run can still send.
 *
 * The trade, deliberately: a crash between claiming and confirming leaves a
 * `claimed` row, so a redelivery SKIPS a mail that may never have gone out,
 * and the skip reason says exactly that. A failure from the send call itself
 * KEEPS the claim and is terminal (never retryable): OrderManagement::notify()
 * reports one flat false / one MailException whether the MTA refused the
 * connection or accepted the message and died afterwards, and only one of
 * those two guesses avoids a customer receiving two copies.
 */
class SendEmail extends AbstractOrderAction implements SimulateableActionInterface
{
    private const TYPE_CONFIRMATION = 'order_confirmation';
    private const TYPE_COMMENT = 'comment';
    private const TYPES = [self::TYPE_CONFIRMATION, self::TYPE_COMMENT];

    /**
     * The send-once guard is a REQUIRED parameter, not a nullable convenience:
     * the ObjectManager does not auto-inject a parameter that has a default
     * value, so an "optional" guard would arrive null in production and this
     * action would quietly go back to double-mailing customers.
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        private readonly OrderManagementInterface $orderManagement,
        private readonly OrderCommentSender $orderCommentSender,
        private readonly SendOnceGuard $sendOnceGuard
    ) {
        parent::__construct($orderRepository);
    }

    public function getCode(): string
    {
        return 'order.send_email';
    }

    public function getLabel(): string
    {
        return (string)__('Send Order Email');
    }

    public function getConfigForm(): array
    {
        return [
            ['name' => 'email_type', 'label' => 'Email Type', 'type' => 'select', 'required' => false,
                'default' => self::TYPE_CONFIRMATION,
                'options' => [
                    ['value' => self::TYPE_CONFIRMATION, 'label' => 'Order confirmation (resend)'],
                    ['value' => self::TYPE_COMMENT, 'label' => 'Latest visible comment'],
                ]],
        ];
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $type = $this->resolveType($config);
        if ($type === null) {
            return ActionResult::failure(
                (string)__('Invalid email_type "%1" (order_confirmation|comment)', $this->stringConfig($config, 'email_type'))
            );
        }

        $order = $this->loadOrder($ctx);
        if ($order instanceof ActionResult) {
            return $order;
        }
        if ($order->getState() === Order::STATE_CANCELED) {
            return ActionResult::failure(
                (string)__('Order %1 is canceled; no email is sent', $order->getIncrementId())
            );
        }

        $dedupeKey = $ctx->getDedupeKey($this->stepKey($ctx));

        return $type === self::TYPE_CONFIRMATION
            ? $this->sendConfirmation($order, $dedupeKey)
            : $this->sendLatestComment($order, $dedupeKey);
    }

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $type = $this->resolveType($config);
        if ($type === null) {
            return ActionResult::failure(
                (string)__('Invalid email_type "%1" (order_confirmation|comment)', $this->stringConfig($config, 'email_type'))
            );
        }

        $order = $this->loadOrder($ctx);
        if ($order instanceof ActionResult) {
            return $order;
        }
        if ($order->getState() === Order::STATE_CANCELED) {
            return ActionResult::failure(
                (string)__('Order %1 is canceled; no email is sent', $order->getIncrementId())
            );
        }

        if ($type === self::TYPE_COMMENT && $this->latestVisibleComment($order) === null) {
            return ActionResult::skipped(
                sprintf('Order %s has no visible comment to send', $order->getIncrementId())
            );
        }

        return $this->simulated(sprintf(
            'Send "%s" email for order %s',
            $type,
            $order->getIncrementId()
        ));
    }

    private function sendConfirmation(Order $order, string $dedupeKey): ActionResultInterface
    {
        $claim = $this->claim($dedupeKey);
        if ($claim instanceof ActionResult) {
            return $claim;
        }

        try {
            $notified = (bool)$this->orderManagement->notify((int)$order->getEntityId());
        } catch (\Exception $e) {
            // The send call was entered, so the claim stands (see the class
            // docblock) and this is terminal: a redelivery could only skip.
            return ActionResult::failure($this->unconfirmedSendError(
                'Could not send order email: ' . $e->getMessage()
            ));
        }

        if (!$notified) {
            // notify() swallows the transport error and reports one flat
            // false — it may mean "email disabled" or "the MTA took it and the
            // link dropped". Terminal with the claim retained, for the same
            // reason: never guess in the direction of a second copy.
            return ActionResult::failure($this->unconfirmedSendError(
                (string)__('Order %1 confirmation email was not sent', $order->getIncrementId())
            ));
        }

        $this->sendOnceGuard->confirm($this->getCode(), $dedupeKey);

        return ActionResult::success([
            'email_type' => self::TYPE_CONFIRMATION,
            'notified' => true,
        ]);
    }

    private function sendLatestComment(Order $order, string $dedupeKey): ActionResultInterface
    {
        // Resolve BEFORE claiming: "nothing to send" must not burn the claim,
        // or a later run that does have a comment would be suppressed.
        $comment = $this->latestVisibleComment($order);
        if ($comment === null) {
            return ActionResult::skipped(
                sprintf('Order %s has no visible comment to send', $order->getIncrementId())
            );
        }

        $claim = $this->claim($dedupeKey);
        if ($claim instanceof ActionResult) {
            return $claim;
        }

        try {
            $this->orderCommentSender->send($order, true, $comment);
        } catch (\Exception $e) {
            return ActionResult::failure($this->unconfirmedSendError(
                'Could not send order comment email: ' . $e->getMessage()
            ));
        }

        $this->sendOnceGuard->confirm($this->getCode(), $dedupeKey);

        return ActionResult::success([
            'email_type' => self::TYPE_COMMENT,
            'comment_sent' => true,
        ]);
    }

    /**
     * Take the send claim, or return the ActionResult that ends the step:
     * a SKIP when somebody already claimed this (execution, step) — the
     * redelivery case — or a retryable FAILURE when the claim store could not
     * answer at all, because sending under an unanswered guard is the one
     * outcome that must never happen.
     *
     * @return true|ActionResult true = claimed, proceed
     */
    private function claim(string $dedupeKey)
    {
        try {
            $claimed = $this->sendOnceGuard->claim($this->getCode(), $dedupeKey);
        } catch (\Exception $e) {
            return ActionResult::failure(
                'Could not claim the order email send: ' . $e->getMessage(),
                true
            );
        }
        if (!$claimed) {
            return ActionResult::skipped($this->sendOnceGuard->describeClaim($this->getCode(), $dedupeKey));
        }
        return true;
    }

    /**
     * Failure text for a send whose outcome is unknowable. Spelled out because
     * the operator has to make the call: the step will NOT retry, and the mail
     * may or may not have gone out.
     */
    private function unconfirmedSendError(string $detail): string
    {
        return $detail
            . ' — the send was already claimed, so it will NOT be retried automatically'
            . ' (the message may or may not have left the mail server).';
    }

    /**
     * The most recent customer-visible, non-empty status-history comment, with
     * workflow dedupe markers stripped, or null when the order has none.
     */
    private function latestVisibleComment(Order $order): ?string
    {
        $latestTs = null;
        $latest = null;
        foreach ($order->getStatusHistories() ?: [] as $history) {
            if (!$history->getIsVisibleOnFront()) {
                continue;
            }
            $comment = $this->stripMarkers((string)$history->getComment());
            if ($comment === '') {
                continue;
            }
            $ts = strtotime((string)$history->getCreatedAt() . ' UTC');
            $ts = $ts === false ? 0 : $ts;
            if ($latestTs === null || $ts >= $latestTs) {
                $latestTs = $ts;
                $latest = $comment;
            }
        }
        return $latest;
    }

    /**
     * Remove any HTML-comment dedupe markers (e.g. order.add_comment's
     * "<!-- mageos-workflows:KEY -->") so they never leak into the email.
     */
    private function stripMarkers(string $comment): string
    {
        return trim((string)preg_replace('/<!--.*?-->/s', '', $comment));
    }

    /**
     * The requested email type (default order_confirmation), or null when an
     * explicit type is unrecognized.
     */
    private function resolveType(array $config): ?string
    {
        $type = $this->stringConfig($config, 'email_type', self::TYPE_CONFIRMATION);
        return in_array($type, self::TYPES, true) ? $type : null;
    }
}
