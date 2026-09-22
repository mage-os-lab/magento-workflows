<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Template;

/**
 * Resolves the template envelope's localized text fields (title/description/
 * parameter label): either a plain string or a {locale: string} map
 * (workflow-template.schema.json #/$defs/localizedText).
 *
 * "Default locale present" is deliberately RUNTIME validation — JSON Schema
 * cannot know the install's locale — so this class carries both a lenient
 * resolver (for display, always yields something when the value is non-empty)
 * and a strict presence test (for the typed compatibility reason):
 * isResolvable() requires the requested locale, its language prefix, or the
 * canonical DEFAULT_LOCALE to be present in a map.
 */
class LocalizedText
{
    /**
     * The canonical fallback a localized map must carry when the install locale
     * is absent — Magento's own default. A template author who provides only
     * non-default locales is flagged incompatible (a compat reason), not
     * silently rendered in whatever locale happened to sort first.
     */
    public const DEFAULT_LOCALE = 'en_US';

    /**
     * Lenient resolution for display: exact locale → language prefix →
     * DEFAULT_LOCALE → first entry. Returns '' only when the value is empty.
     */
    public static function resolve(mixed $value, string $locale = self::DEFAULT_LOCALE): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value) || $value === []) {
            return '';
        }
        $picked = self::pick($value, $locale);
        if ($picked !== null) {
            return $picked;
        }
        $first = reset($value);
        return is_string($first) ? $first : '';
    }

    /**
     * Strict presence test for the compat stage: a string always resolves; a
     * map must carry the requested locale, its language prefix, or the
     * canonical default.
     */
    public static function isResolvable(mixed $value, string $locale = self::DEFAULT_LOCALE): bool
    {
        if (is_string($value)) {
            return $value !== '';
        }
        if (!is_array($value) || $value === []) {
            return false;
        }
        return self::pick($value, $locale) !== null;
    }

    /**
     * Exact locale, then its two-letter language prefix, then DEFAULT_LOCALE.
     */
    private static function pick(array $map, string $locale): ?string
    {
        if (isset($map[$locale]) && is_string($map[$locale])) {
            return $map[$locale];
        }
        $language = substr($locale, 0, 2);
        foreach ($map as $key => $text) {
            if (is_string($key) && is_string($text) && substr($key, 0, 2) === $language) {
                return $text;
            }
        }
        if (isset($map[self::DEFAULT_LOCALE]) && is_string($map[self::DEFAULT_LOCALE])) {
            return $map[self::DEFAULT_LOCALE];
        }
        return null;
    }
}
