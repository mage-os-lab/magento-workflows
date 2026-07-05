<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Stub;

use Magento\Framework\DataObject;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Minimal StoreManagerInterface stand-in: every store maps to the given website
 * id (default 1). Enough for RelationContext's store_id -> website_id mapping in
 * unit tests that do not exercise per-website scoping.
 */
class StubStoreManager implements StoreManagerInterface
{
    public function __construct(private readonly int $websiteId = 1)
    {
    }

    public function getStore($storeId = null)
    {
        return new DataObject(['website_id' => $this->websiteId]);
    }

    public function setIsSingleStoreModeAllowed($value)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function hasSingleStore()
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function isSingleStoreMode()
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getStores($withDefault = false, $codeKey = false)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getWebsite($websiteId = null)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getWebsites($withDefault = false, $codeKey = false)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function reinitStores()
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getDefaultStoreView()
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getGroup($groupId = null)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getGroups($withDefault = false)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function setCurrentStore($store)
    {
        throw new \BadMethodCallException(__METHOD__);
    }
}
