<?php
declare(strict_types=1);

/**
 * Dependency-honesty check (domain-packs E4).
 *
 * For every src/module-* package, gather the Magento modules it references at
 * compile time and fail when one is missing from the package's composer.json
 * "require". Compile-time references are:
 *
 *   - PHP: every qualified/fully-qualified `Magento\<Module>\...` name token in
 *     the package's own PHP (Test/ excluded). Comments and string literals are
 *     skipped — token_get_all only yields real name tokens, so a class named in
 *     a docblock is not a reference.
 *   - XML: every `Magento\<Module>\...` FQCN in etc/*.xml element text or
 *     attribute values (di.xml object refs, plugin/preference/type/virtualType
 *     class names). Parsed with DOMDocument, so comments are skipped.
 *
 * Namespace → package: `Magento\Framework` => magento/framework; anything else
 * `Magento\Xyz` => magento/module-<kebab(Xyz)> (CatalogInventory =>
 * catalog-inventory, SalesRule => sales-rule). Magento\TestFramework is ignored.
 *
 * Cross-pack (first-party): a `MageOS\Workflows...` reference maps to the sibling
 * workflows package — `MageOS\Workflows` => mage-os/workflows,
 * `MageOS\WorkflowsSales` => mage-os/workflows-sales, etc. A package referencing
 * another workflows pack's classes at compile time must require it (a package's
 * own namespace is excluded). Other `MageOS\*` namespaces (e.g. AsyncEvents) are
 * third-party and not tracked here — their composer names don't follow this
 * kebab mapping. Same baseline/inline-allowlist mechanics apply.
 *
 * Two escape hatches:
 *   - Inline: a PHP file carrying the comment
 *     `@workflows-dependency-allowlist Magento\Xyz` has that module's references
 *     in that file excluded (for sanctioned runtime-guarded references behind
 *     interface_exists()).
 *   - Baseline: dependency-honesty-baseline.json lists known-undeclared
 *     references that are reported as WARNINGS. Any missing reference NOT in the
 *     baseline is an ERROR and the script exits 1.
 *
 * Usage:
 *   php dev/tools/dependency-honesty-check.php                 # check (CI)
 *   php dev/tools/dependency-honesty-check.php --generate-baseline
 */

namespace MageOS\Workflows\Dev\DependencyHonesty;

const BASELINE_FILE = __DIR__ . '/dependency-honesty-baseline.json';
const INLINE_ALLOWLIST_TAG = '@workflows-dependency-allowlist';

/**
 * CamelCase module segment => kebab-case (CatalogInventory => catalog-inventory).
 */
function kebab(string $name): string
{
    $hyphenated = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $name) ?? $name;
    return strtolower($hyphenated);
}

/**
 * Second segment of a `Magento\Xyz\...` FQCN => composer package name, or null
 * when the name is not a Magento module reference we track (framework is a
 * package; TestFramework and non-Magento names are ignored).
 */
function packageForFqcn(string $fqcn): ?string
{
    $fqcn = ltrim($fqcn, '\\');
    $parts = explode('\\', $fqcn);
    if (count($parts) < 2) {
        return null;
    }
    if ($parts[0] === 'Magento') {
        $module = $parts[1];
        if ($module === 'TestFramework') {
            return null;
        }
        if ($module === 'Framework') {
            return 'magento/framework';
        }
        return 'magento/module-' . kebab($module);
    }
    // First-party cross-pack: MageOS\Workflows... => mage-os/workflows...
    // (kebab of the second segment). Other MageOS\* namespaces are third-party.
    if ($parts[0] === 'MageOS' && str_starts_with($parts[1], 'Workflows')) {
        return 'mage-os/' . kebab($parts[1]);
    }
    return null;
}

/**
 * @return array{0: array<string, true>, 1: array<string, true>} [referenced packages, inline-allowlisted packages]
 */
function scanPhpFile(string $path): array
{
    $source = (string) file_get_contents($path);
    $referenced = [];
    $allowlisted = [];

    if (preg_match_all(
        '/' . preg_quote(INLINE_ALLOWLIST_TAG, '/') . '\s+(Magento\\\\[A-Za-z0-9_]+)/',
        $source,
        $matches
    )) {
        foreach ($matches[1] as $ns) {
            $package = packageForFqcn($ns . '\\_');
            if ($package !== null) {
                $allowlisted[$package] = true;
            }
        }
    }

    foreach (token_get_all($source) as $token) {
        if (!is_array($token)) {
            continue;
        }
        if ($token[0] !== T_NAME_QUALIFIED && $token[0] !== T_NAME_FULLY_QUALIFIED) {
            continue;
        }
        $package = packageForFqcn($token[1]);
        if ($package !== null) {
            $referenced[$package] = true;
        }
    }

    return [$referenced, $allowlisted];
}

/**
 * @return array<string, true> referenced packages
 */
function scanXmlFile(string $path): array
{
    $referenced = [];
    $dom = new \DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $dom->load($path);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) {
        return $referenced;
    }

    $xpath = new \DOMXPath($dom);
    // Every text node and every attribute value; comments are not selected.
    foreach ($xpath->query('//text() | //@*') as $node) {
        if (preg_match_all('/\\\\?(?:Magento|MageOS)(?:\\\\[A-Za-z0-9_]+)+/', (string) $node->nodeValue, $matches)) {
            foreach ($matches[0] as $fqcn) {
                $package = packageForFqcn($fqcn);
                if ($package !== null) {
                    $referenced[$package] = true;
                }
            }
        }
    }

    return $referenced;
}

/**
 * @return string[]
 */
function listFiles(string $dir, string $extension): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $files = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        /** @var \SplFileInfo $fileInfo */
        if (!$fileInfo->isFile() || $fileInfo->getExtension() !== $extension) {
            continue;
        }
        $path = $fileInfo->getPathname();
        // Exclude tests from the compile-time surface.
        if (str_contains(str_replace('\\', '/', $path), '/Test/')) {
            continue;
        }
        $files[] = $path;
    }
    sort($files);
    return $files;
}

/**
 * @return array{name: string, declared: array<string, true>, missing: string[]}
 */
function analyzePackage(string $moduleDir): array
{
    $composer = json_decode((string) file_get_contents($moduleDir . '/composer.json'), true);
    $name = (string) ($composer['name'] ?? basename($moduleDir));
    $declared = [];
    foreach (array_keys($composer['require'] ?? []) as $require) {
        $declared[(string) $require] = true;
    }

    /** @var array<string, true> $referenced */
    $referenced = [];
    foreach (listFiles($moduleDir, 'php') as $phpFile) {
        [$fileRefs, $allowlisted] = scanPhpFile($phpFile);
        foreach ($fileRefs as $package => $_) {
            if (!isset($allowlisted[$package])) {
                $referenced[$package] = true;
            }
        }
    }
    foreach (listFiles($moduleDir . '/etc', 'xml') as $xmlFile) {
        foreach (scanXmlFile($xmlFile) as $package => $_) {
            $referenced[$package] = true;
        }
    }

    // A package never requires itself.
    unset($referenced[$name]);

    $missing = [];
    foreach (array_keys($referenced) as $package) {
        if (!isset($declared[$package])) {
            $missing[] = $package;
        }
    }
    sort($missing);

    return ['name' => $name, 'declared' => $declared, 'missing' => $missing];
}

// ---------------------------------------------------------------------------

$repoRoot = dirname(__DIR__, 2);
$generateBaseline = in_array('--generate-baseline', $argv, true);

/** @var array<string, string[]> $baseline package => allowlisted missing packages */
$baseline = [];
if (!$generateBaseline && is_file(BASELINE_FILE)) {
    $decoded = json_decode((string) file_get_contents(BASELINE_FILE), true);
    if (is_array($decoded)) {
        /** @var array<string, string[]> $baseline */
        $baseline = $decoded;
    }
}

$moduleDirs = glob($repoRoot . '/src/module-*', GLOB_ONLYDIR) ?: [];
sort($moduleDirs);

/** @var array<string, string[]> $newBaseline */
$newBaseline = [];
$errors = [];
$warnings = [];

echo "Dependency-honesty check (domain-packs E4)\n";
echo str_repeat('=', 60) . "\n";

foreach ($moduleDirs as $moduleDir) {
    if (!is_file($moduleDir . '/composer.json')) {
        continue;
    }
    $result = analyzePackage($moduleDir);
    $name = $result['name'];
    $missing = $result['missing'];

    if ($missing === []) {
        echo "  [OK]   {$name}\n";
        continue;
    }

    if ($generateBaseline) {
        $newBaseline[$name] = $missing;
        echo "  [BASE] {$name}: " . implode(', ', $missing) . "\n";
        continue;
    }

    $allowed = $baseline[$name] ?? [];
    foreach ($missing as $package) {
        if (in_array($package, $allowed, true)) {
            $warnings[] = "{$name}: {$package} (baselined)";
        } else {
            $errors[] = "{$name}: {$package}";
        }
    }
    $status = array_diff($missing, $allowed) === [] ? '[WARN]' : '[FAIL]';
    echo "  {$status} {$name}: " . implode(', ', $missing) . "\n";
}

echo str_repeat('=', 60) . "\n";

if ($generateBaseline) {
    ksort($newBaseline);
    file_put_contents(
        BASELINE_FILE,
        json_encode($newBaseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );
    echo "Wrote baseline: " . BASELINE_FILE . " (" . count($newBaseline) . " package(s))\n";
    exit(0);
}

if ($warnings !== []) {
    echo "\nBaselined (warnings, " . count($warnings) . "):\n";
    foreach ($warnings as $warning) {
        echo "  - {$warning}\n";
    }
}

if ($errors !== []) {
    echo "\nUndeclared dependencies NOT in baseline (" . count($errors) . "):\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    echo "\nDeclare the module in the package's composer.json require, add an\n";
    echo INLINE_ALLOWLIST_TAG . " annotation for a runtime-guarded reference, or\n";
    echo "regenerate the baseline (--generate-baseline) if this is intentional.\n";
    exit(1);
}

echo "\nAll referenced Magento modules are declared (or baselined). OK.\n";
exit(0);
