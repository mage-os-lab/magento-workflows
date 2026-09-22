<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\AsyncEvents\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use MageOS\AsyncEvents\Api\Data\AsyncEventDisplayInterface;
use MageOS\AsyncEvents\Api\Data\AsyncEventInterface;
use MageOS\AsyncEvents\Api\Data\AsyncEventSearchResultsInterface;

/**
 * Standalone-runner shim for mage-os/mageos-async-events (4.x)
 * Api/AsyncEventRepositoryInterface. Signature-faithful: get()/save() return
 * the display read model (the concrete AsyncEvent model implements both it
 * and AsyncEventInterface), and the 4.x repository declares no delete().
 */
interface AsyncEventRepositoryInterface
{
    /**
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function get(int $subscriptionId): AsyncEventDisplayInterface;

    public function getList(SearchCriteriaInterface $searchCriteria): AsyncEventSearchResultsInterface;

    /**
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function save(AsyncEventInterface $asyncEvent, bool $checkResources = true): AsyncEventDisplayInterface;
}
