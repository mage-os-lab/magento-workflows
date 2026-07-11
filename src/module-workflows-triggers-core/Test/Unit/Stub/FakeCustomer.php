<?php
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Test\Unit\Stub;

use Magento\Customer\Model\Customer;

/**
 * Customer stand-in for the customer_save_after observer: carries id, the
 * current group and the ORIGINAL group snapshot ($origGroupId = null models
 * a brand-new customer). Skips the real Customer constructor in both
 * environments; accessors are self-contained.
 */
class FakeCustomer extends Customer
{
    /**
     * @param mixed $id
     * @param mixed $groupId
     * @param mixed $origGroupId null = new customer (no original data)
     */
    public function __construct(
        private $id = 0,
        private $groupId = 0,
        private $origGroupId = null
    ) {
    }

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return mixed
     */
    public function getGroupId()
    {
        return $this->groupId;
    }

    /**
     * @param string|null $key
     * @return mixed
     */
    public function getOrigData($key = null)
    {
        return $key === 'group_id' ? $this->origGroupId : null;
    }
}
