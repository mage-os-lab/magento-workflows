<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Test\Unit\Stub;

use Magento\Customer\Model\Address;

/**
 * Customer address model stand-in for CustomerAddressChangeObserver. Carries the
 * flat address data the observer snapshots (country/region/postcode/city +
 * default flags) plus a controllable orig entity_id so the save seam can model
 * created (no orig id) vs updated (orig id present).
 *
 * Skips the real Address constructor in BOTH environments: the real
 * Magento\Customer\Model\Address constructor's first argument is a
 * Magento\Framework\Model\Context (part of a large DI graph), so forwarding the
 * flat data array to parent::__construct() is a TypeError under real Magento.
 * Instead every accessor the observer touches (getId, getCustomerId,
 * getCountryId, getRegion, getPostcode, getCity, getIsDefaultBilling,
 * getIsDefaultShipping, getOrigData) is overridden to read this fake's own data
 * array, so the uninitialised real parent is never invoked. The class still
 * genuinely extends Address so production `instanceof Address` checks hold.
 */
class FakeCustomerAddress extends Address
{
    /**
     * @param array<string, mixed> $data flat address data (entity_id/customer_id/country_id/...)
     * @param mixed $origEntityId null models a brand-new address (created)
     */
    public function __construct(
        private array $data = [],
        private mixed $origEntityId = null
    ) {
        // Intentionally do NOT call parent::__construct(): the real Address
        // constructor requires an injected dependency graph, not a data array.
    }

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->data['entity_id'] ?? null;
    }

    /**
     * @return mixed
     */
    public function getCustomerId()
    {
        return $this->data['customer_id'] ?? null;
    }

    /**
     * @return mixed
     */
    public function getCountryId()
    {
        return $this->data['country_id'] ?? null;
    }

    /**
     * @return mixed
     */
    public function getRegion()
    {
        return $this->data['region'] ?? null;
    }

    /**
     * @return mixed
     */
    public function getPostcode()
    {
        return $this->data['postcode'] ?? null;
    }

    /**
     * @return mixed
     */
    public function getCity()
    {
        return $this->data['city'] ?? null;
    }

    /**
     * @return mixed
     */
    public function getIsDefaultBilling()
    {
        return $this->data['is_default_billing'] ?? null;
    }

    /**
     * @return mixed
     */
    public function getIsDefaultShipping()
    {
        return $this->data['is_default_shipping'] ?? null;
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
