<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Action\Order;

use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\ActionResult;
use MageOS\Workflows\Model\Execution\ExecutionContext;

/**
 * order.hold — puts the order on hold when canHold() allows it; otherwise skipped.
 * Naturally idempotent: an already-held order fails canHold() and skips.
 */
class Hold extends AbstractOrderAction implements SimulateableActionInterface
{
    public function getCode(): string
    {
        return 'order.hold';
    }

    public function getLabel(): string
    {
        return 'Hold Order';
    }

    public function execute(ExecutionContext $ctx, array $config): ActionResult
    {
        $order = $this->loadOrder($ctx);
        if ($order instanceof ActionResult) {
            return $order;
        }

        if (!$order->canHold()) {
            return ActionResult::skipped(sprintf(
                'Order %s cannot be held (state "%s")',
                $order->getIncrementId(),
                $order->getState()
            ));
        }

        try {
            $order->hold();
            $this->orderRepository->save($order);
        } catch (\Exception $e) {
            return ActionResult::failure('Could not hold order: ' . $e->getMessage(), true);
        }

        return ActionResult::success(['state' => (string)$order->getState()]);
    }

    public function simulate(ExecutionContext $ctx, array $config): ActionResult
    {
        $order = $this->loadOrder($ctx);
        if ($order instanceof ActionResult) {
            return $order;
        }
        if (!$order->canHold()) {
            return ActionResult::skipped(sprintf('Order %s cannot be held', $order->getIncrementId()));
        }
        return $this->simulated(sprintf('Hold order %s', $order->getIncrementId()));
    }
}
