<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
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
    // Params UNTYPED to mirror the real parent AbstractDb::load()/save(), which
    // type the model as AbstractModel — a subclass double narrowing this to the
    // concrete Review would be a contravariance fatal under real Magento, so the
    // shim must not invite (or mask) that narrowing.
    public function load($object, $value, $field = null): self
    {
        return $this;
    }

    public function save($object): self
    {
        return $this;
    }
}
