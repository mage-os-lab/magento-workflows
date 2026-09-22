<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Config;

use MageOS\Workflows\Test\Unit\PackageLocator;
use PHPUnit\Framework\TestCase;

/**
 * Every admin listing must declare storageConfig.indexField, matching its data
 * provider's primaryFieldName.
 *
 * Magento_Ui/js/grid/data-storage caches fetched records in a map keyed by
 * `record[indexField]`, and its default indexField is 'entity_id'. A listing over
 * a table whose primary key is not literally `entity_id` — every table in this
 * suite — therefore keys every record to `undefined` unless the field is declared.
 * They collapse into a single slot and the grid paints the LAST record of the
 * response into every row.
 *
 * The failure is nasty precisely because the server side looks perfectly healthy:
 * totalRecords is right, the row count is right, the JSON payload contains fully
 * distinct records, and there is no console error. Only the rendered rows are
 * wrong. indexField also governs which ids selections and massactions submit, so
 * a wrong value silently mis-targets Enable/Disable/Delete.
 */
class ListingIndexFieldContractTest extends TestCase
{
    /**
     * Discovery goes through PackageLocator so the listings are found in BOTH
     * layouts — <repo>/src/module-* and, in CI's real Magento install,
     * <magento>/vendor/mage-os/workflows* — and an empty package scan throws
     * rather than passing this contract vacuously.
     *
     * @return string[]
     */
    private function listingFiles(): array
    {
        return PackageLocator::globInPackages('view/adminhtml/ui_component/*_listing.xml');
    }

    private function relative(string $path): string
    {
        return PackageLocator::relative($path);
    }

    /**
     * Accepts both declaration styles: the argument/config style these files use
     * (<item name="storageConfig"><item name="indexField">) and the modern
     * settings style (<storageConfig><param name="indexField">).
     */
    private function indexField(\SimpleXMLElement $xml): ?string
    {
        $hits = $xml->xpath('//item[@name="storageConfig"]/item[@name="indexField"]') ?: [];
        if ($hits !== []) {
            return trim((string) $hits[0]);
        }

        $hits = $xml->xpath('//storageConfig/param[@name="indexField"]') ?: [];
        if ($hits !== []) {
            return trim((string) $hits[0]);
        }

        return null;
    }

    private function primaryFieldName(\SimpleXMLElement $xml): ?string
    {
        $hits = $xml->xpath('//argument[@name="primaryFieldName"]') ?: [];
        if ($hits !== []) {
            return trim((string) $hits[0]);
        }

        $hits = $xml->xpath('//primaryFieldName') ?: [];
        if ($hits !== []) {
            return trim((string) $hits[0]);
        }

        return null;
    }

    public function testEveryListingDeclaresAnIndexField(): void
    {
        $files = $this->listingFiles();
        $this->assertTrue($files !== [], 'Expected at least one listing ui_component.');

        $missing = [];
        foreach ($files as $file) {
            $xml = simplexml_load_file($file);
            if ($xml === false) {
                $this->fail('Unparseable listing: ' . $file);
            }
            if ($this->indexField($xml) === null) {
                $missing[] = $this->relative($file);
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Listings with no storageConfig.indexField. Magento_Ui/js/grid/data-storage will "
            . "fall back to 'entity_id', collapse every record onto one cache key, and paint "
            . "the last record of the response into every row:\n  " . implode("\n  ", $missing)
        );
    }

    public function testIndexFieldMatchesThePrimaryFieldName(): void
    {
        $mismatched = [];

        foreach ($this->listingFiles() as $file) {
            $xml = simplexml_load_file($file);
            if ($xml === false) {
                continue;
            }

            $indexField = $this->indexField($xml);
            $primary = $this->primaryFieldName($xml);

            if ($indexField === null || $primary === null) {
                continue;
            }

            if ($indexField !== $primary) {
                $mismatched[] = sprintf(
                    '%s → indexField "%s" but primaryFieldName "%s"',
                    $this->relative($file),
                    $indexField,
                    $primary
                );
            }
        }

        $this->assertSame(
            [],
            $mismatched,
            "storageConfig.indexField must match the data provider's primaryFieldName — it is "
            . "the row identity the grid caches, selects and massactions by:\n  "
            . implode("\n  ", $mismatched)
        );
    }

    /**
     * Guards the specific value, so a rename of the primary key cannot quietly
     * satisfy the match test above with a field the rows do not carry.
     */
    public function testIndexFieldIsNeverTheEntityIdDefault(): void
    {
        $offenders = [];

        foreach ($this->listingFiles() as $file) {
            $xml = simplexml_load_file($file);
            if ($xml === false) {
                continue;
            }
            if ($this->indexField($xml) === 'entity_id') {
                $offenders[] = $this->relative($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'No table in this suite has an entity_id column; declaring it as indexField is '
            . "the same bug as omitting it:\n  " . implode("\n  ", $offenders)
        );
    }
}
