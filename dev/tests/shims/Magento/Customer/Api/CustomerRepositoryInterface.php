<?php
declare(strict_types=1);

namespace Magento\Customer\Api;

/**
 * Standalone-runner shim for Magento\Customer\Api\CustomerRepositoryInterface.
 * Mirrors the FULL real interface (6 methods) with the real UNTYPED signatures
 * so a partial double is caught here, not only under real Magento.
 */
interface CustomerRepositoryInterface
{
    public function save($customer, $passwordHash = null);

    public function get($email, $websiteId = null);

    public function getById($customerId);

    public function getList($searchCriteria);

    public function delete($customer);

    public function deleteById($customerId);
}
