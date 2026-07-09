<?php
declare(strict_types=1);

namespace Magento\Sales\Api\Data;

/**
 * Standalone-runner shim for
 * Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterface. Only the
 * adjustment-positive accessors order.create_creditmemo touches (ORD-A3 partial
 * refunds) are declared; test doubles implement it directly.
 */
interface CreditmemoCreationArgumentsInterface
{
    /**
     * @param float $amount
     * @return $this
     */
    public function setAdjustmentPositive($amount);

    /**
     * @return float|null
     */
    public function getAdjustmentPositive();
}
