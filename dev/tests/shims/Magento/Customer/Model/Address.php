<?php
declare(strict_types=1);

namespace Magento\Customer\Model;

use Magento\Framework\DataObject;

/**
 * Standalone-runner shim for Magento\Customer\Model\Address. Minimal
 * AbstractModel-flavoured surface: getId() returns the entity_id, getOrigData()
 * exposes the pre-save snapshot, and the remaining address getters
 * (getCustomerId/getCountryId/getRegion/getPostcode/getCity/getIsDefaultBilling
 * /getIsDefaultShipping) resolve through DataObject's magic accessors. Test
 * doubles extend this and set data / override getOrigData; the real class's
 * heavyweight constructor is skipped in both environments.
 */
class Address extends DataObject
{
    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->getData('entity_id');
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
