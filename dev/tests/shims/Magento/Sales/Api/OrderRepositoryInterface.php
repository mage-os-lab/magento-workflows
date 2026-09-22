<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Sales\Api;

/**
 * Standalone-runner shim for Magento\Sales\Api\OrderRepositoryInterface.
 * Mirrors the FULL real interface (5 methods) with the real UNTYPED
 * signatures so a test double must satisfy the same contract it faces under
 * real Magento — in particular get($id) is UNTYPED (a typed int param would
 * narrow it, a contravariance violation the real lane rejects).
 */
interface OrderRepositoryInterface
{
    public function get($id);

    public function getList($searchCriteria);

    public function delete($entity);

    public function save($entity);

    public function deleteById($id);
}
