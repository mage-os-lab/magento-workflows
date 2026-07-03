<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Action\Order;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipOrderInterface;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * order.create_shipment — ships all shippable items via the sales ShipOrder
 * service. canShip() guard makes redelivery safe: a fully shipped (or
 * unshippable) order skips instead of double-shipping.
 */
class CreateShipment extends AbstractOrderAction implements SimulateableActionInterface
{
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        private readonly ShipOrderInterface $shipOrder
    ) {
        parent::__construct($orderRepository);
    }

    public function getCode(): string
    {
        return 'order.create_shipment';
    }

    public function getLabel(): string
    {
        return (string)__('Create Shipment');
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

        if (!$order->canShip()) {
            return ActionResult::skipped(sprintf(
                'Order %s cannot be shipped (state "%s")',
                $order->getIncrementId(),
                $order->getState()
            ));
        }

        try {
            // Empty items = ship everything shippable
            $shipmentId = $this->shipOrder->execute((int)$order->getEntityId(), [], $notify);
        } catch (LocalizedException $e) {
            // Configuration/state problems will not resolve on redelivery
            return ActionResult::failure('Could not create shipment: ' . $e->getMessage());
        } catch (\Exception $e) {
            // Infrastructure flakiness (locks, connection drops) may succeed on retry
            return ActionResult::failure('Could not create shipment: ' . $e->getMessage(), true);
        }

        return ActionResult::success([
            'shipment_id' => (int)$shipmentId,
            'notify' => $notify,
        ]);
    }

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $order = $this->loadOrder($ctx);
        if ($order instanceof ActionResult) {
            return $order;
        }
        if (!$order->canShip()) {
            return ActionResult::skipped(sprintf('Order %s cannot be shipped', $order->getIncrementId()));
        }
        return $this->simulated(sprintf(
            'Create shipment for order %s (notify %s)',
            $order->getIncrementId(),
            $this->boolConfig($config, 'notify') ? 'yes' : 'no'
        ));
    }
}
