<?php
declare(strict_types=1);

/**
 * i18n phrase collector for the workflows monorepo.
 *
 * Stands in for `bin/magento i18n:collect-phrases`, which needs an installed
 * Magento to run. It walks each src/module-* package and writes that package's
 * own translatable phrases to <package>/i18n/en_US.csv as identity rows
 * ("Source","Source") — en_US is the source locale, so nothing is translated
 * here; the catalog only exists so translators of other locales have a complete
 * phrase list and so `__()` calls resolve against a real dictionary entry.
 *
 * What counts as a phrase (mirroring Magento's parser adapters):
 *
 *   - PHP / PHTML: the first argument of `__(...)` and `->__(...)` when it is a
 *     single literal string. Collected with token_get_all, so occurrences in
 *     comments and inside other string literals are not seen. A first argument
 *     that is a variable, a concatenation, or a constant is skipped — Magento
 *     cannot extract those either, and guessing would put phantom rows in the
 *     catalog.
 *   - JS (and inline JS in .phtml): `$t('...')` and `$.mage.__('...')` with a
 *     literal argument.
 *   - XML: any element carrying a `translate` attribute. `translate="true"`
 *     translates the element's own text; `translate="label comment"` translates
 *     the named attributes, or — as system.xml does — the named child elements.
 *   - menu.xml / acl.xml: the `title` attribute of `<add>` and `<resource>`.
 *     Magento translates those at render time without a `translate` marker.
 *
 * Deliberately out of scope:
 *
 *   - Test/ directories (fixtures are not shipped UI copy).
 *   - src/module-workflows-canvas/app/ — the React app hardcodes English in TSX
 *     outside the `__()` pipeline; see issue #9 for the structural decision.
 *   - src/module-workflows-templates/templates/*.json — template titles,
 *     descriptions and parameter labels are data, not `__()` call sites; same
 *     open decision.
 *
 * Existing catalog rows are always preserved: the output is the union of what
 * the scan finds and what the file already had, so a hand-added phrase (or one
 * coming from a source this scanner does not understand) is never dropped.
 *
 * Usage:
 *   php dev/tools/i18n-collect-phrases.php            # rewrite catalogs
 *   php dev/tools/i18n-collect-phrases.php --check    # exit 1 if out of date
 *   php dev/tools/i18n-collect-phrases.php --print    # report, write nothing
 */

namespace MageOS\Workflows\Dev\I18n;

const SRC_DIR = __DIR__ . '/../../src';

/** Directory names never scanned, at any depth. */
const SKIP_DIRS = ['Test', 'node_modules', 'vendor', 'dist', 'app'];

/**
 * Packages that intentionally ship no catalog even though the scan may find
 * nothing — keyed by package dir name, value is the reason (printed in reports).
 */
const NO_CATALOG_REASON = [
    'metapackage-workflows-suite' => 'metapackage, no code',
];

/* -------------------------------------------------------------------------- */
/* PHP / PHTML                                                                */
/* -------------------------------------------------------------------------- */

/**
 * Phrases from `__('literal')` / `->__('literal')` call sites.
 *
 * @return string[]
 */
function parsePhp(string $code): array
{
    $phrases = [];
    $tokens = @token_get_all($code);
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== '__') {
            continue;
        }

        // `function __(...)` is a declaration, not a call.
        $before = previousMeaningful($tokens, $i);
        if ($before !== null && is_array($before) && $before[0] === T_FUNCTION) {
            continue;
        }

        $j = nextMeaningfulIndex($tokens, $i);
        if ($j === null || $tokens[$j] !== '(') {
            continue;
        }

        $phrase = readLiteralArgument($tokens, $j);
        if ($phrase !== null && $phrase !== '') {
            $phrases[] = $phrase;
        }
    }

    return $phrases;
}

/**
 * Read the first argument of a call whose `(` sits at $openIndex, when that
 * argument is one literal string or a concatenation of literal strings.
 *
 * `__('long sentence ' . 'split over lines')` is one phrase — the sources here
 * wrap long messages that way, and Magento's own parser joins them too. Any
 * non-literal operand (variable, constant, function call) makes the argument
 * dynamic, and the call site is skipped rather than guessed at.
 *
 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
 */
function readLiteralArgument(array $tokens, int $openIndex): ?string
{
    $phrase = '';
    $index = nextMeaningfulIndex($tokens, $openIndex);

    while (true) {
        if ($index === null) {
            return null;
        }
        $token = $tokens[$index];
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $part = decodePhpLiteral($token[1]);
        if ($part === null) {
            return null;
        }
        $phrase .= $part;

        $index = nextMeaningfulIndex($tokens, $index);
        if ($index === null) {
            return null;
        }
        if ($tokens[$index] === ',' || $tokens[$index] === ')') {
            return $phrase;
        }
        if ($tokens[$index] !== '.') {
            // Some other operator: the argument is an expression, not a phrase.
            return null;
        }
        $index = nextMeaningfulIndex($tokens, $index);
    }
}

/**
 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
 * @return array{0:int,1:string,2:int}|string|null
 */
function previousMeaningful(array $tokens, int $from)
{
    for ($i = $from - 1; $i >= 0; $i--) {
        if (isSkippableToken($tokens[$i])) {
            continue;
        }
        return $tokens[$i];
    }
    return null;
}

/**
 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
 */
function nextMeaningfulIndex(array $tokens, int $from): ?int
{
    $count = count($tokens);
    for ($i = $from + 1; $i < $count; $i++) {
        if (isSkippableToken($tokens[$i])) {
            continue;
        }
        return $i;
    }
    return null;
}

/**
 * @param array{0:int,1:string,2:int}|string $token
 */
function isSkippableToken($token): bool
{
    return is_array($token)
        && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
}

/**
 * Turn a PHP string literal (quotes included) into its runtime value.
 *
 * Double-quoted literals containing interpolation are rejected (null) — the
 * phrase would depend on runtime state, so it is not a catalog row.
 */
function decodePhpLiteral(string $literal): ?string
{
    $quote = $literal[0] ?? '';
    $body = substr($literal, 1, -1);

    if ($quote === "'") {
        return strtr($body, ['\\\\' => '\\', "\\'" => "'"]);
    }

    if ($quote !== '"') {
        return null;
    }

    // `$var`, `{$expr}` and `${expr}` mean interpolation; escaped `\$` does not.
    if (preg_match('/(?<!\\\\)(?:\$\{?[A-Za-z_\x80-\xff]|\{\$)/', $body)) {
        return null;
    }

    $out = '';
    $len = strlen($body);
    for ($i = 0; $i < $len; $i++) {
        if ($body[$i] !== '\\' || $i + 1 >= $len) {
            $out .= $body[$i];
            continue;
        }
        $next = $body[++$i];
        switch ($next) {
            case 'n':
                $out .= "\n";
                break;
            case 't':
                $out .= "\t";
                break;
            case 'r':
                $out .= "\r";
                break;
            case 'v':
                $out .= "\v";
                break;
            case 'f':
                $out .= "\f";
                break;
            case 'e':
                $out .= "\033";
                break;
            case '\\':
            case '"':
            case '$':
                $out .= $next;
                break;
            default:
                // \x41, \101, \u{1F600} and anything unrecognised: keep verbatim
                // rather than half-decode. None occur in this codebase; if one
                // appears, the row is visibly wrong instead of silently wrong.
                $out .= '\\' . $next;
        }
    }

    return $out;
}

/* -------------------------------------------------------------------------- */
/* JavaScript (standalone .js and inline <script> in .phtml)                   */
/* -------------------------------------------------------------------------- */

/**
 * Phrases from `$t('literal')` and `$.mage.__('literal')`.
 *
 * @return string[]
 */
function parseJs(string $code): array
{
    $phrases = [];
    $patterns = [
        '/(?<![\w$.])\$t\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*[),]/',
        '/\$\.mage\.__\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*[),]/',
    ];

    foreach ($patterns as $pattern) {
        if (!preg_match_all($pattern, $code, $matches)) {
            continue;
        }
        foreach ($matches[1] as $literal) {
            $phrase = decodeJsLiteral($literal);
            if ($phrase !== '') {
                $phrases[] = $phrase;
            }
        }
    }

    return $phrases;
}

function decodeJsLiteral(string $literal): string
{
    $body = substr($literal, 1, -1);

    return preg_replace_callback(
        '/\\\\(.)/',
        static function (array $m): string {
            return match ($m[1]) {
                'n' => "\n",
                't' => "\t",
                'r' => "\r",
                default => $m[1],
            };
        },
        $body
    ) ?? $body;
}

/* -------------------------------------------------------------------------- */
/* XML                                                                        */
/* -------------------------------------------------------------------------- */

/**
 * @return string[]
 */
function parseXml(string $path): array
{
    $phrases = [];

    $previous = libxml_use_internal_errors(true);
    $doc = new \DOMDocument();
    $loaded = $doc->load($path, LIBXML_NOCDATA | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
        fwrite(STDERR, "warning: could not parse XML {$path}\n");
        return [];
    }

    $xpath = new \DOMXPath($doc);

    /** @var \DOMElement $element */
    foreach ($xpath->query('//*[@translate]') ?: [] as $element) {
        $translate = trim($element->getAttribute('translate'));
        foreach (preg_split('/[\s,]+/', $translate) ?: [] as $target) {
            if ($target === '') {
                continue;
            }
            if ($target === 'true') {
                $phrases[] = normalise($element->textContent);
                continue;
            }
            if ($element->hasAttribute($target)) {
                $phrases[] = normalise($element->getAttribute($target));
                continue;
            }
            // system.xml style: translate="label comment" naming child elements.
            foreach ($element->childNodes as $child) {
                if ($child instanceof \DOMElement && $child->localName === $target) {
                    $phrases[] = normalise($child->textContent);
                }
            }
        }
    }

    // Attributes Magento (or this suite) translates at render time without a
    // `translate` marker, keyed by file name so the xpath stays narrow.
    $untagged = [
        // <add title="..."/> in the admin menu, <resource title="..."/> in ACL.
        'menu.xml' => '//add[@title]/@title',
        'acl.xml' => '//resource[@title]/@title',
        // workflow_triggers.xsd documents label and group as translatable UI copy.
        'workflow_triggers.xml' => '//trigger/@label | //trigger/@group',
        // Shown in the admin "Load default template" picker.
        'email_templates.xml' => '//template/@label',
    ];

    $query = $untagged[basename($path)] ?? null;
    if ($query !== null) {
        foreach ($xpath->query($query) ?: [] as $attribute) {
            $phrases[] = normalise($attribute->nodeValue ?? '');
        }
    }

    return array_values(array_filter($phrases, static fn(string $p): bool => $p !== ''));
}

/**
 * Collapse the incidental whitespace that XML indentation introduces into
 * multi-line element text, which is how Magento renders it anyway.
 */
function normalise(string $text): string
{
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

/* -------------------------------------------------------------------------- */
/* Walking                                                                    */
/* -------------------------------------------------------------------------- */

/**
 * @return string[] absolute file paths
 */
function collectFiles(string $dir): array
{
    $files = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            static function (\SplFileInfo $current): bool {
                return !$current->isDir() || !in_array($current->getFilename(), SKIP_DIRS, true);
            }
        )
    );

    /** @var \SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);
    return $files;
}

/**
 * @return string[] phrases, de-duplicated, in case-insensitive alphabetical order
 */
function collectPackagePhrases(string $packageDir): array
{
    $phrases = [];

    foreach (collectFiles($packageDir) as $file) {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($extension, ['php', 'phtml', 'js', 'xml'], true)) {
            continue;
        }
        if (str_ends_with($file, '/i18n/en_US.csv')) {
            continue;
        }

        if ($extension === 'xml') {
            $phrases = array_merge($phrases, parseXml($file));
            continue;
        }

        $code = (string) file_get_contents($file);

        if ($extension === 'php' || $extension === 'phtml') {
            $phrases = array_merge($phrases, parsePhp($code));
        }
        if ($extension === 'js' || $extension === 'phtml') {
            // .phtml is scanned twice on purpose: `__()` in the PHP islands and
            // `$.mage.__()` in the inline <script> block are both real call sites.
            $phrases = array_merge($phrases, parseJs($code));
        }
    }

    return $phrases;
}

/* -------------------------------------------------------------------------- */
/* CSV                                                                        */
/* -------------------------------------------------------------------------- */

/**
 * @return string[] the source column of an existing catalog
 */
function readCatalog(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $rows = [];
    $handle = fopen($path, 'r');
    if ($handle === false) {
        return [];
    }
    while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        if ($row === [null] || $row === false || !isset($row[0]) || $row[0] === '') {
            continue;
        }
        $rows[] = $row[0];
    }
    fclose($handle);

    return $rows;
}

/**
 * @param string[] $phrases
 */
function renderCatalog(array $phrases): string
{
    $out = '';
    foreach ($phrases as $phrase) {
        $escaped = str_replace('"', '""', $phrase);
        $out .= '"' . $escaped . '","' . $escaped . '"' . "\n";
    }
    return $out;
}

/**
 * @param string[] $phrases
 * @return string[]
 */
function sortPhrases(array $phrases): array
{
    $unique = array_values(array_unique($phrases));
    usort($unique, static function (string $a, string $b): int {
        return strcasecmp($a, $b) ?: strcmp($a, $b);
    });
    return $unique;
}

/* -------------------------------------------------------------------------- */
/* Main                                                                       */
/* -------------------------------------------------------------------------- */

$options = array_slice($argv, 1);
$check = in_array('--check', $options, true);
$printOnly = in_array('--print', $options, true);

$srcDir = realpath(SRC_DIR);
if ($srcDir === false) {
    fwrite(STDERR, "error: src/ not found\n");
    exit(1);
}

$packages = glob($srcDir . '/*', GLOB_ONLYDIR) ?: [];
sort($packages);

$stale = [];
$totals = [];

foreach ($packages as $packageDir) {
    $name = basename($packageDir);
    $catalogPath = $packageDir . '/i18n/en_US.csv';

    if (isset(NO_CATALOG_REASON[$name])) {
        continue;
    }

    $found = collectPackagePhrases($packageDir);
    $existing = readCatalog($catalogPath);
    $phrases = sortPhrases(array_merge($existing, $found));

    if ($phrases === []) {
        $totals[$name] = 0;
        continue;
    }

    $totals[$name] = count($phrases);
    $onlyInCatalog = array_values(array_diff($existing, $found));
    $newRows = array_values(array_diff($phrases, $existing));

    $contents = renderCatalog($phrases);
    $current = is_file($catalogPath) ? (string) file_get_contents($catalogPath) : null;

    printf(
        "%-40s %4d phrases (%d new, %d catalog-only)\n",
        $name,
        count($phrases),
        count($newRows),
        count($onlyInCatalog)
    );
    foreach ($onlyInCatalog as $orphan) {
        printf("    kept (not found by scan): %s\n", $orphan);
    }

    if ($printOnly) {
        continue;
    }

    if ($current === $contents) {
        continue;
    }

    if ($check) {
        $stale[] = $name;
        continue;
    }

    if (!is_dir(dirname($catalogPath))) {
        mkdir(dirname($catalogPath), 0755, true);
    }
    file_put_contents($catalogPath, $contents);
}

printf("\n%d phrases across %d packages\n", array_sum($totals), count(array_filter($totals)));

if ($check && $stale !== []) {
    fwrite(STDERR, "\nout-of-date catalogs: " . implode(', ', $stale) . "\n");
    fwrite(STDERR, "run: php dev/tools/i18n-collect-phrases.php\n");
    exit(1);
}

exit(0);
