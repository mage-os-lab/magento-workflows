<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Test\Unit\Stub;

use Magento\Sales\Model\Order;

/**
 * Order stand-in for the sales_order_save_after observer: carries entity id,
 * current status and the ORIGINAL status snapshot ($origStatus = null models
 * a brand-new order, whose orig data has no 'status'). Skips the real Order
 * constructor in both environments; accessors are self-contained.
 */
class FakeOrder extends Order
{
    /**
     * @param mixed $id
     * @param mixed $status
     * @param mixed $origStatus null = new order (no original data)
     * @param mixed $increment
     */
    public function __construct(
        private $id = 0,
        private $status = '',
        private $origStatus = null,
        private $increment = ''
    ) {
    }

    public function getEntityId(): int
    {
        return (int) $this->id;
    }

    public function getIncrementId(): string
    {
        return (string) $this->increment;
    }

    /**
     * @return mixed
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @param string|null $key
     * @return mixed
     */
    public function getOrigData($key = null)
    {
        return $key === 'status' ? $this->origStatus : null;
    }
}
