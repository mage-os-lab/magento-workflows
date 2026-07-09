<?php
declare(strict_types=1);

namespace Magento\Review\Model\ResourceModel;

use Magento\Review\Model\Review as ReviewModel;

/**
 * Standalone-runner shim for Magento\Review\Model\ResourceModel\Review — the
 * review resource model. Only the load()/save() surface the review.set_status
 * action uses is declared; tests pass a fake resource (a subclass) that records
 * calls and hands back a prepared fake review. Real Magento installs load the
 * concrete resource model instead.
 */
class Review
{
    public function load(ReviewModel $object, $value, $field = null): self
    {
        return $this;
    }

    public function save(ReviewModel $object): self
    {
        return $this;
    }
}
