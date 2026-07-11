<?php
declare(strict_types=1);

namespace Magento\Catalog\Api\Data;

/**
 * Standalone-runner shim for the ProductLinkInterface factory: produces a
 * data-bag ProductLinkInterface so product.set_product_links can build link
 * objects without a Magento install.
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

            public function getPosition()
            {
                return $this->data['position'] ?? null;
            }

            public function setPosition($position)
            {
                $this->data['position'] = $position;
                return $this;
            }
        };
    }
}
