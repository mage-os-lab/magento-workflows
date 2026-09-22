<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Api\Data;

/**
 * Standalone-runner shim for the ProductLinkInterface factory: produces a
 * data-bag ProductLinkInterface so product.set_product_links can build link
 * objects without a Magento install.
 *
 * NOTE for test authors: on a real install this class does not exist as source.
 * It is a GENERATED factory, and the unit-test framework generates it with
 * Magento\Framework\TestFramework\Unit\Autoloader\FactoryGenerator — an EMPTY
 * create(array $data = []) body that returns null. Tests must therefore pass
 * their own factory double rather than `new ProductLinkInterfaceFactory()`;
 * this shim only keeps the standalone runner able to load the type.
 */
class ProductLinkInterfaceFactory
{
    public function create(array $data = []): ProductLinkInterface
    {
        return new class implements ProductLinkInterface {
            /** @var array<string, mixed> */
            private array $data = [];

            public function getSku()
            {
                return $this->data['sku'] ?? null;
            }

            public function setSku($sku)
            {
                $this->data['sku'] = $sku;
                return $this;
            }

            public function getLinkType()
            {
                return $this->data['link_type'] ?? null;
            }

            public function setLinkType($linkType)
            {
                $this->data['link_type'] = $linkType;
                return $this;
            }

            public function getLinkedProductSku()
            {
                return $this->data['linked_product_sku'] ?? null;
            }

            public function setLinkedProductSku($linkedProductSku)
            {
                $this->data['linked_product_sku'] = $linkedProductSku;
                return $this;
            }

            public function getLinkedProductType()
            {
                return $this->data['linked_product_type'] ?? null;
            }

            public function setLinkedProductType($linkedProductType)
            {
                $this->data['linked_product_type'] = $linkedProductType;
                return $this;
            }

            public function getPosition()
            {
                return $this->data['position'] ?? null;
            }

            public function setPosition($position)
            {
                $this->data['position'] = $position;
                return $this;
            }

            public function getExtensionAttributes()
            {
                return null;
            }

            public function setExtensionAttributes(
                \Magento\Catalog\Api\Data\ProductLinkExtensionInterface $extensionAttributes
            ) {
                return $this;
            }
        };
    }
}
