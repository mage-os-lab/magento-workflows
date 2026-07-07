<?php

/**
 * Zero-dependency test runner for the Mage-OS Workflow Engine unit tests.
 *
 * Neither PHPUnit nor Magento is installed in this environment. This script
 * defines a minimal PHPUnit\Framework\TestCase-compatible shim, PSR-4
 * autoloaders for every src/module-* package (prefixes read from each
 * module's composer.json), a last-in-chain shim autoloader for Magento\ /
 * Psr\Log\ classes backed by dev/tests/shims/, discovers every *Test.php
 * file under each module's Test/Unit directory (all five src/module-…
 * packages), and runs it.
 *
 * The real test suites (Test/Unit trees under src/module-…) are written against
 * the standard PHPUnit\Framework\TestCase API so they also run unmodified
 * under a real PHPUnit installation in CI. The class shims under
 * dev/tests/shims/ are registered only by this runner — and only after the
 * module autoloaders — so real Magento / psr-log / PHPUnit classes always
 * win when installed (see dev/tests/shims/README.md).
 *
 * Usage: php dev/tests/standalone-runner.php
 */

declare(strict_types=1);

// NOTE: only `declare()` and comments may precede a bracketed namespace
// declaration, so all setup happens inside the global `namespace { ... }`
// block below.

// ---------------------------------------------------------------------
// Minimal PHPUnit shim
// ---------------------------------------------------------------------

namespace PHPUnit\Framework {

    class AssertionFailedError extends \Exception
    {
    }

    abstract class TestCase
    {
        /** @var string|null */
        private $expectedExceptionForRunner;

        /** @var string|null */
        private $expectedExceptionMessageForRunner;

        public function setUp(): void
        {
        }

        public function tearDown(): void
        {
        }

        protected function expectException(string $exceptionClass): void
        {
            $this->expectedExceptionForRunner = $exceptionClass;
        }

        protected function expectExceptionMessage(string $message): void
        {
            $this->expectedExceptionMessageForRunner = $message;
        }

        /**
         * @internal used only by the standalone runner
         */
        public function getExpectedExceptionForRunner(): ?string
        {
            return $this->expectedExceptionForRunner;
        }

        /**
         * @internal used only by the standalone runner
         */
        public function getExpectedExceptionMessageForRunner(): ?string
        {
            return $this->expectedExceptionMessageForRunner;
        }

        protected function fail(string $message = ''): void
        {
            throw new AssertionFailedError($message !== '' ? $message : 'fail() was called');
        }

        protected function assertTrue($condition, string $message = ''): void
        {
            if ($condition !== true) {
                throw new AssertionFailedError($message !== '' ? $message : 'Failed asserting that value is true.');
            }
        }

        protected function assertFalse($condition, string $message = ''): void
        {
            if ($condition !== false) {
                throw new AssertionFailedError($message !== '' ? $message : 'Failed asserting that value is false.');
            }
        }

        protected function assertNull($actual, string $message = ''): void
        {
            if ($actual !== null) {
                throw new AssertionFailedError(
                    $message !== '' ? $message : 'Failed asserting that value is null, got: ' . var_export($actual, true)
                );
            }
        }

        protected function assertNotNull($actual, string $message = ''): void
        {
            if ($actual === null) {
                throw new AssertionFailedError($message !== '' ? $message : 'Failed asserting that value is not null.');
            }
        }

        protected function assertSame($expected, $actual, string $message = ''): void
        {
            if ($expected !== $actual) {
                throw new AssertionFailedError($message !== '' ? $message : sprintf(
                    'Failed asserting that %s is identical to %s.',
                    self::export($actual),
                    self::export($expected)
                ));
            }
        }

        protected function assertEquals($expected, $actual, string $message = ''): void
        {
            // phpcs:ignore -- intentional loose comparison, mirrors PHPUnit::assertEquals
            if ($expected != $actual) {
                throw new AssertionFailedError($message !== '' ? $message : sprintf(
                    'Failed asserting that %s equals %s.',
                    self::export($actual),
                    self::export($expected)
                ));
            }
        }

        protected function assertCount(int $expectedCount, $haystack, string $message = ''): void
        {
            $actualCount = is_countable($haystack) ? count($haystack) : -1;
            if ($actualCount !== $expectedCount) {
                throw new AssertionFailedError($message !== '' ? $message : sprintf(
                    'Failed asserting that actual count %d matches expected count %d.',
                    $actualCount,
                    $expectedCount
                ));
            }
        }

        protected function assertArrayHasKey($key, $array, string $message = ''): void
        {
            if (!is_array($array) || !array_key_exists($key, $array)) {
                throw new AssertionFailedError($message !== '' ? $message : sprintf(
                    'Failed asserting that array has key %s.',
                    self::export($key)
                ));
            }
        }

        protected function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
        {
            if (!str_contains($haystack, $needle)) {
                throw new AssertionFailedError($message !== '' ? $message : sprintf(
                    'Failed asserting that %s contains %s.',
                    self::export($haystack),
                    self::export($needle)
                ));
            }
        }

        protected function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
        {
            if (str_contains($haystack, $needle)) {
                throw new AssertionFailedError($message !== '' ? $message : sprintf(
                    'Failed asserting that %s does not contain %s.',
                    self::export($haystack),
                    self::export($needle)
                ));
            }
        }

        protected function assertInstanceOf(string $expectedClass, $actual, string $message = ''): void
        {
            if (!($actual instanceof $expectedClass)) {
                throw new AssertionFailedError($message !== '' ? $message : sprintf(
                    'Failed asserting that %s is an instance of %s.',
                    is_object($actual) ? get_class($actual) : gettype($actual),
                    $expectedClass
                ));
            }
        }

        private static function export($value): string
        {
            if (is_array($value) || is_object($value)) {
                return var_export($value, true);
            }
            return var_export($value, true);
        }
    }
}

// ---------------------------------------------------------------------
// Runner
// ---------------------------------------------------------------------

namespace {

    use PHPUnit\Framework\AssertionFailedError;
    use PHPUnit\Framework\TestCase;

    error_reporting(E_ALL);
    ini_set('display_errors', '1');

    $repoRoot = dirname(__DIR__, 2);

    // -----------------------------------------------------------------
    // Module PSR-4 autoloaders: prefix map read from each module's
    // composer.json ("autoload"."psr-4"), e.g.
    //   MageOS\Workflows\             => src/module-workflows/
    //   MageOS\WorkflowsActionsCore\  => src/module-workflows-actions-core/
    //   MageOS\WorkflowsAdminUi\     => src/module-workflows-admin-ui/
    //   MageOS\WorkflowsScheduler\    => src/module-workflows-scheduler/
    //   MageOS\WorkflowsTriggersCore\ => src/module-workflows-triggers-core/
    // -----------------------------------------------------------------

    /** @var array<string, string> $psr4Map prefix => module source root */
    $psr4Map = [];
    foreach (glob($repoRoot . '/src/module-*', GLOB_ONLYDIR) ?: [] as $moduleDir) {
        $composerFile = $moduleDir . '/composer.json';
        if (!is_file($composerFile)) {
            continue;
        }
        $composer = json_decode((string)file_get_contents($composerFile), true);
        foreach (($composer['autoload']['psr-4'] ?? []) as $prefix => $relativeDir) {
            $psr4Map[$prefix] = rtrim($moduleDir . '/' . ltrim((string)$relativeDir, '/'), '/');
        }
    }
    ksort($psr4Map);

    spl_autoload_register(static function (string $class) use ($psr4Map): void {
        foreach ($psr4Map as $prefix => $baseDir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = substr($class, strlen($prefix));
            $path = $baseDir . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($path)) {
                require_once $path;
                return;
            }
        }
    });

    // -----------------------------------------------------------------
    // Shim autoloader — registered LAST so that when real Magento /
    // psr-log / mageos-async-events / cloudevents packages are installed
    // their autoloaders (registered earlier in the chain) always win. Only
    // fires for third-party namespaces nothing else could load — never for
    // this repo's own MageOS\Workflows* prefixes (their generated factories
    // get per-file stand-ins instead); each shim lives in its own file
    // mirroring PSR-4 under dev/tests/shims/.
    // -----------------------------------------------------------------

    $shimRoot = $repoRoot . '/dev/tests/shims';
    spl_autoload_register(static function (string $class) use ($shimRoot): void {
        if (!str_starts_with($class, 'Magento\\')
            && !str_starts_with($class, 'Psr\\Log\\')
            && !str_starts_with($class, 'MageOS\\AsyncEvents\\')
            && !str_starts_with($class, 'CloudEvents\\')
        ) {
            return;
        }
        $path = $shimRoot . '/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    // Global __() translation-function shim (Magento defines this in
    // app/functions.php on real installs).
    if (!function_exists('__')) {
        /**
         * @return \Magento\Framework\Phrase
         */
        function __(...$argc)
        {
            $text = (string)array_shift($argc);
            if (!empty($argc) && is_array($argc[0])) {
                $argc = $argc[0];
            }
            return new \Magento\Framework\Phrase($text, $argc);
        }
    }

    /**
     * @return string[] absolute paths to *Test.php files
     */
    function discoverTestFiles(string $dir): array
    {
        $found = [];
        if (!is_dir($dir)) {
            return $found;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if ($fileInfo->isFile() && str_ends_with($fileInfo->getFilename(), 'Test.php')) {
                $found[] = $fileInfo->getPathname();
            }
        }
        sort($found);
        return $found;
    }

    /**
     * @param array<string, string> $psr4Map prefix => module source root
     */
    function classNameFromPath(string $path, array $psr4Map): ?string
    {
        $path = str_replace('\\', '/', $path);
        foreach ($psr4Map as $prefix => $baseDir) {
            $root = str_replace('\\', '/', $baseDir);
            if (!str_starts_with($path, $root . '/')) {
                continue;
            }
            $relative = substr($path, strlen($root) + 1);
            $relative = preg_replace('/\.php$/', '', $relative);
            return $prefix . str_replace('/', '\\', $relative);
        }
        return null;
    }

    // Discover *Test.php under EVERY src/module-*/Test/Unit directory.
    $testFiles = [];
    foreach ($psr4Map as $baseDir) {
        $testFiles = array_merge($testFiles, discoverTestFiles($baseDir . '/Test/Unit'));
    }
    sort($testFiles);

    $totalTests = 0;
    $totalFailures = 0;
    /** @var array<int, array{class:string, method:string, message:string}> $failures */
    $failures = [];

    echo "Mage-OS Workflow Engine — standalone test runner\n";
    echo str_repeat('=', 60) . "\n";

    foreach ($testFiles as $testFile) {
        $class = classNameFromPath($testFile, $psr4Map);
        if ($class === null || !class_exists($class)) {
            echo "SKIP  (could not resolve class for {$testFile})\n";
            continue;
        }

        $reflection = new \ReflectionClass($class);
        if ($reflection->isAbstract() || !$reflection->isSubclassOf(TestCase::class)) {
            continue;
        }

        echo "\n{$class}\n";

        $methods = array_filter(
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn (\ReflectionMethod $m) => str_starts_with($m->getName(), 'test') && !$m->isStatic()
        );
        usort($methods, static fn (\ReflectionMethod $a, \ReflectionMethod $b) => $a->getName() <=> $b->getName());

        foreach ($methods as $method) {
            $methodName = $method->getName();
            $totalTests++;

            /** @var TestCase $instance */
            $instance = $reflection->newInstance();

            try {
                $instance->setUp();

                try {
                    $instance->{$methodName}();
                    $expected = $instance->getExpectedExceptionForRunner();
                    if ($expected !== null) {
                        throw new AssertionFailedError(
                            "Expected exception \"{$expected}\" was not thrown."
                        );
                    }
                    echo "  [PASS] {$methodName}\n";
                } catch (AssertionFailedError $e) {
                    $totalFailures++;
                    $failures[] = ['class' => $class, 'method' => $methodName, 'message' => $e->getMessage()];
                    echo "  [FAIL] {$methodName} — {$e->getMessage()}\n";
                } catch (\Throwable $e) {
                    $expected = $instance->getExpectedExceptionForRunner();
                    $expectedMessage = $instance->getExpectedExceptionMessageForRunner();
                    if ($expected !== null && $e instanceof $expected
                        && ($expectedMessage === null || str_contains($e->getMessage(), $expectedMessage))
                    ) {
                        echo "  [PASS] {$methodName}\n";
                    } else {
                        $totalFailures++;
                        $failures[] = [
                            'class' => $class,
                            'method' => $methodName,
                            'message' => sprintf(
                                'Unexpected exception %s: %s',
                                get_class($e),
                                $e->getMessage()
                            ),
                        ];
                        echo "  [FAIL] {$methodName} — unexpected " . get_class($e) . ': ' . $e->getMessage() . "\n";
                    }
                }
            } finally {
                $instance->tearDown();
            }
        }
    }

    echo "\n" . str_repeat('=', 60) . "\n";
    printf("Ran %d test(s), %d failure(s)\n", $totalTests, $totalFailures);

    if ($totalFailures > 0) {
        echo "\nFailures:\n";
        foreach ($failures as $failure) {
            echo "  - {$failure['class']}::{$failure['method']}\n";
            echo "      {$failure['message']}\n";
        }
        exit(1);
    }

    exit(0);
}
