<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\App;

/**
 * Standalone-runner shim for Magento\Framework\App\ProductMetadataInterface.
 */
interface ProductMetadataInterface
{
    /**
     * @return string
     */
    public function getEdition();

    /**
     * @return string
     */
    public function getVersion();

    /**
     * @return string
     */
    public function getName();
}
