<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework;

/**
 * Standalone-runner shim for Magento\Framework\Event. Data-bag event object;
 * tests construct it with the payload array exactly as they would the real
 * class (new Event(['order' => $order, 'name' => 'sales_order_save_after'])).
 */
class Event extends DataObject
{
    /**
     * @return mixed
     */
    public function getName()
    {
        return $this->getData('name');
    }

    /**
     * @param mixed $name
     * @return $this
     */
    public function setName($name)
    {
        return $this->setData('name', $name);
    }
}
