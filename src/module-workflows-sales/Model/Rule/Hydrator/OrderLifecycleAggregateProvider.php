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
     *               is_virtual: int, invoice_count: int, shipment_count: int}
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

        return [
            'can_invoice' => (int)$order->canInvoice(),
            'can_ship' => (int)$order->canShip(),
            'can_creditmemo' => (int)$order->canCreditmemo(),
            'is_virtual' => (int)(bool)$order->getIsVirtual(),
            'invoice_count' => (int)$order->getInvoiceCollection()->getSize(),
            'shipment_count' => (int)$order->getShipmentsCollection()->getSize(),
        ];
    }
}
