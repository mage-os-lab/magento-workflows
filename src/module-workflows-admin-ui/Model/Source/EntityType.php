<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Curated v1 entity type list (docs/05-triggers.md coverage: order/customer/catalog/quote/
 * invoice/creditmemo/shipment/review). Not sourced from a registry because none is exposed
 * as peer context; a trigger/entity metadata-driven list is a natural v2 follow-up.
 */
class EntityType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'sales_order', 'label' => __('Order')],
            ['value' => 'customer', 'label' => __('Customer')],
            ['value' => 'catalog_product', 'label' => __('Product')],
            ['value' => 'quote', 'label' => __('Cart')],
            ['value' => 'invoice', 'label' => __('Invoice')],
            ['value' => 'creditmemo', 'label' => __('Credit Memo')],
            ['value' => 'shipment', 'label' => __('Shipment')],
            ['value' => 'review', 'label' => __('Review')],
        ];
    }
}
