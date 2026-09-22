<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Store\Model\System;

/**
 * Standalone-runner shim: the admin store catalogue behind the executions grid's
 * store filter and the execution detail's store label. The real class walks the
 * website/group/store collections; the tests only need a class to subclass and
 * the one accessor the block calls, so this default answers "no such store" and
 * lets each test override getStoreName().
 */
class Store
{
    /**
     * Website / group / store-view names joined by newlines in the real class,
     * or null when the id resolves to nothing.
     *
     * @param int $storeId
     * @return string|null
     */
    public function getStoreName($storeId)
    {
        return null;
    }
}
