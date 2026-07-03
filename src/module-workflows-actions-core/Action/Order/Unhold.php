<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Action\Order;

use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * order.unhold — releases a held order when canUnhold() allows it; otherwise skipped.
 * Naturally idempotent: a not-held order fails canUnhold() and skips.
 */
class Unhold extends AbstractOrderAction implements SimulateableActionInterface
{
    public function getCode(): string
    {
        return 'order.unhold';
    }

    public function getLabel(): string
    {
        return 'Unhold Order';
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $order = $this->loadOrder($ctx);
        if ($order instanceof ActionResult) {
            return $order;
        }

        if (!$order->canUnhold()) {
            return ActionResult::skipped(sprintf(
                'Order %s cannot be unheld (state "%s")',
                $order->getIncrementId(),
                $order->getState()
            ));
        }

        try {
            $order->unhold();
            $this->orderRepository->save($order);
        } catch (\Exception $e) {
            return ActionResult::failure('Could not unhold order: ' . $e->getMessage(), true);
        }

        return ActionResult::success(['state' => (string)$order->getState()]);
    }

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $order = $this->loadOrder($ctx);
        if ($order instanceof ActionResult) {
            return $order;
        }
        if (!$order->canUnhold()) {
            return ActionResult::skipped(sprintf('Order %s cannot be unheld', $order->getIncrementId()));
        }
        return $this->simulated(sprintf('Unhold order %s', $order->getIncrementId()));
    }
}
