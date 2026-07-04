<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Template;

use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;

/**
 * Reads templates from bundled pack modules on disk (discovery §3 G1/G3).
 *
 * Pack modules are registered as a di.xml array argument — the ActionPool
 * type-array pattern — so a third-party pack adds one item naming its module.
 * Each entry is a module name; its `<module>/templates` directory is scanned:
 *
 *   <type name="MageOS\Workflows\Model\Template\BundledTemplateSource">
 *       <arguments>
 *           <argument name="packDirectories" xsi:type="array">
 *               <item name="mageos" xsi:type="string">MageOS_WorkflowsTemplates</item>
 *               <item name="acme"   xsi:type="string">Acme_WorkflowTemplates</item>
 *           </argument>
 *       </arguments>
 *   </type>
 *
 * (Module names rather than raw paths so a pack stays portable — di.xml cannot
 * express a module's absolute install path.) Each *.json file is one template
 * envelope. Scanning is defensive: a file that fails to parse or is missing
 * template.code is skipped rather than breaking the whole gallery — the CI
 * fixture test is the mechanism that keeps a shipped pack honest.
 */
class BundledTemplateSource implements TemplateSourceInterface
{
    private const TEMPLATES_SUBDIR = '/templates';

    /**
     * @var array<string, string>|null code => absolute file path, memoized
     */
    private ?array $pathsByCode = null;

    /**
     * @var array<string, TemplateSummary>|null code => summary, memoized
     */
    private ?array $summaries = null;

    /**
     * @param string[] $packDirectories module names whose `templates/` dir is scanned
     */
    public function __construct(
        private readonly ModuleDirReader $moduleDirReader,
        private readonly array $packDirectories = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function list(): array
    {
        $this->scan();
        return array_values($this->summaries ?? []);
    }

    /**
     * @inheritDoc
     */
    public function get(string $code): string
    {
        $this->scan();
        $path = $this->pathsByCode[$code] ?? null;
        if ($path === null) {
            throw new TemplateNotFoundException(__('No workflow template with code "%1" is available.', $code));
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new TemplateNotFoundException(__('The workflow template "%1" could not be read.', $code));
        }
        return $contents;
    }

    /**
     * @inheritDoc
     */
    public function has(string $code): bool
    {
        $this->scan();
        return isset($this->pathsByCode[$code]);
    }

    /**
     * Walk every pack module's templates directory once and index by code.
     * A code already claimed by an earlier pack wins (first pack wins); a
     * duplicate is skipped rather than silently overriding.
     */
    private function scan(): void
    {
        if ($this->summaries !== null) {
            return;
        }
        $this->summaries = [];
        $this->pathsByCode = [];

        foreach ($this->packDirectories as $module) {
            $directory = $this->resolveDirectory((string) $module);
            if ($directory === null) {
                continue;
            }
            $files = glob($directory . '/*.json') ?: [];
            sort($files);
            foreach ($files as $file) {
                $node = $this->readTemplateNode($file);
                if ($node === null) {
                    continue;
                }
                $code = (string) ($node['code'] ?? '');
                if ($code === '' || isset($this->pathsByCode[$code])) {
                    continue;
                }
                $this->pathsByCode[$code] = $file;
                $this->summaries[$code] = TemplateSummary::fromTemplateNode($node);
            }
        }
    }

    private function resolveDirectory(string $module): ?string
    {
        if ($module === '') {
            return null;
        }
        try {
            $base = rtrim($this->moduleDirReader->getModuleDir(Dir::MODULE_BASE_DIR, $module), '/');
        } catch (\Throwable $e) {
            return null;
        }
        $directory = $base . self::TEMPLATES_SUBDIR;
        return is_dir($directory) ? $directory : null;
    }

    /**
     * @return array<string, mixed>|null the envelope's `template` node, or null when unreadable/invalid
     */
    private function readTemplateNode(string $file): ?array
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            return null;
        }
        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return null;
        }
        $node = $decoded['template'] ?? null;
        return is_array($node) ? $node : null;
    }
}
