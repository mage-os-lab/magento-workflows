<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Action\Order;

use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * order.cancel — cancels the order when canCancel() allows it; otherwise skipped.
 * Naturally idempotent: an already-canceled order fails canCancel() and skips.
 */
class Cancel extends AbstractOrderAction implements SimulateableActionInterface
{
    public function getCode(): string
    {
        return 'order.cancel';
    }

    public function getLabel(): string
    {
        return 'Cancel Order';
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $order = $this->loadOrder($ctx);
        if ($order instanceof ActionResult) {
            return $order;
        }

        if (!$order->canCancel()) {
            return ActionResult::skipped(sprintf(
                'Order %s cannot be canceled (state "%s")',
                $order->getIncrementId(),
                $order->getState()
            ));
        }

        try {
            $order->cancel();
            $this->orderRepository->save($order);
        } catch (\Exception $e) {
            return ActionResult::failure('Could not cancel order: ' . $e->getMessage(), true);
        }

        return ActionResult::success(['state' => (string)$order->getState()]);
    }

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $order = $this->loadOrder($ctx);
        if ($order instanceof ActionResult) {
            return $order;
        }
        if (!$order->canCancel()) {
            return ActionResult::skipped(sprintf('Order %s cannot be canceled', $order->getIncrementId()));
        }
        return $this->simulated(sprintf('Cancel order %s', $order->getIncrementId()));
    }
}
