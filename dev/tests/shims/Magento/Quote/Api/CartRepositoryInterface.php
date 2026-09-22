<?php
declare(strict_types=1);

namespace Magento\Quote\Api;

/**
 * Standalone-runner shim for Magento\Quote\Api\CartRepositoryInterface.
 * Mirrors the FULL real interface (7 methods) so a partial double is caught
 * here, not only under real Magento. Parameters are left UNTYPED where the real
 * interface types them (getList/save/delete): a double written against these
 * signatures stays valid under the real interface too — PHP allows a parameter
 * type to be widened, never narrowed — while an untyped shim avoids forcing
 * every double to also implement SearchCriteriaInterface/CartInterface.
 */
interface CartRepositoryInterface
{
    public function get($cartId, array $sharedStoreIds = []);

    public function getList($searchCriteria);

    public function getForCustomer($customerId, array $sharedStoreIds = []);

    public function getActive($cartId, array $sharedStoreIds = []);

    public function getActiveForCustomer($customerId, array $sharedStoreIds = []);

    public function save($quote);

    public function delete($quote);
}
