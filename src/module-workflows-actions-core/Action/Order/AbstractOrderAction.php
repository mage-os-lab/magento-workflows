<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Action\Order;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Model\Action\AbstractAction;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * Shared plumbing for sales_order actions: loading the order model behind
 * the execution's entity id with uniform not-found handling.
 */
abstract class AbstractOrderAction extends AbstractAction
{
    public function __construct(
        protected readonly OrderRepositoryInterface $orderRepository
    ) {
    }

    public function getGroup(): string
    {
        return 'Sales';
    }

    public function getApplicableEntities(): array
    {
        return ['sales_order'];
    }

    /**
     * Order model for the execution entity, or a terminal ActionResult failure
     */
    protected function loadOrder(ExecutionContextInterface $ctx): Order|ActionResult
    {
        try {
            $order = $this->orderRepository->get($ctx->getEntityId());
        } catch (NoSuchEntityException $e) {
            return ActionResult::failure(sprintf('Order %d not found', $ctx->getEntityId()));
        }
        if (!$order instanceof Order) {
            return ActionResult::failure('Unexpected order implementation returned by repository');
        }
        return $order;
    }
}
