<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Action\Order;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderCommentSender;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\ActionResult;

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
 * Idempotency note: transactional email is inherently at-least-once here (a
 * redelivery re-notifies). Author it on a once-only trigger, or gate it, when a
 * duplicate email would matter; the engine's chain-depth guard bounds any
 * trigger→email→trigger interaction.
 */
class SendEmail extends AbstractOrderAction implements SimulateableActionInterface
{
    private const TYPE_CONFIRMATION = 'order_confirmation';
    private const TYPE_COMMENT = 'comment';
    private const TYPES = [self::TYPE_CONFIRMATION, self::TYPE_COMMENT];

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        private readonly OrderManagementInterface $orderManagement,
        private readonly OrderCommentSender $orderCommentSender
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

        return $type === self::TYPE_CONFIRMATION
            ? $this->sendConfirmation($order)
            : $this->sendLatestComment($order);
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

    private function sendConfirmation(Order $order): ActionResultInterface
    {
        try {
            $notified = (bool)$this->orderManagement->notify((int)$order->getEntityId());
        } catch (LocalizedException $e) {
            return ActionResult::failure('Could not send order email: ' . $e->getMessage());
        } catch (\Exception $e) {
            // Mail transport / infra flakiness may succeed on retry
            return ActionResult::failure('Could not send order email: ' . $e->getMessage(), true);
        }

        if (!$notified) {
            // notify() reports the send did not go out; a retry may succeed.
            return ActionResult::failure(
                (string)__('Order %1 confirmation email was not sent', $order->getIncrementId()),
                true
            );
        }

        return ActionResult::success([
            'email_type' => self::TYPE_CONFIRMATION,
            'notified' => true,
        ]);
    }

    private function sendLatestComment(Order $order): ActionResultInterface
    {
        $comment = $this->latestVisibleComment($order);
        if ($comment === null) {
            return ActionResult::skipped(
                sprintf('Order %s has no visible comment to send', $order->getIncrementId())
            );
        }

        try {
            $this->orderCommentSender->send($order, true, $comment);
        } catch (LocalizedException $e) {
            return ActionResult::failure('Could not send order comment email: ' . $e->getMessage());
        } catch (\Exception $e) {
            return ActionResult::failure('Could not send order comment email: ' . $e->getMessage(), true);
        }

        return ActionResult::success([
            'email_type' => self::TYPE_COMMENT,
            'comment_sent' => true,
        ]);
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
