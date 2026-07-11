<?php
declare(strict_types=1);

namespace Magento\Review\Model;

use Magento\Framework\DataObject;

/**
 * Standalone-runner shim for Magento\Review\Model\Review: status constants
 * plus a data-bag body. Test doubles extend it and override the accessors
 * they exercise (the real model resolves most of them magically through
 * DataObject, which the shim's DataObject parent mirrors).
 */
class Review extends DataObject
{
    public const STATUS_APPROVED = 1;
    public const STATUS_PENDING = 2;
    public const STATUS_NOT_APPROVED = 3;

    public const ENTITY_PRODUCT_CODE = 'product';
    public const ENTITY_CUSTOMER_CODE = 'customer';
    public const ENTITY_CATEGORY_CODE = 'category';

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->getData('review_id');
    }

    /**
     * @param string|null $key
     * @return mixed
     */
    public function getOrigData($key = null)
    {
        return null;
    }
}
