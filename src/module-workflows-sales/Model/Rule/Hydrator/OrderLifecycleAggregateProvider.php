<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Model\Rule\Hydrator;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use MageOS\Workflows\Model\Rule\AggregateProviderInterface;

/**
 * Order lifecycle-flag aggregates (ORD-C1): the guard booleans and document
 * counts a merchant reasons about but that never ride the trigger snapshot —
 * can_invoice / can_ship / can_creditmemo (straight from the order model's own
 * guards, the same predicates the create_invoice / create_shipment /
 * create_creditmemo actions honor), is_virtual, and invoice_count /
 * shipment_count.
 *
 * These make the document-creation actions safe to author: "if can_ship →
 * create shipment", "if can_creditmemo and total_refunded = 0 → notify".
 *
 * ORD-C3 adds `hours_in_current_status` (numeric): the elapsed hours since the
 * order LAST entered its current status. The source is the status-history rows,
 * NOT the order's flat updated_at — updated_at moves on every save (a comment, a
 * grid re-index, an unrelated attribute write), so it would answer "hours since
 * the order was last touched", not "hours in this status". The honest source is
 * the latest history row whose `status` equals the order's current status; its
 * `created_at` is the moment the order entered the status. Fallback when NO
 * history row matches the current status (e.g. the initial status set at
 * creation left no explicit history row, or histories are unavailable on the
 * hydrated model): the order's `created_at`. Rounding: elapsed seconds / 3600,
 * rounded HALF-UP to 2 decimals (round()), clamped at 0 so a clock skew that
 * puts the reference instant slightly in the future reads as 0, never negative.
 * When neither the matching history row NOR created_at yields a parseable
 * timestamp the attribute is ABSENT (indeterminable → fail-toward-false), the
 * same contract as a non-loadable order. This is the SLA-schedule enabler:
 * "escalate orders in 'processing' for more than 48 hours".
 *
 * Computed from the HYDRATED order model. The provider re-reads the order
 * through OrderRepositoryInterface::get — the same repository OrderHydrator
 * uses, which registry-caches the loaded entity, so this is not a second DB
 * load in practice — because the guards (canInvoice() etc.) live on the
 * concrete Magento\Sales\Model\Order and are not exposed on the flat DTO the
 * hydrator emits. Contributed to the sales_order condition root via
 * AggregateProviderPool under entity type 'sales_order' (E2); merged into the
 * hydrated order by OrderHydrator, so these attributes classify as
 * needs_hydration and resolve in phase 2.
 *
 * When the order cannot be loaded, ALL aggregates are absent (not null-set):
 * absent attributes only match the negative operators
 * (AbstractWorkflowCondition::validateAttribute(), fail-toward-false).
 */
class OrderLifecycleAggregateProvider implements AggregateProviderInterface
{
    /**
     * Attribute code => [label, workflow input type]. Labels are raw strings
     * ( __()-wrapped by the consuming condition root).
     */
    private const ATTRIBUTE_METADATA = [
        'can_invoice' => ['label' => 'Can Invoice', 'input_type' => 'boolean'],
        'can_ship' => ['label' => 'Can Ship', 'input_type' => 'boolean'],
        'can_creditmemo' => ['label' => 'Can Credit Memo', 'input_type' => 'boolean'],
        'is_virtual' => ['label' => 'Is Virtual Order', 'input_type' => 'boolean'],
        'invoice_count' => ['label' => 'Invoice Count', 'input_type' => 'numeric'],
        'shipment_count' => ['label' => 'Shipment Count', 'input_type' => 'numeric'],
        'hours_in_current_status' => ['label' => 'Hours In Current Status', 'input_type' => 'numeric'],
    ];

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository
    ) {
    }

    /**
     * @return array<string, array{label: string, input_type: string}>
     */
    public function getAttributeMetadata(): array
    {
        return self::ATTRIBUTE_METADATA;
    }

    /**
     * @return array{can_invoice: int, can_ship: int, can_creditmemo: int,
     *               is_virtual: int, invoice_count: int, shipment_count: int,
     *               hours_in_current_status?: float}
     */
    public function getAggregates(int $orderId): array
    {
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException | LocalizedException) {
            return [];
        }
        if (!$order instanceof Order) {
            return [];
        }

        $aggregates = [
            'can_invoice' => (int)$order->canInvoice(),
            'can_ship' => (int)$order->canShip(),
            'can_creditmemo' => (int)$order->canCreditmemo(),
            'is_virtual' => (int)(bool)$order->getIsVirtual(),
            'invoice_count' => (int)$order->getInvoiceCollection()->getSize(),
            'shipment_count' => (int)$order->getShipmentsCollection()->getSize(),
        ];

        $hours = $this->hoursInCurrentStatus($order);
        if ($hours !== null) {
            $aggregates['hours_in_current_status'] = $hours;
        }

        return $aggregates;
    }

    /**
     * Elapsed hours since the order last entered its current status (ORD-C3),
     * or null when indeterminable. Reference instant: the latest status-history
     * row whose status equals the order's current status; failing that, the
     * order's created_at. See the class docblock for the source rationale and
     * rounding rule.
     */
    private function hoursInCurrentStatus(Order $order): ?float
    {
        $currentStatus = trim((string)$order->getStatus());

        $referenceTs = null;
        if ($currentStatus !== '') {
            foreach ($order->getStatusHistories() ?: [] as $history) {
                if (trim((string)$history->getStatus()) !== $currentStatus) {
                    continue;
                }
                $ts = $this->parseUtcTimestamp($history->getCreatedAt());
                if ($ts !== null && ($referenceTs === null || $ts > $referenceTs)) {
                    $referenceTs = $ts;
                }
            }
        }

        if ($referenceTs === null) {
            // No history row records entry into the current status: fall back to
            // when the order was created (documented in the class docblock).
            $referenceTs = $this->parseUtcTimestamp($order->getCreatedAt());
        }

        if ($referenceTs === null) {
            return null;
        }

        $elapsedSeconds = max(0, $this->currentTimestamp() - $referenceTs);
        return round($elapsedSeconds / 3600, 2);
    }

    /**
     * Parse a Magento datetime string (UTC, 'Y-m-d H:i:s') to a Unix timestamp,
     * or null when absent/unparseable.
     */
    private function parseUtcTimestamp(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $ts = strtotime(trim($value) . ' UTC');
        return $ts === false ? null : $ts;
    }

    /**
     * "Now" as a Unix timestamp. Isolated behind a method so tests can pin the
     * clock and assert an exact elapsed-hours value.
     */
    protected function currentTimestamp(): int
    {
        return time();
    }
}
