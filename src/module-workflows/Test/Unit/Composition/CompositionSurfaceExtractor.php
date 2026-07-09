<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Composition;

/**
 * Extracts the merged registration surface of the whole monorepo from its
 * etc/*.xml config — the pools, trigger metadata, async-event and observer
 * wiring that DI would merge at runtime — WITHOUT a Magento install. Pure XML
 * parsing (DOMDocument), so it runs under the standalone runner.
 *
 * The golden composition test (domain-packs S0) snapshots this into a fixture
 * and asserts byte-equality. Its purpose: a pure-relocation refactor (S1–S5
 * moving classes/di blocks into new packs) is correct iff this merged surface
 * is identical before and after — the codes are the contract, not the file
 * locations. Every section is normalized to a flat string=>string map so a
 * diff reads as plain added/removed/changed keys.
 *
 * Not named *Test.php, so the runner autoloads but does not execute it.
 */
final class CompositionSurfaceExtractor
{
    /**
     * Flat DI pools: section key => [pool/virtualType class name, array argument name].
     * Each contributes `item name` => class-string entries merged across every di.xml.
     */
    private const FLAT_POOLS = [
        'actions' => ['MageOS\\Workflows\\Model\\Action\\ActionPool', 'actions'],
        'combines' => ['MageOS\\Workflows\\Model\\Rule\\ConditionCombinePool', 'combines'],
        'relations' => ['MageOS\\Workflows\\Model\\Relation\\RelationPool', 'relations'],
        'hydrators' => ['MageOS\\Workflows\\Model\\Rule\\HydrationProvider', 'hydrators'],
        'option_sources' => ['MageOS\\Workflows\\Model\\Option\\OptionSourcePool', 'sources'],
    ];

    /**
     * Nested aggregate-provider pool: entity_type => (item name => provider class).
     * Flattened to "entity_type.item_name" => class.
     */
    private const AGGREGATE_POOL = ['MageOS\\Workflows\\Model\\Rule\\AggregateProviderPool', 'providers'];

    /**
     * @return array<string, array<string, string>> section => (key => value), each section ksorted
     */
    public static function extract(string $repoRoot): array
    {
        $surface = [
            'actions' => [],
            'combines' => [],
            'relations' => [],
            'hydrators' => [],
            'aggregate_providers' => [],
            'option_sources' => [],
            'trigger_events' => [],
            'async_events' => [],
            'observers' => [],
        ];

        foreach (self::etcXmlFiles($repoRoot) as $file) {
            $dom = self::load($file);
            if ($dom === null || $dom->documentElement === null) {
                continue;
            }
            $rootName = $dom->documentElement->localName;
            if ($rootName === 'config') {
                self::collectDiPools($dom, $surface);
                self::collectAsyncEvents($dom, $surface);
                self::collectObservers($dom, $surface);
            } elseif ($rootName === 'triggers') {
                self::collectTriggers($dom, $surface);
            }
        }

        foreach ($surface as $section => $entries) {
            ksort($entries);
            $surface[$section] = $entries;
        }

        return $surface;
    }

    /**
     * @return string[] absolute paths to every etc/*.xml under every src/module-*
     */
    private static function etcXmlFiles(string $repoRoot): array
    {
        $files = [];
        foreach (glob($repoRoot . '/src/module-*', GLOB_ONLYDIR) ?: [] as $moduleDir) {
            $etcDir = $moduleDir . '/etc';
            if (!is_dir($etcDir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($etcDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileInfo) {
                /** @var \SplFileInfo $fileInfo */
                if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'xml') {
                    $files[] = $fileInfo->getPathname();
                }
            }
        }
        sort($files);
        return $files;
    }

    private static function load(string $file): ?\DOMDocument
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->load($file, LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $loaded ? $dom : null;
    }

    /**
     * @param array<string, array<string, string>> $surface
     */
    private static function collectDiPools(\DOMDocument $dom, array &$surface): void
    {
        $reverse = [];
        foreach (self::FLAT_POOLS as $section => [$class, $argument]) {
            $reverse[$class] = [$section, $argument];
        }
        [$aggClass, $aggArgument] = self::AGGREGATE_POOL;

        foreach (self::elementsByTag($dom, ['type', 'virtualType']) as $type) {
            $name = $type->getAttribute('name');
            if (isset($reverse[$name])) {
                [$section, $argument] = $reverse[$name];
                $arrayNode = self::findArrayArgument($type, $argument);
                if ($arrayNode !== null) {
                    foreach (self::childItems($arrayNode) as $itemName => $itemNode) {
                        $surface[$section][$itemName] = trim($itemNode->textContent);
                    }
                }
            }
            if ($name === $aggClass) {
                $arrayNode = self::findArrayArgument($type, $aggArgument);
                if ($arrayNode !== null) {
                    foreach (self::childItems($arrayNode) as $entityType => $entityNode) {
                        foreach (self::childItems($entityNode) as $providerKey => $providerNode) {
                            $surface['aggregate_providers'][$entityType . '.' . $providerKey]
                                = trim($providerNode->textContent);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param array<string, array<string, string>> $surface
     */
    private static function collectAsyncEvents(\DOMDocument $dom, array &$surface): void
    {
        foreach ($dom->getElementsByTagName('async_event') as $asyncEvent) {
            /** @var \DOMElement $asyncEvent */
            $name = $asyncEvent->getAttribute('name');
            if ($name === '') {
                continue;
            }
            foreach ($asyncEvent->getElementsByTagName('service') as $service) {
                /** @var \DOMElement $service */
                $surface['async_events'][$name]
                    = $service->getAttribute('class') . '::' . $service->getAttribute('method');
            }
        }
    }

    /**
     * @param array<string, array<string, string>> $surface
     */
    private static function collectObservers(\DOMDocument $dom, array &$surface): void
    {
        foreach ($dom->getElementsByTagName('event') as $event) {
            /** @var \DOMElement $event */
            $eventName = $event->getAttribute('name');
            if ($eventName === '') {
                continue;
            }
            foreach ($event->getElementsByTagName('observer') as $observer) {
                /** @var \DOMElement $observer */
                $observerName = $observer->getAttribute('name');
                $surface['observers'][$eventName . '::' . $observerName]
                    = $observer->getAttribute('instance');
            }
        }
    }

    /**
     * @param array<string, array<string, string>> $surface
     */
    private static function collectTriggers(\DOMDocument $dom, array &$surface): void
    {
        foreach ($dom->getElementsByTagName('trigger') as $trigger) {
            /** @var \DOMElement $trigger */
            $event = $trigger->getAttribute('event');
            if ($event === '') {
                continue;
            }
            $surface['trigger_events'][$event]
                = $trigger->getAttribute('entity') . '|' . $trigger->getAttribute('group');
        }
    }

    /**
     * @param string[] $tagNames
     * @return \DOMElement[]
     */
    private static function elementsByTag(\DOMDocument $dom, array $tagNames): array
    {
        $elements = [];
        foreach ($tagNames as $tagName) {
            foreach ($dom->getElementsByTagName($tagName) as $element) {
                $elements[] = $element;
            }
        }
        return $elements;
    }

    /**
     * The `<argument name="X" xsi:type="array">` node directly under a type's
     * `<arguments>`, or null.
     */
    private static function findArrayArgument(\DOMElement $type, string $argument): ?\DOMElement
    {
        foreach ($type->getElementsByTagName('argument') as $arg) {
            /** @var \DOMElement $arg */
            if ($arg->getAttribute('name') === $argument
                && $arg->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type') === 'array'
            ) {
                return $arg;
            }
        }
        return null;
    }

    /**
     * Direct `<item name="...">` element children of an array node.
     *
     * @return array<string, \DOMElement> item name => node
     */
    private static function childItems(\DOMElement $arrayNode): array
    {
        $items = [];
        foreach ($arrayNode->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'item') {
                $items[$child->getAttribute('name')] = $child;
            }
        }
        return $items;
    }
}
