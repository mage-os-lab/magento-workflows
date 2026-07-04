<?php
declare(strict_types=1);

namespace Magento\Framework\Api;

/**
 * Minimal shim for Magento\Framework\Api\AttributeInterface — the constants and
 * accessors EntityDataConverter reads when lifting custom_attributes to
 * top-level keys.
 */
interface AttributeInterface
{
    public const ATTRIBUTE_CODE = 'attribute_code';
    public const VALUE = 'value';

    public function getAttributeCode();

    public function setAttributeCode($attributeCode);

    public function getValue();

    public function setValue($value);
}
