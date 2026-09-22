<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model;

use Magento\Framework\Api\SearchResults;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalSearchResultsInterface;

class ApprovalSearchResults extends SearchResults implements ApprovalSearchResultsInterface
{
}
