<?php
/**
 * Fixture (docs/20 §5, #15): a user-defined product EAV attribute flagged
 * is_used_for_promo_rules so the Product condition's loadAttributeOptions()
 * (CatalogRule-style promo/searchable introspection) auto-discovers it.
 *
 *   @magentoDataFixture MageOS_Workflows::Test/Integration/Rule/_files/product_promo_attribute.php
 */
declare(strict_types=1);

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

/** @var EavSetup $eavSetup */
$eavSetup = $objectManager->create(EavSetupFactory::class)->create();

$eavSetup->addAttribute(Product::ENTITY, 'wf_promo_flag', [
    'type' => 'int',
    'label' => 'WF Promo Flag',
    'input' => 'boolean',
    'source' => \Magento\Eav\Model\Entity\Attribute\Source\Boolean::class,
    'required' => false,
    'user_defined' => true,
    'is_used_for_promo_rules' => 1,
    'used_in_product_listing' => 1,
    'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
    'group' => 'General',
    'default' => 0,
]);
