<?php
declare(strict_types=1);

namespace Magento\Catalog\Api\Data;

/**
 * Standalone-runner shim for Magento\Catalog\Api\Data\ProductLinkInterface.
 *
 * Carries the FULL method surface of the real interface — not just the
 * sku/link-type/position accessors product.set_product_links writes. A test
 * double implementing only the subset it needs looks fine against a narrower
 * shim and fatals on a real install ("must implement getLinkedProductType()"),
 * so the shim declares everything the real contract does. That includes the
 * extension-attribute pair, whose parameter type (ProductLinkExtensionInterface)
 * is itself generated and never loaded here: PHP compares such a hint by name,
 * so an implementation repeating it verbatim is valid in both worlds.
 *
 * The real interface additionally extends
 * Magento\Framework\Api\ExtensibleDataInterface, a marker with no methods; it is
 * deliberately not mirrored, since nothing in this suite type-hints it.
 */
interface ProductLinkInterface
{
    public function getSku();

    public function setSku($sku);

    public function getLinkType();

    public function setLinkType($linkType);

    public function getLinkedProductSku();

    public function setLinkedProductSku($linkedProductSku);

    public function getLinkedProductType();

    public function setLinkedProductType($linkedProductType);

    public function getPosition();

    public function setPosition($position);

    public function getExtensionAttributes();

    public function setExtensionAttributes(
        \Magento\Catalog\Api\Data\ProductLinkExtensionInterface $extensionAttributes
    );
}
