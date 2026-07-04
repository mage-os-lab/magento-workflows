<?php
declare(strict_types=1);

namespace Magento\Framework\Stdlib\DateTime;

/**
 * Standalone-runner shim for Magento\Framework\Stdlib\DateTime\TimezoneInterface —
 * only the surface RunScheduledWorkflows touches (per-scope config timezone
 * lookup).
 */
interface TimezoneInterface
{
    /**
     * @param string|null $scopeType
     * @param int|string|null $scopeCode
     */
    public function getConfigTimezone($scopeType = null, $scopeCode = null): string;
}
