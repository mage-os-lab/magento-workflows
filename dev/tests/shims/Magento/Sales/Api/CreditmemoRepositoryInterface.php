<?php
declare(strict_types=1);

namespace Magento\Sales\Api;

/**
 * Standalone-runner shim for Magento\Sales\Api\CreditmemoRepositoryInterface.
 * Mirrors the FULL real interface (5 methods, real UNTYPED signatures — note
 * there is no deleteById here, unlike OrderRepositoryInterface) so a test
 * double must satisfy the same contract it faces under real Magento. Only
 * getList() is exercised: order.create_creditmemo scans an order's existing
 * memos for its own dedupe marker before refunding again.
 */
interface CreditmemoRepositoryInterface
{
    public function getList($searchCriteria);

    public function get($id);

    public function create();

    public function delete($entity);

    public function save($entity);
}
