<?php
/**
 * Fixture (docs/20 §2.4): a user-defined customer EAV attribute so the
 * Customer condition's loadAttributeOptions() auto-discovery
 * (CustomerMetadataInterface::getAllAttributesMetadata) can be pinned against
 * a real attribute. Referenced as:
 *
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 *
 *   @magentoDataFixture MageOS_Workflows::Test/Integration/Rule/_files/customer_custom_attribute.php
 */
declare(strict_types=1);

use Magento\Customer\Model\Customer;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Eav\Model\Config as EavConfig;
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
    // Must land in the default customer attribute set: CustomerMetadata's
    // getAllAttributesMetadata() reads codes scoped to ATTRIBUTE_SET_ID_CUSTOMER.
    // addAttribute only assigns to sets when a 'group' is given (a user_defined
    // attribute is otherwise left unassigned), so without this the condition's
    // auto-discovery never sees it.
    'group' => 'General',
]);

$attribute = $customerSetup->getEavConfig()->getAttribute(Customer::ENTITY, 'wf_loyalty_tier');
$attribute->setData('used_in_forms', ['adminhtml_customer']);
$attribute->save();

// Bust the EAV attribute cache warmed by earlier test methods (no app isolation
// on the sibling cases), so the freshly added attribute is discoverable.
$objectManager->get(EavConfig::class)->clear();
