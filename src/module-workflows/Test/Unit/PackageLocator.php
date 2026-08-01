<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit;

/**
 * Layout-agnostic discovery of this suite's package roots, shared by every
 * repo-wide contract test that scans sibling packages for config/view files.
 *
 * The same Test/Unit trees run in TWO different directory layouts:
 *
 *   monorepo (php dev/tests/standalone-runner.php)
 *     <repo>/src/module-workflows/Test/Unit/…          <- the test
 *     <repo>/src/module-workflows-admin-ui/etc/di.xml  <- a sibling package
 *
 *   real Magento install (vendor/bin/phpunit, check-extension.yml)
 *     <magento>/vendor/mage-os/workflows/Test/Unit/…            <- the test
 *     <magento>/vendor/mage-os/workflows-admin-ui/etc/di.xml    <- a sibling
 *
 * A scan hard-coded to `<root>/src/module-*` therefore finds NOTHING under
 * vendor/, and every repo-wide contract test degenerates into a vacuous pass
 * (or a "everything was removed" diff). Both layouts do share a structure,
 * though: each package root holds registration.php, and all of this suite's
 * packages are siblings inside ONE container directory (`src/` or
 * `vendor/mage-os/`). So discovery walks up from this file to its own package
 * root, takes that root's parent as the container, and keeps every sibling
 * whose registration.php registers a `MageOS_Workflows…` module. Under a real
 * install the registrations are also already loaded (composer autoload
 * "files"), so Magento's own ComponentRegistrar is merged in as a second,
 * authoritative source.
 *
 * Discovery finding ZERO packages is never tolerated: packageRoots() throws.
 * These are contract tests — an empty scan must fail loudly, not pass quietly.
 *
 * Not named *Test.php, so both runners autoload but never execute it.
 */
final class PackageLocator
{
    /**
     * Every package in this suite registers a module under this prefix. It is
     * what separates our packages from the other mage-os/* packages that share
     * vendor/mage-os/ in an install (mageos-async-events and friends).
     */
    public const MODULE_PREFIX = 'MageOS_Workflows';

    private const REGISTRATION_PATTERN =
        '/ComponentRegistrar::MODULE\s*,\s*[\'"]([A-Za-z0-9_]+)[\'"]/';

    private const REGISTRAR_CLASS = 'Magento\\Framework\\Component\\ComponentRegistrar';

    /** @var array<string, string>|null module name => absolute package root */
    private static ?array $roots = null;

    /**
     * Module name (MageOS_WorkflowsAdminUi) => absolute package root, sorted by
     * module name. Never empty — an empty discovery throws.
     *
     * @return array<string, string>
     */
    public static function packageRoots(): array
    {
        if (self::$roots === null) {
            self::$roots = self::discover();
        }

        if (self::$roots === []) {
            throw new \RuntimeException(
                'PackageLocator discovered no ' . self::MODULE_PREFIX . '* packages under '
                . self::containerDir() . '. Every repo-wide contract test scans the sibling '
                . 'packages, so an empty discovery would make them all pass vacuously — the '
                . 'scan is broken, not the repo.'
            );
        }

        return self::$roots;
    }

    /**
     * @return string[] absolute package roots, sorted by module name
     */
    public static function packageDirs(): array
    {
        return array_values(self::packageRoots());
    }

    /**
     * The directory the packages live side by side in: `<repo>/src` in the
     * monorepo, `<magento>/vendor/mage-os` in an install.
     */
    public static function containerDir(): string
    {
        return dirname(self::ownPackageRoot());
    }

    /**
     * A readable, layout-appropriate label for a discovered path — relative to
     * the container's parent, i.e. "src/module-workflows/etc/di.xml" in the
     * monorepo and "mage-os/workflows/etc/di.xml" in an install. Failure
     * messages only; nothing asserts on it.
     */
    public static function relative(string $path): string
    {
        $base = dirname(self::containerDir()) . '/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /**
     * glob() of a package-relative pattern across every discovered package,
     * e.g. globInPackages('view/adminhtml/ui_component/*_listing.xml').
     *
     * @return string[] absolute paths, sorted
     */
    public static function globInPackages(string $pattern, int $flags = 0): array
    {
        $paths = [];
        foreach (self::packageRoots() as $root) {
            foreach (glob($root . '/' . ltrim($pattern, '/'), $flags) ?: [] as $path) {
                $paths[] = $path;
            }
        }
        sort($paths);

        return $paths;
    }

    /**
     * Every file under a package-relative directory (itself a glob pattern —
     * "etc", or "view/" + a wildcard area + "/templates"), recursively, whose
     * name ends with $suffix.
     *
     * @return string[] absolute paths, sorted
     */
    public static function filesUnder(string $dirPattern, string $suffix): array
    {
        $files = [];
        foreach (self::globInPackages($dirPattern, GLOB_ONLYDIR) as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileInfo) {
                /** @var \SplFileInfo $fileInfo */
                if ($fileInfo->isFile() && str_ends_with($fileInfo->getFilename(), $suffix)) {
                    $files[] = $fileInfo->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return array<string, string>
     */
    private static function discover(): array
    {
        $roots = [];

        // Primary, layout-agnostic source: the sibling directories of our own
        // package root that register a module of this suite.
        foreach (glob(self::containerDir() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = self::moduleNameOf($dir);
            if ($name !== null) {
                $roots[$name] = $dir;
            }
        }

        // Secondary source, only ever additive: on a real install every
        // registration.php has already run (composer autoload "files"), so
        // Magento's own registry knows where each module really lives — even
        // if it was installed somewhere other than beside us.
        foreach (self::registrarPaths() as $name => $path) {
            if (!isset($roots[$name]) && is_dir($path)) {
                $roots[$name] = rtrim($path, '/');
            }
        }

        ksort($roots);

        return $roots;
    }

    /**
     * The MageOS_Workflows* module a directory registers, or null if it
     * registers nothing (or belongs to another suite).
     */
    private static function moduleNameOf(string $dir): ?string
    {
        $registration = $dir . '/registration.php';
        if (!is_file($registration)) {
            return null;
        }

        $source = (string) file_get_contents($registration);
        if (preg_match(self::REGISTRATION_PATTERN, $source, $m) !== 1) {
            return null;
        }

        return str_starts_with($m[1], self::MODULE_PREFIX) ? $m[1] : null;
    }

    /**
     * @return array<string, string> module name => path, from Magento's
     *         ComponentRegistrar when it exists (real install only)
     */
    private static function registrarPaths(): array
    {
        if (!class_exists(self::REGISTRAR_CLASS) || !method_exists(self::REGISTRAR_CLASS, 'getPaths')) {
            return [];
        }

        $type = defined(self::REGISTRAR_CLASS . '::MODULE')
            ? (string) constant(self::REGISTRAR_CLASS . '::MODULE')
            : 'module';

        $paths = [];
        foreach ((array) call_user_func([self::REGISTRAR_CLASS, 'getPaths'], $type) as $name => $path) {
            if (is_string($name) && is_string($path) && str_starts_with($name, self::MODULE_PREFIX)) {
                $paths[$name] = $path;
            }
        }

        return $paths;
    }

    /**
     * This file's own package root — the nearest ancestor directory holding
     * both registration.php and composer.json. True in every layout: the
     * monorepo source tree, a composer path-repo mirror under vendor/, and an
     * app/code checkout.
     */
    private static function ownPackageRoot(): string
    {
        $dir = __DIR__;

        while (true) {
            if (is_file($dir . '/registration.php') && is_file($dir . '/composer.json')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                throw new \RuntimeException(
                    'PackageLocator could not find its own package root (a directory holding '
                    . 'registration.php and composer.json) above ' . __DIR__ . '.'
                );
            }
            $dir = $parent;
        }
    }
}
