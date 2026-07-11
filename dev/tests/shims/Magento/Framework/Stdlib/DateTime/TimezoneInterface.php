<?php
declare(strict_types=1);

namespace Magento\Framework\Stdlib\DateTime;

/**
 * Standalone-runner shim for Magento\Framework\Stdlib\DateTime\TimezoneInterface.
 *
 * Mirrors the FULL real interface surface (14 methods) so that a test double
 * implementing this interface must satisfy the same contract it would under
 * real Magento — a partial implementation is a compile-time fatal in both
 * environments, not just CI. Production code under the shim only touches
 * getConfigTimezone(); the rest are here for fidelity.
 */
interface TimezoneInterface
{
    /**
     * @param string|null $scopeType
     * @param int|string|null $scopeCode
     */
    public function getConfigTimezone($scopeType = null, $scopeCode = null): string;

    public function getDefaultTimezonePath();

    public function getDefaultTimezone();

    public function getDateFormat($type = \IntlDateFormatter::SHORT);

    public function getDateFormatWithLongYear();

    public function getTimeFormat($type = null);

    public function getDateTimeFormat($type);

    public function date($date = null, $locale = null, $useTimezone = true, $includeTime = true);

    public function scopeDate($scope = null, $date = null, $includeTime = false);

    public function scopeTimeStamp($scope = null);

    public function formatDate($date = null, $format = \IntlDateFormatter::SHORT, $showTime = false);

    public function isScopeDateInInterval($scope, $dateFrom = null, $dateTo = null);

    public function formatDateTime(
        $date,
        $dateType = \IntlDateFormatter::SHORT,
        $timeType = \IntlDateFormatter::SHORT,
        $locale = null,
        $timezone = null,
        $pattern = null
    );

    public function convertConfigTimeToUtc($date, $format = 'Y-m-d H:i:s');
}
