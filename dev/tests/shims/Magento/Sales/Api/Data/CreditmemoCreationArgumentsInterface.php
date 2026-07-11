<?php
declare(strict_types=1);

namespace Magento\Sales\Api\Data;

/**
 * Standalone-runner shim for
 * Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterface. Mirrors the FULL
 * real interface (8 accessors) with loose signatures so a partial double is
 * caught here, not only under real Magento. Only the adjustment-positive
 * accessors are exercised by order.create_creditmemo (ORD-A3 partial refunds).
 */
interface CreditmemoCreationArgumentsInterface
{
    public function getShippingAmount();

    public function setShippingAmount($amount);

    public function getAdjustmentPositive();

    public function setAdjustmentPositive($amount);

    public function getAdjustmentNegative();

    public function setAdjustmentNegative($amount);

    public function getExtensionAttributes();

    public function setExtensionAttributes($extensionAttributes);
}
