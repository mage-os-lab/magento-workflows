<?php
declare(strict_types=1);

namespace Magento\Catalog\Api\Data;

/**
 * Standalone-runner shim for Magento\Catalog\Api\Data\ProductLinkInterface:
 * the sku/link-type/position surface product.set_product_links writes.
 */
interface ProductLinkInterface
{
    public function getSku();

    public function setSku($sku);

    public function getLinkType();

    public function setLinkType($linkType);

    public function getLinkedProductSku();

    public function setLinkedProductSku($linkedProductSku);

    public function getPosition();

    public function setPosition($position);
}
