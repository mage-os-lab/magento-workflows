<?php
declare(strict_types=1);

namespace Magento\Store\Model;

/**
 * Standalone-runner shim for Magento\Store\Model\StoreManagerInterface —
 * only the surface the workflow modules touch (store lookup for
 * store_id => website_id mapping).
 */
interface StoreManagerInterface
{
    /**
     * @param int|string|null $storeId
     * @return mixed store object exposing getWebsiteId()
     */
    public function getStore($storeId = null);
}
