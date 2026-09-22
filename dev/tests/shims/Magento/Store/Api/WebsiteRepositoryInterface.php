<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Store\Api;

/**
 * Standalone-runner shim for Magento\Store\Api\WebsiteRepositoryInterface:
 * the minimal surface the website condition/action/option-source exercise
 * (getById for validation, getList for enumeration).
 */
interface WebsiteRepositoryInterface
{
    public function get($code);

    public function getById($id);

    public function getList();

    public function getDefault();

    public function clean();
}
