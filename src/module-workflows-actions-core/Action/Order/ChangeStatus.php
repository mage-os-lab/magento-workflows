<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Action\Order;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Config as OrderConfig;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\ActionResult;
use MageOS\Workflows\Model\Execution\ExecutionContext;

/**
 * order.change_status — sets a new order status WITHIN the order's current
 * state. Transitions are validated against the state machine: the target
 * status must be assigned to the order's current state
 * (Magento\Sales\Model\Order\Config::getStateStatuses). Changing state
 * (e.g. processing -> complete) is the job of the dedicated invoice/cancel/
 * hold actions — an invalid transition is a terminal step failure, not
 * silent data corruption.
 */
class ChangeStatus extends AbstractOrderAction implements SimulateableActionInterface
{
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        private readonly OrderConfig $orderConfig
    ) {
        parent::__construct($orderRepository);
    }

    public function getCode(): string
    {
        return 'order.change_status';
    }

    public function getLabel(): string
    {
        return 'Change Order Status';
    }

    public function getConfigForm(): array
    {
        return [
            [
                'name' => 'status',
                'label' => 'Target Status',
                'type' => 'text',
                'required' => true,
                'notice' => 'Must be a status assigned to the order\'s current state; state changes are rejected.',
            ],
        ];
    }

    public function execute(ExecutionContext $ctx, array $config): ActionResult
    {
        $status = $this->stringConfig($config, 'status');
        if ($status === null) {
            return $this->missingConfig('status');
        }

        $order = $this->loadOrder($ctx);
        if ($order instanceof ActionResult) {
            return $order;
        }

        if ($order->getStatus() === $status) {
            return ActionResult::skipped(sprintf('Order already has status "%s"', $status));
        }

        $state = (string)$order->getState();
        $allowed = $this->orderConfig->getStateStatuses($state);
        if (!array_key_exists($status, $allowed)) {
            return ActionResult::failure(sprintf(
                'Status "%s" is not valid for order state "%s" (allowed: %s)',
                $status,
                $state,
                implode(', ', array_keys($allowed))
            ));
        }

        $previousStatus = (string)$order->getStatus();
        $workflowName = (string)($ctx->getWorkflow()['name'] ?? 'workflow');
        try {
            // Same state + new status: a legal transition within the state machine
            $order->addCommentToStatusHistory(
                sprintf('Status changed by workflow "%s".', $workflowName),
                $status,
                false
            );
            $this->orderRepository->save($order);
        } catch (\Exception $e) {
            return ActionResult::failure('Could not change order status: ' . $e->getMessage(), true);
        }

        return ActionResult::success([
            'previous_status' => $previousStatus,
            'status' => $status,
        ]);
    }

    public function simulate(ExecutionContext $ctx, array $config): ActionResult
    {
        $status = $this->stringConfig($config, 'status');
        if ($status === null) {
            return $this->missingConfig('status');
        }

        $order = $this->loadOrder($ctx);
        if ($order instanceof ActionResult) {
            return $order;
        }

        if ($order->getStatus() === $status) {
            return ActionResult::skipped(sprintf('Order already has status "%s"', $status));
        }

        $allowed = $this->orderConfig->getStateStatuses((string)$order->getState());
        if (!array_key_exists($status, $allowed)) {
            return ActionResult::failure(sprintf(
                'Status "%s" is not valid for order state "%s"',
                $status,
                (string)$order->getState()
            ));
        }

        return $this->simulated(sprintf(
            'Change order %s status from "%s" to "%s"',
            $order->getIncrementId(),
            (string)$order->getStatus(),
            $status
        ));
    }
}
