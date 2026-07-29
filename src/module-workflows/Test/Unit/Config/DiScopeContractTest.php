<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * Repo-wide guard against a silent, high-blast-radius DI mistake.
 *
 * Area DI config (etc/adminhtml/di.xml, etc/frontend/di.xml, ...) is layered
 * onto the already-merged global config by
 * Magento\Framework\ObjectManager\Config\Config::_mergeConfiguration(), which
 * does:
 *
 *     $this->_arguments[$key] = array_replace($this->_arguments[$key], $curConfig['arguments']);
 *
 * array_replace() is SHALLOW and keyed by argument name. An array argument
 * declared in an area scope therefore does NOT merge item-by-item with the
 * global declaration of the same argument — it replaces the whole thing.
 *
 * Declaring `collections` on
 * Magento\Framework\View\Element\UiComponent\DataProvider\CollectionFactory in
 * etc/adminhtml/di.xml consequently unregistered every core grid data source
 * (customer_listing_data_source, sales_order_grid_data_source, ...) and broke
 * the native Customers and Orders grids with
 * "Not registered handle <name>". Core registers these in global etc/di.xml
 * for exactly this reason (see Magento_Customer and Magento_Sales etc/di.xml).
 *
 * The same trap applies to this suite's own extension-point arrays: a third
 * party registering an entry from its global di.xml would be wiped by an
 * area-scope declaration here.
 */
class DiScopeContractTest extends TestCase
{
    private const COLLECTION_FACTORY =
        'Magento\Framework\View\Element\UiComponent\DataProvider\CollectionFactory';

    private function srcRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    /**
     * Every etc/<area>/di.xml in the monorepo.
     *
     * @return string[]
     */
    private function areaDiFiles(): array
    {
        $files = [];
        foreach (glob($this->srcRoot() . '/module-*/etc/*/di.xml') ?: [] as $path) {
            $files[] = $path;
        }
        return $files;
    }

    /**
     * @return string[] repo-relative paths
     */
    private function relative(array $paths): array
    {
        $root = dirname($this->srcRoot());
        return array_map(
            static fn (string $p): string => str_replace($root . '/', '', $p),
            $paths
        );
    }

    public function testNoAreaScopedDiDeclaresArrayArguments(): void
    {
        $offenders = [];

        foreach ($this->areaDiFiles() as $file) {
            $xml = simplexml_load_file($file);
            if ($xml === false) {
                $this->fail('Unparseable di.xml: ' . $file);
            }

            foreach ($xml->type as $type) {
                foreach ($type->arguments->argument ?? [] as $argument) {
                    if ((string) $argument->attributes('xsi', true)['type'] === 'array') {
                        $offenders[] = sprintf(
                            '%s → <type name="%s"> argument "%s"',
                            $this->relative([$file])[0],
                            (string) $type['name'],
                            (string) $argument['name']
                        );
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Array arguments must be declared in a module's GLOBAL etc/di.xml, never in an "
            . "area-scoped etc/<area>/di.xml: Magento layers area DI onto global config with a "
            . "shallow array_replace(), so an area-scope array argument REPLACES the global one "
            . "wholesale instead of merging into it. Offenders:\n  " . implode("\n  ", $offenders)
        );
    }

    public function testGridDataSourcesAreRegisteredGlobally(): void
    {
        $registered = $this->globallyRegisteredDataSources();
        sort($registered);

        $this->assertSame(
            [
                'mageos_workflow_approvals_listing_data_source',
                'mageos_workflow_executions_listing_data_source',
                'mageos_workflows_listing_data_source',
            ],
            $registered,
            'Every grid data source this suite ships must be registered in a global etc/di.xml.'
        );
    }

    /**
     * Every listing ui_component's dataSource name must have a matching entry in
     * some module's global etc/di.xml collections map — an unregistered name is
     * precisely what CollectionFactory::getReport() rejects with
     * "Not registered handle <name>" when the grid is opened.
     */
    public function testEveryListingDataSourceIsRegistered(): void
    {
        $registered = $this->globallyRegisteredDataSources();

        $components = glob($this->srcRoot() . '/module-*/view/adminhtml/ui_component/*_listing.xml') ?: [];
        $this->assertTrue($components !== [], 'Expected at least one listing ui_component.');

        $unregistered = [];
        foreach ($components as $file) {
            $xml = simplexml_load_file($file);
            if ($xml === false || !isset($xml->dataSource)) {
                continue;
            }
            $name = (string) $xml->dataSource['name'];
            if (!in_array($name, $registered, true)) {
                $unregistered[] = $this->relative([$file])[0] . ' → ' . $name;
            }
        }

        $this->assertSame(
            [],
            $unregistered,
            "Listing data sources with no global CollectionFactory registration; opening these "
            . "grids throws \"Not registered handle <name>\":\n  " . implode("\n  ", $unregistered)
        );
    }

    /**
     * @return string[]
     */
    private function globallyRegisteredDataSources(): array
    {
        $registered = [];

        foreach (glob($this->srcRoot() . '/module-*/etc/di.xml') ?: [] as $file) {
            $xml = simplexml_load_file($file);
            if ($xml === false) {
                continue;
            }
            foreach ($xml->type as $type) {
                if ((string) $type['name'] !== self::COLLECTION_FACTORY) {
                    continue;
                }
                foreach ($type->arguments->argument ?? [] as $argument) {
                    if ((string) $argument['name'] !== 'collections') {
                        continue;
                    }
                    foreach ($argument->item as $item) {
                        $registered[] = (string) $item['name'];
                    }
                }
            }
        }

        return $registered;
    }
}
