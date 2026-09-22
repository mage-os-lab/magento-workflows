<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Customer\Model;

use Magento\Framework\DataObject;

/**
 * Standalone-runner shim for Magento\Customer\Model\Customer. Minimal
 * AbstractModel-flavoured surface (getId/getGroupId/getOrigData); test
 * doubles extend it, override the accessors they exercise, and skip the
 * real class's heavyweight constructor in both environments.
 */
class Customer extends DataObject
{
    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->getData('entity_id');
    }

    /**
     * @return mixed
     */
    public function getGroupId()
    {
        return $this->getData('group_id');
    }

    /**
     * @param string|null $key
     * @return mixed
     */
    public function getOrigData($key = null)
    {
        return null;
    }
}
