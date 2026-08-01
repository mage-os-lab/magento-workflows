<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the discovery helper every repo-wide contract test now depends on.
 *
 * PackageLocator is load-bearing: CompositionSurfaceTest, DiScopeContractTest,
 * ListingIndexFieldContractTest, HtmlContentTemplateContractTest and
 * ViewAssetPathContractTest all scan the packages it hands back. If it ever
 * silently narrows — a layout it does not recognise, a registration.php format
 * it stops parsing — those tests would keep passing while checking a fraction
 * of the suite (or nothing at all). That is the failure mode this pins.
 *
 * It must hold in BOTH layouts the Test/Unit trees run in: the monorepo
 * (<repo>/src/module-*) and a real Magento install (<magento>/vendor/mage-os/
 * workflows*), which is exactly what PackageLocator abstracts.
 */
class PackageLocatorTest extends TestCase
{
    public function testDiscoveryFindsTheWholeSuiteNotJustItsOwnPackage(): void
    {
        $roots = PackageLocator::packageRoots();

        // The engine core plus the packs the contract tests actually read
        // (listings and templates live in the admin-ui/canvas packs, di pools in
        // the domain packs) — finding only the package this file ships in is the
        // regression that would gut every repo-wide scan.
        foreach (['MageOS_Workflows', 'MageOS_WorkflowsAdminUi', 'MageOS_WorkflowsCanvas'] as $module) {
            $this->assertArrayHasKey($module, $roots, 'Package discovery lost ' . $module);
        }

        $this->assertTrue(
            count($roots) >= 10,
            'Expected the whole suite to be discovered, found: ' . implode(', ', array_keys($roots))
        );
    }

    public function testEveryDiscoveredRootIsAModuleDirectory(): void
    {
        foreach (PackageLocator::packageRoots() as $module => $root) {
            $this->assertTrue(is_dir($root), "{$module}: not a directory ({$root})");
            $this->assertTrue(
                is_file($root . '/registration.php'),
                "{$module}: no registration.php in {$root}"
            );
            $this->assertTrue(
                is_file($root . '/composer.json'),
                "{$module}: no composer.json in {$root}"
            );
        }
    }

    public function testPackagesShareOneContainerDirectory(): void
    {
        $container = PackageLocator::containerDir();
        $this->assertTrue(is_dir($container));

        foreach (PackageLocator::packageDirs() as $root) {
            $this->assertSame($container, dirname($root));
        }
    }

    public function testGlobInPackagesResolvesPackageRelativePatterns(): void
    {
        $registrations = PackageLocator::globInPackages('registration.php');

        $this->assertSame(count(PackageLocator::packageRoots()), count($registrations));
        foreach ($registrations as $file) {
            $this->assertTrue(is_file($file));
        }
    }

    public function testFilesUnderWalksMatchingDirectoriesRecursively(): void
    {
        $templates = PackageLocator::filesUnder('view/*/templates', '.phtml');

        $this->assertTrue($templates !== [], 'Expected the suite to ship at least one .phtml template.');
        foreach ($templates as $template) {
            $this->assertTrue(str_ends_with($template, '.phtml'));
            $this->assertTrue(is_file($template));
        }
    }

    public function testRelativeStripsTheContainersParent(): void
    {
        $root = PackageLocator::packageRoots()['MageOS_Workflows'];
        $relative = PackageLocator::relative($root . '/etc/di.xml');

        // "src/module-workflows/etc/di.xml" here, "mage-os/workflows/etc/di.xml"
        // in an install — short and readable in both, absolute in neither.
        $this->assertTrue(str_ends_with($relative, '/etc/di.xml'), 'Unexpected label: ' . $relative);
        $this->assertFalse(str_starts_with($relative, '/'), 'Expected a relative label, got: ' . $relative);
        $this->assertSame(
            basename(PackageLocator::containerDir()) . '/' . basename($root) . '/etc/di.xml',
            $relative
        );
    }
}
