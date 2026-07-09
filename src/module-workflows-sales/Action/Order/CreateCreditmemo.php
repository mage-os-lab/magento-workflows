<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Action\Order;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\RefundOrderInterface;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * order.create_creditmemo — full OFFLINE refund of all refundable items via
 * the sales RefundOrder service (no payment gateway call). canCreditmemo()
 * guard makes redelivery safe: a fully refunded order skips.
 */
class CreateCreditmemo extends AbstractOrderAction implements SimulateableActionInterface
{
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        private readonly RefundOrderInterface $refundOrder
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
            ['name' => 'notify', 'label' => 'Notify Customer', 'type' => 'boolean', 'required' => false,
                'default' => false],
        ];
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $notify = $this->boolConfig($config, 'notify');

        $order = $this->loadOrder($ctx);
        if ($order instanceof ActionResult) {
            return $order;
        }

        if (!$order->canCreditmemo()) {
            return ActionResult::skipped(sprintf(
                'Order %s cannot be refunded (state "%s")',
                $order->getIncrementId(),
                $order->getState()
            ));
        }

        try {
            // Empty items = refund everything refundable, offline
            $creditmemoId = $this->refundOrder->execute((int)$order->getEntityId(), [], $notify);
        } catch (LocalizedException $e) {
            // Configuration/state problems will not resolve on redelivery
            return ActionResult::failure('Could not create credit memo: ' . $e->getMessage());
        } catch (\Exception $e) {
            // Infrastructure flakiness (locks, connection drops) may succeed on retry
            return ActionResult::failure('Could not create credit memo: ' . $e->getMessage(), true);
        }

        return ActionResult::success([
            'creditmemo_id' => (int)$creditmemoId,
            'notify' => $notify,
        ]);
    }

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $order = $this->loadOrder($ctx);
        if ($order instanceof ActionResult) {
            return $order;
        }
        if (!$order->canCreditmemo()) {
            return ActionResult::skipped(sprintf('Order %s cannot be refunded', $order->getIncrementId()));
        }
        return $this->simulated(sprintf(
            'Create offline credit memo for order %s (notify %s)',
            $order->getIncrementId(),
            $this->boolConfig($config, 'notify') ? 'yes' : 'no'
        ));
    }
}
