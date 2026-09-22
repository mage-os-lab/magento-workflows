<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Aggregation;

use MageOS\Workflows\Api\Data\WorkflowInterface;

/**
 * Creates and persists the single (pending, entity_id = 0) execution row a
 * batch flush releases, returning its id. Extracted behind an interface so the
 * flush claim / write-before-publish logic is testable without Magento's
 * generated execution factory.
 */
interface BatchExecutionFactoryInterface
{
    /**
     * @param array<string, mixed> $batchContext the {batch,count,window,overflow,items} trigger
     * @return int the persisted execution id
     */
    public function create(WorkflowInterface $workflow, array $batchContext, int $storeId): int;
}
