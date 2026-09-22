<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\AsyncEvents\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

/**
 * Standalone-runner shim for mage-os/mageos-async-events (4.x)
 * Api/Data/AsyncEventSearchResultsInterface.
 */
interface AsyncEventSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return \MageOS\AsyncEvents\Api\Data\AsyncEventDisplayInterface[]
     */
    public function getItems();

    /**
     * @param \MageOS\AsyncEvents\Api\Data\AsyncEventDisplayInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
