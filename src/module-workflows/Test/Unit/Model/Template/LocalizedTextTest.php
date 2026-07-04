<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Template;

use MageOS\Workflows\Model\Template\LocalizedText;
use PHPUnit\Framework\TestCase;

/**
 * The localized-text resolver: string | {locale: string} map, with a lenient
 * display resolver and a strict presence test (the default-locale rule that
 * rides the compat stage).
 */
class LocalizedTextTest extends TestCase
{
    public function testPlainStringResolvesAsItself(): void
    {
        $this->assertSame('Hello', LocalizedText::resolve('Hello', 'de_DE'));
        $this->assertTrue(LocalizedText::isResolvable('Hello', 'de_DE'));
    }

    public function testExactLocaleWins(): void
    {
        $map = ['en_US' => 'Hello', 'de_DE' => 'Hallo'];
        $this->assertSame('Hallo', LocalizedText::resolve($map, 'de_DE'));
    }

    public function testLanguagePrefixFallback(): void
    {
        $map = ['en_US' => 'Color', 'de_DE' => 'Farbe'];
        $this->assertSame('Color', LocalizedText::resolve($map, 'en_GB'));
        $this->assertTrue(LocalizedText::isResolvable($map, 'en_GB'));
    }

    public function testDefaultLocaleFallbackForDisplay(): void
    {
        $map = ['en_US' => 'Hello'];
        $this->assertSame('Hello', LocalizedText::resolve($map, 'fr_FR'));
    }

    public function testFirstEntryFallbackForDisplayWhenNoDefault(): void
    {
        $map = ['de_DE' => 'Hallo'];
        // Lenient: display never blanks out a non-empty value.
        $this->assertSame('Hallo', LocalizedText::resolve($map, 'fr_FR'));
    }

    public function testStrictPresenceFailsWithoutRequestedOrDefaultLocale(): void
    {
        $map = ['de_DE' => 'Hallo'];
        // Strict: neither fr_FR nor the en_US default is present.
        $this->assertFalse(LocalizedText::isResolvable($map, 'fr_FR'));
    }

    public function testEmptyValuesAreNotResolvable(): void
    {
        $this->assertFalse(LocalizedText::isResolvable('', 'en_US'));
        $this->assertFalse(LocalizedText::isResolvable([], 'en_US'));
        $this->assertSame('', LocalizedText::resolve([], 'en_US'));
    }
}
