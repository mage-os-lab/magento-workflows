<?php
/**
 * Fixture (docs/20 §2.4): a user-defined customer EAV attribute so the
 * Customer condition's loadAttributeOptions() auto-discovery
 * (CustomerMetadataInterface::getAllAttributesMetadata) can be pinned against
 * a real attribute. Referenced as:
 *
 *   @magentoDataFixture MageOS_Workflows::Test/Integration/Rule/_files/customer_custom_attribute.php
 */
declare(strict_types=1);

use Magento\Customer\Model\Customer;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

/** @var CustomerSetupFactory $customerSetupFactory */
$customerSetupFactory = $objectManager->create(CustomerSetupFactory::class);
$customerSetup = $customerSetupFactory->create();

$customerSetup->addAttribute(Customer::ENTITY, 'wf_loyalty_tier', [
    'type' => 'varchar',
    'label' => 'WF Loyalty Tier',
    'input' => 'text',
    'required' => false,
    'visible' => true,
    'user_defined' => true,
    'system' => false,
    'position' => 900,
    'sort_order' => 900,
]);

$attribute = $customerSetup->getEavConfig()->getAttribute(Customer::ENTITY, 'wf_loyalty_tier');
$attribute->setData('used_in_forms', ['adminhtml_customer']);
$attribute->save();
