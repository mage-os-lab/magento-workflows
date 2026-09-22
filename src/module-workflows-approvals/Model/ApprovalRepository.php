<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\WorkflowsApprovals\Api\ApprovalRepositoryInterface;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalSearchResultsInterface;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalSearchResultsInterfaceFactory;
use MageOS\WorkflowsApprovals\Model\ResourceModel\Approval as ApprovalResource;
use MageOS\WorkflowsApprovals\Model\ResourceModel\Approval\CollectionFactory;

/**
 * Read surface for approval tasks (§5). Tasks are loaded and listed by their
 * public handles only — getByUuid never accepts the int PK.
 */
class ApprovalRepository implements ApprovalRepositoryInterface
{
    public function __construct(
        private readonly ApprovalFactory $approvalFactory,
        private readonly ApprovalResource $approvalResource,
        private readonly CollectionFactory $collectionFactory,
        private readonly CollectionProcessorInterface $collectionProcessor,
        private readonly ApprovalSearchResultsInterfaceFactory $searchResultsFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getByUuid(string $uuid): ApprovalInterface
    {
        /** @var Approval $approval */
        $approval = $this->approvalFactory->create();
        $this->approvalResource->load($approval, $uuid, ApprovalInterface::UUID);
        if (!$approval->getData(ApprovalInterface::APPROVAL_ID)) {
            throw NoSuchEntityException::singleField('uuid', $uuid);
        }

        return $approval;
    }

    /**
     * @inheritDoc
     */
    public function getList(SearchCriteriaInterface $searchCriteria): ApprovalSearchResultsInterface
    {
        $collection = $this->collectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        /** @var ApprovalSearchResultsInterface $searchResults */
        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }
}
