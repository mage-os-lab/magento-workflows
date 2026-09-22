<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Customer\Api;

/**
 * Standalone-runner shim for Magento\Customer\Api\CustomerMetadataInterface.
 * Mirrors the FULL real surface (the four MetadataInterface methods, real
 * UNTYPED signatures, plus the entity-type constants) so a partial double is
 * caught here, not only under real Magento.
 */
interface CustomerMetadataInterface
{
    public const ENTITY_TYPE_CUSTOMER = 'customer';

    public const ATTRIBUTE_SET_ID_CUSTOMER = 1;

    public const DATA_INTERFACE_NAME = \Magento\Customer\Api\Data\CustomerInterface::class;

    public function getAttributes($formCode);

    public function getAttributeMetadata($attributeCode);

    public function getAllAttributesMetadata();

    public function getCustomAttributesMetadata($dataObjectClassName = self::DATA_INTERFACE_NAME);
}
