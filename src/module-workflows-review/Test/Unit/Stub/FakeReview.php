<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsReview\Test\Unit\Stub;

use Magento\Review\Model\Review;

/**
 * Review stand-in for the review_save_after observer: carries the current
 * and ORIGINAL status ($origStatusId = null models a freshly created
 * review), the review entity-type id (1 = product in stock data), and the
 * reviewed entity's pk. Skips the real Review constructor in both
 * environments; accessors are self-contained.
 */
class FakeReview extends Review
{
    /**
     * @param mixed $id
     * @param mixed $statusId
     * @param mixed $origStatusId null = newly created review
     * @param mixed $entityId review entity-type row id (1 = product), null = unknown
     * @param mixed $entityPkValue reviewed entity id (product id)
     * @param mixed $title
     * @param mixed $nickname
     * @param mixed $storeId
     */
    public function __construct(
        private $id = 0,
        private $statusId = Review::STATUS_PENDING,
        private $origStatusId = null,
        private $entityId = 1,
        private $entityPkValue = 0,
        private $title = '',
        private $nickname = '',
        private $storeId = 0
    ) {
    }

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return mixed
     */
    public function getStatusId()
    {
        return $this->statusId;
    }

    /**
     * @param string|null $key
     * @return mixed
     */
    public function getOrigData($key = null)
    {
        return $key === 'status_id' ? $this->origStatusId : null;
    }

    /**
     * @return mixed
     */
    public function getEntityId()
    {
        return $this->entityId;
    }

    /**
     * @return mixed
     */
    public function getEntityPkValue()
    {
        return $this->entityPkValue;
    }

    /**
     * @return mixed
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * @return mixed
     */
    public function getNickname()
    {
        return $this->nickname;
    }

    /**
     * @return mixed
     */
    public function getStoreId()
    {
        return $this->storeId;
    }
}
