<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model;

use Magento\Framework\Api\SearchResults;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalSearchResultsInterface;

class ApprovalSearchResults extends SearchResults implements ApprovalSearchResultsInterface
{
}
