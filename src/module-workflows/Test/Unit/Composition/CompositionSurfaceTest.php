<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Composition;

use MageOS\Workflows\Test\Unit\PackageLocator;
use PHPUnit\Framework\TestCase;

/**
 * Golden composition test (domain-packs S0). Snapshots the whole monorepo's
 * merged registration surface — action codes, condition combines, relation
 * codes, hydrator keys, aggregate providers, option-source codes, trigger
 * events, async events and observers — and asserts byte-equality against a
 * committed fixture.
 *
 * The surface is the contract that the S1–S5 vertical split must preserve: a
 * pure relocation of classes and di/events/trigger blocks into new domain
 * packs is correct iff this merged surface is identical before and after. A
 * failure here means the set of registered codes/keys changed — either an
 * accidental behavior change, or a fixture that needs an intentional,
 * reviewed update (regenerate with CompositionSurfaceExtractor).
 *
 * Pure XML parsing — no Magento install required, runs under the standalone
 * runner.
 */
final class CompositionSurfaceTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/composition-surface.json';

    /**
     * @return array<string, array<string, string>>
     */
    private static function expected(): array
    {
        $decoded = json_decode((string) file_get_contents(self::FIXTURE), true);
        return is_array($decoded) ? $decoded : [];
    }

    public function testFixtureExistsAndIsPopulated(): void
    {
        $this->assertTrue(is_file(self::FIXTURE), 'Composition fixture is missing: ' . self::FIXTURE);
        $expected = self::expected();
        // Guard against a silently-empty extractor+fixture pair passing trivially.
        foreach (['actions', 'combines', 'relations', 'hydrators', 'aggregate_providers',
                     'option_sources', 'trigger_events', 'async_events', 'observers'] as $section) {
            $this->assertArrayHasKey($section, $expected, "Fixture missing section: {$section}");
            $this->assertTrue(
                is_array($expected[$section]) && $expected[$section] !== [],
                "Fixture section is empty: {$section}"
            );
        }
    }

    public function testMergedSurfaceMatchesFixture(): void
    {
        // PackageLocator resolves the sibling packages in BOTH layouts (monorepo
        // src/module-*, install vendor/mage-os/workflows*) and throws rather than
        // returning an empty set — a scan that found nothing would otherwise
        // report the entire fixture as "removed".
        $actual = CompositionSurfaceExtractor::extract(PackageLocator::packageDirs());
        $expected = self::expected();

        if ($actual === $expected) {
            $this->assertTrue(true);
            return;
        }

        $this->fail(
            "Merged composition surface changed — the S1–S5 moves must be pure relocations.\n"
            . "If this change is intentional, regenerate the fixture from CompositionSurfaceExtractor.\n\n"
            . self::diff($expected, $actual)
        );
    }

    /**
     * Readable added/removed/changed diff across every section.
     *
     * @param array<string, array<string, string>> $expected
     * @param array<string, array<string, string>> $actual
     */
    private static function diff(array $expected, array $actual): string
    {
        $lines = [];
        $sections = array_keys($expected + $actual);
        sort($sections);

        foreach ($sections as $section) {
            $expectedEntries = $expected[$section] ?? [];
            $actualEntries = $actual[$section] ?? [];
            if ($expectedEntries === $actualEntries) {
                continue;
            }

            $lines[] = "[{$section}]";
            $keys = array_keys($expectedEntries + $actualEntries);
            sort($keys);
            foreach ($keys as $key) {
                $inExpected = array_key_exists($key, $expectedEntries);
                $inActual = array_key_exists($key, $actualEntries);
                if ($inExpected && !$inActual) {
                    $lines[] = "  - removed: {$key} => {$expectedEntries[$key]}";
                } elseif (!$inExpected && $inActual) {
                    $lines[] = "  + added:   {$key} => {$actualEntries[$key]}";
                } elseif ($expectedEntries[$key] !== $actualEntries[$key]) {
                    $lines[] = "  ~ changed: {$key}";
                    $lines[] = "      expected: {$expectedEntries[$key]}";
                    $lines[] = "      actual:   {$actualEntries[$key]}";
                }
            }
        }

        return implode("\n", $lines);
    }
}
