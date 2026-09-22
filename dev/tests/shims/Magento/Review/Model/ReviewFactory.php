<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Review\Model;

/**
 * Standalone-runner shim for Magento\Review\Model\ReviewFactory. Tests pass
 * their own fake factory (a subclass whose create() returns a prepared fake
 * Review), so this base only needs to exist for type-hinting. Real Magento
 * installs load the generated factory instead.
 */
class ReviewFactory
{
    public function create(array $data = []): Review
    {
        return new Review();
    }
}
