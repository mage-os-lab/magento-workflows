<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Action\Order;

use Magento\Framework\Exception\LocalizedException;
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
 * (no payment gateway call). canCreditmemo() guards redelivery for every mode:
 * a fully refunded order skips.
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

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        private readonly RefundOrderInterface $refundOrder,
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
            if ($mode === self::MODE_FULL) {
                // Empty items = refund everything refundable, offline
                $creditmemoId = $this->refundOrder->execute((int)$order->getEntityId(), [], $notify);
            } else {
                // Adjustment-only refund: no item lines, grand total = adjustment
                $creditmemoId = $this->refundOrder->execute(
                    (int)$order->getEntityId(),
                    [],
                    $notify,
                    false,
                    null,
                    $arguments
                );
            }
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
