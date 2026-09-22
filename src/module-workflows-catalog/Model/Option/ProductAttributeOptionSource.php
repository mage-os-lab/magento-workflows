<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsCatalog\Model\Option;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as ProductAttributeCollectionFactory;
use MageOS\Workflows\Model\Option\AbstractOptionSource;
use MageOS\WorkflowsCatalog\Action\Product\SetAttribute;

/**
 * Search-typed option source: writable catalog_product attribute codes (F6),
 * labelled "Attribute Label (code)" so the author recognises the attribute and
 * still sees the code that gets persisted. A store's attribute set can run to
 * hundreds of rows, so product.set_attribute references it with min_chars: 0
 * and the client queries as the user types (AbstractOptionSource filters + caps).
 *
 * The security denylist is NOT restated here: the picker asks the action that
 * enforces it, so the list stays declared exactly once — in di.xml, on
 * product.set_attribute — and a merchant extending it there also shrinks the
 * picker. Deny-listed codes would only ever be offered to be refused.
 */
class ProductAttributeOptionSource extends AbstractOptionSource
{
    public function __construct(
        private readonly ProductAttributeCollectionFactory $attributeCollectionFactory,
        private readonly SetAttribute $setAttributeAction
    ) {
    }

    public function getCode(): string
    {
        return 'product_attributes';
    }

    /**
     * @inheritDoc
     */
    protected function loadOptions(): array
    {
        $options = [];
        foreach ($this->attributeCollectionFactory->create() as $attribute) {
            $code = (string) $attribute->getAttributeCode();
            if ($code === '' || $this->setAttributeAction->isDenied($code)) {
                continue;
            }
            $label = trim((string) $attribute->getFrontendLabel());
            $options[] = [
                'value' => $code,
                'label' => $label !== '' ? sprintf('%s (%s)', $label, $code) : $code,
            ];
        }
        return $options;
    }
}
