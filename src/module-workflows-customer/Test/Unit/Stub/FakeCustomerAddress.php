<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Test\Unit\Stub;

use Magento\Customer\Model\Address;

/**
 * Customer address model stand-in for CustomerAddressChangeObserver. Carries the
 * flat address data the observer snapshots (country/region/postcode/city +
 * default flags) via DataObject's magic getters, plus a controllable orig
 * entity_id so the save seam can model created (no orig id) vs updated (orig id
 * present). Skips the real Address constructor in both environments.
 */
class FakeCustomerAddress extends Address
{
    /**
     * @param mixed $origEntityId null models a brand-new address (created)
     */
    public function __construct(
        array $data = [],
        private mixed $origEntityId = null
    ) {
        parent::__construct($data);
    }

    /**
     * @param string|null $key
     * @return mixed
     */
    public function getOrigData($key = null)
    {
        return $key === 'entity_id' ? $this->origEntityId : null;
    }
}
