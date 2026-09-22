<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCanvas\Test\Unit\View;

use MageOS\Workflows\Test\Unit\PackageLocator;
use PHPUnit\Framework\TestCase;

/**
 * Repo-wide guard against a silently unreachable view asset (issue #26).
 *
 * A module-root `web/` directory is NOT a static-file location in Magento.
 * Magento\Framework\View\Asset\Repository / the fallback rule set only ever
 * look for module view files under `view/<area>/web/` (with `view/base/web/`
 * as the cross-area fallback). Shipping a built bundle at
 * `<module>/web/js/dist/canvas.js` therefore produces:
 *
 *   - `getViewFileUrl('Mod::js/dist/canvas.js')` resolving to a 404 URL — the
 *     page renders its mount point with no JS and no CSS, silently;
 *   - a `.css` request falling through to the LESS pre-processor, which then
 *     tries to compile a non-existent `canvas.less`;
 *   - `setup:static-content:deploy` failing / emitting nothing in production
 *     mode, where there is no on-demand fallback to paper over it.
 *
 * None of that shows up in a unit test of the block, and there is no Magento
 * install in this suite, so the contract is asserted structurally instead:
 * every `getViewFileUrl('Module::path')` argument in any template in the
 * monorepo must correspond to a real file under that module's
 * `view/<area>/web/`.
 */
class ViewAssetPathContractTest extends TestCase
{
    /**
     * `getViewFileUrl('MageOS_WorkflowsCanvas::js/dist/canvas.js')` and friends.
     * Both quote styles; the module prefix is required (a bare relative path
     * resolves against the current theme, not a module, and is out of scope).
     */
    private const VIEW_FILE_URL_PATTERN =
        '/getViewFileUrl\(\s*[\'"]([A-Za-z0-9_]+)::([^\'"]+)[\'"]/';

    /**
     * @return string container-relative path
     */
    private function relative(string $path): string
    {
        return PackageLocator::relative($path);
    }

    /**
     * Module name (MageOS_WorkflowsCanvas) => absolute module directory, read
     * from each package's registration.php — the same source of truth Magento
     * itself uses.
     *
     * PackageLocator does the discovery, so the sibling packages resolve in
     * BOTH layouts — <repo>/src/module-* and, in CI's real Magento install,
     * <magento>/vendor/mage-os/workflows* — and it throws on an empty scan
     * rather than letting this contract pass with nothing checked.
     *
     * @return array<string, string>
     */
    private function moduleDirectories(): array
    {
        return PackageLocator::packageRoots();
    }

    /**
     * Every .phtml template this suite ships.
     *
     * @return string[]
     */
    private function templateFiles(): array
    {
        return PackageLocator::filesUnder('view/*/templates', '.phtml');
    }

    /**
     * The locations Magento's fallback actually searches for a module view
     * file, in order. A theme override (app/design/**) may also supply one, but
     * a module must ship its own copy — a theme is not a dependency.
     *
     * @return string[] absolute candidate file paths
     */
    private function candidatePaths(string $moduleDir, string $path): array
    {
        $candidates = [];
        foreach (glob($moduleDir . '/view/*/web', GLOB_ONLYDIR) ?: [] as $webDir) {
            $candidates[] = $webDir . '/' . $path;
            // Magento's CSS pre-processor accepts a .less source for a .css
            // request; treat that as a legitimate resolution too.
            if (str_ends_with($path, '.css')) {
                $candidates[] = $webDir . '/' . substr($path, 0, -4) . '.less';
            }
        }
        return $candidates;
    }

    public function testEveryGetViewFileUrlArgumentResolvesToAShippedFile(): void
    {
        $modules = $this->moduleDirectories();
        $this->assertArrayHasKey(
            'MageOS_WorkflowsCanvas',
            $modules,
            'registration.php parsing regressed — the guard below would pass vacuously.'
        );

        $checked = 0;
        $unresolved = [];

        foreach ($this->templateFiles() as $template) {
            $source = (string) file_get_contents($template);
            if (preg_match_all(self::VIEW_FILE_URL_PATTERN, $source, $matches, PREG_SET_ORDER) === 0) {
                continue;
            }

            foreach ($matches as [$_, $moduleName, $path]) {
                $checked++;

                if (!isset($modules[$moduleName])) {
                    // A reference to a module outside this monorepo (a core
                    // Magento_* asset, say) cannot be checked structurally.
                    continue;
                }

                $resolved = false;
                foreach ($this->candidatePaths($modules[$moduleName], $path) as $candidate) {
                    if (is_file($candidate)) {
                        $resolved = true;
                        break;
                    }
                }

                if (!$resolved) {
                    $unresolved[] = sprintf(
                        '%s → %s::%s (expected a file at %s/view/<area>/web/%s)',
                        $this->relative($template),
                        $moduleName,
                        $path,
                        $this->relative($modules[$moduleName]),
                        $path
                    );
                }
            }
        }

        $this->assertTrue(
            $checked > 0,
            'Expected at least one getViewFileUrl() call in the monorepo templates; '
            . 'finding none means the scan (or the pattern) broke, not that the repo is clean.'
        );

        $this->assertSame(
            [],
            $unresolved,
            "getViewFileUrl() arguments that resolve to nothing. Magento only serves module view "
            . "files from view/<area>/web/ (or view/base/web/); anything else 404s at runtime and "
            . "is skipped by setup:static-content:deploy:\n  " . implode("\n  ", $unresolved)
        );
    }

    /**
     * The root cause of issue #26, asserted directly: a module-root web/
     * directory looks plausible but is never consulted by the view fallback, so
     * anything placed there is dead weight that ships and never loads.
     */
    public function testNoModuleShipsAModuleRootWebDirectory(): void
    {
        $offenders = [];
        foreach (PackageLocator::globInPackages('web', GLOB_ONLYDIR) as $dir) {
            $offenders[] = $this->relative($dir);
        }

        $this->assertSame(
            [],
            $offenders,
            "A module-root web/ directory is not a static-file location in Magento — move these "
            . "under view/<area>/web/ (or view/base/web/) so getViewFileUrl() and "
            . "setup:static-content:deploy can find them:\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * The canvas bundle specifically: both halves of the mount template must be
     * present at the path the template asks for. This is the regression this
     * module was filed against (#26) and is cheap to pin exactly.
     */
    public function testCanvasBundleShipsUnderAdminhtmlWeb(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $dist = $moduleRoot . '/view/adminhtml/web/js/dist';

        $this->assertTrue(
            is_file($dist . '/canvas.js'),
            'Missing built bundle at view/adminhtml/web/js/dist/canvas.js — the committed dist is '
            . 'the release artifact (no merchant build step); rebuild it with `npm run build` in app/.'
        );
        $this->assertTrue(
            is_file($dist . '/canvas.css'),
            'Missing built stylesheet at view/adminhtml/web/js/dist/canvas.css — without it the '
            . 'CSS request falls through to the LESS pre-processor and fails on a missing canvas.less.'
        );

        $template = $moduleRoot . '/view/adminhtml/templates/canvas/mount.phtml';
        $source = (string) file_get_contents($template);
        $this->assertStringContainsString(
            "getViewFileUrl('MageOS_WorkflowsCanvas::js/dist/canvas.js')",
            $source,
            'mount.phtml must reference the bundle by its module-relative view path.'
        );
        $this->assertStringContainsString(
            "getViewFileUrl('MageOS_WorkflowsCanvas::js/dist/canvas.css')",
            $source,
            'mount.phtml must reference the stylesheet by its module-relative view path.'
        );
    }

    /**
     * The bundler must write where Magento reads. A vite outDir pointing back at
     * the module root would silently re-create the bug on the next release build.
     */
    public function testBundlerWritesIntoTheAdminhtmlWebDirectory(): void
    {
        $config = (string) file_get_contents(dirname(__DIR__, 3) . '/app/vite.config.ts');

        $this->assertStringContainsString(
            "outDir: resolve(__dirname, '../view/adminhtml/web/js/dist')",
            $config,
            'vite build output must land under view/adminhtml/web/, not the module root.'
        );
        $this->assertStringNotContainsString(
            "'../web/js/dist'",
            $config,
            'vite outDir still points at the module-root web/ directory (issue #26).'
        );
    }
}
