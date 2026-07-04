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
}
