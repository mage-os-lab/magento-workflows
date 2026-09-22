<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Math;

/**
 * Standalone-runner shim for Magento\Framework\Math\Random (the real class
 * is dependency-free and constructible with `new Random()`, which is why
 * tests can use it directly in both environments).
 */
class Random
{
    public const CHARS_LOWERS = 'abcdefghijklmnopqrstuvwxyz';
    public const CHARS_UPPERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    public const CHARS_DIGITS = '0123456789';

    /**
     * @param int $length
     * @param string|null $chars
     * @return string
     */
    public function getRandomString($length, $chars = null)
    {
        $pool = $chars ?? self::CHARS_LOWERS . self::CHARS_UPPERS . self::CHARS_DIGITS;
        $result = '';
        $max = strlen($pool) - 1;
        for ($i = 0; $i < (int) $length; $i++) {
            $result .= $pool[random_int(0, $max)];
        }
        return $result;
    }

    /**
     * @param string $prefix
     * @return string
     */
    public function getUniqueHash($prefix = '')
    {
        return $prefix . $this->getRandomString(32);
    }
}
