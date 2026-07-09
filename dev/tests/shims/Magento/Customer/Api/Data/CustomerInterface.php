<?php
declare(strict_types=1);

namespace Magento\Customer\Api\Data;

/**
 * Standalone-runner shim for Magento\Customer\Api\Data\CustomerInterface —
 * only the surface the erasure-scrub plugin tests exercise. Signatures match
 * the real interface so test doubles stay drop-in compatible.
 */
interface CustomerInterface
{
    /**
     * @return int|null
     */
    public function getId();

    /**
     * @return string
     */
    public function getEmail();
}
