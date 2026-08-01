<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\View;

use MageOS\Workflows\Test\Unit\PackageLocator;
use PHPUnit\Framework\TestCase;

/**
 * No template embedded in a ui_component via <htmlContent> may contain an inline
 * script element.
 *
 * Magento\Ui\Component\HtmlContent renders the referenced block and carries its
 * HTML as a JSON *string* inside the parent component's
 * <script type="text/x-magento-init"> element. The HTML parser closes a script
 * element at the first closing script tag it encounters, wherever it appears —
 * including inside a JSON string literal. So one inline script in an embedded
 * block truncates the outer tag: the remainder of the component config spills
 * into the document as inert text, Magento_Ui/js/core/app never receives a valid
 * config, and the ENTIRE form fails to initialize.
 *
 * The symptom is maximally unhelpful: a full-page loading mask, not one field
 * rendered, no failed request, and typically no console error at all — the
 * truncated remnant is text, not broken JavaScript. It cost a long debugging
 * session on the workflow edit form, hence this guard.
 *
 * The fix is always the same: move the behaviour into a RequireJS module under
 * view/<area>/web/js/, register it in requirejs-config.js, and boot it from a
 * data-mage-init attribute (which is also the module's stated CSP posture).
 */
class HtmlContentTemplateContractTest extends TestCase
{
    private function relative(string $path): string
    {
        return PackageLocator::relative($path);
    }

    /**
     * Magento module name => absolute module directory.
     *
     * Discovery goes through PackageLocator, so the sibling packages resolve in
     * BOTH layouts — <repo>/src/module-* and, in CI's real Magento install,
     * <magento>/vendor/mage-os/workflows* — and an empty scan throws instead of
     * quietly reducing every guard below to a no-op.
     *
     * @return array<string, string>
     */
    private function moduleDirectories(): array
    {
        return PackageLocator::packageRoots();
    }

    /**
     * Block classes referenced by <htmlContent> nodes across every ui_component.
     *
     * @return array<int, array{class: string, component: string}>
     */
    private function htmlContentBlocks(): array
    {
        $blocks = [];
        foreach (PackageLocator::globInPackages('view/*/ui_component/*.xml') as $file) {
            $xml = simplexml_load_file($file);
            if ($xml === false) {
                continue;
            }
            foreach ($xml->xpath('//htmlContent/argument[@name="block"]') ?: [] as $argument) {
                $class = trim((string) $argument);
                if ($class !== '') {
                    $blocks[] = ['class' => $class, 'component' => $this->relative($file)];
                }
            }
        }
        return $blocks;
    }

    /**
     * Resolves a block class to the phtml its $_template points at.
     */
    private function templatePath(string $class): ?string
    {
        $modules = $this->moduleDirectories();

        // MageOS\WorkflowsAdminUi\Block\... => MageOS_WorkflowsAdminUi
        $parts = explode('\\', $class);
        if (count($parts) < 2) {
            return null;
        }
        $moduleName = $parts[0] . '_' . $parts[1];
        if (!isset($modules[$moduleName])) {
            return null;
        }

        $classFile = $modules[$moduleName] . '/'
            . str_replace('\\', '/', implode('\\', array_slice($parts, 2))) . '.php';
        if (!is_file($classFile)) {
            return null;
        }

        $source = (string) file_get_contents($classFile);
        if (!preg_match('/_template\s*=\s*[\'"]([^\'"]+)[\'"]/', $source, $m)) {
            return null;
        }

        // 'MageOS_WorkflowsAdminUi::workflow/definition_preview.phtml'
        $parts = explode('::', $m[1]);
        if (count($parts) !== 2 || !isset($modules[$parts[0]])) {
            return null;
        }

        return $modules[$parts[0]] . '/view/adminhtml/templates/' . $parts[1];
    }

    public function testEveryHtmlContentBlockResolvesToATemplate(): void
    {
        $blocks = $this->htmlContentBlocks();
        $this->assertTrue($blocks !== [], 'Expected at least one <htmlContent> block.');

        $unresolved = [];
        foreach ($blocks as $block) {
            $path = $this->templatePath($block['class']);
            if ($path === null || !is_file($path)) {
                $unresolved[] = $block['component'] . ' → ' . $block['class'];
            }
        }

        $this->assertSame([], $unresolved, "Unresolvable <htmlContent> blocks:\n  " . implode("\n  ", $unresolved));
    }

    public function testNoHtmlContentTemplateContainsAnInlineScript(): void
    {
        $offenders = [];

        foreach ($this->htmlContentBlocks() as $block) {
            $path = $this->templatePath($block['class']);
            if ($path === null || !is_file($path)) {
                continue;
            }

            $source = (string) file_get_contents($path);

            // Strip the leading PHP docblock so the explanatory comment in these
            // templates (which necessarily describes the hazard) is not a match.
            $body = preg_replace('/^<\?php.*?\?>/s', '', $source) ?? $source;

            if (stripos($body, '</script') !== false || preg_match('/<script[\s>]/i', $body) === 1) {
                $offenders[] = sprintf(
                    '%s (embedded by %s)',
                    $this->relative($path),
                    $block['component']
                );
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Inline script in a template embedded via <htmlContent>. The closing tag truncates "
            . "the parent component's text/x-magento-init element and the whole form silently "
            . "fails to initialize — full-page mask, no fields, no console error. Move the JS "
            . "to a RequireJS module and boot it with data-mage-init:\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * The replacement module must actually be registered, or the panel renders
     * inert markup and the failure just moves.
     */
    public function testDefinitionPreviewBootsARegisteredRequireJsModule(): void
    {
        $modules = $this->moduleDirectories();
        $this->assertArrayHasKey('MageOS_WorkflowsAdminUi', $modules);

        $adminUi = $modules['MageOS_WorkflowsAdminUi'];

        $template = (string) file_get_contents(
            $adminUi . '/view/adminhtml/templates/workflow/definition_preview.phtml'
        );
        $this->assertStringContainsString(
            'mageosWorkflowsDefinitionPreview',
            $template,
            'The preview panel must boot its module via data-mage-init.'
        );

        $requireConfig = (string) file_get_contents($adminUi . '/view/adminhtml/requirejs-config.js');
        $this->assertStringContainsString(
            'mageosWorkflowsDefinitionPreview',
            $requireConfig,
            'The module alias must be mapped in requirejs-config.js.'
        );

        $this->assertTrue(
            is_file($adminUi . '/view/adminhtml/web/js/definition-preview.js'),
            'The mapped RequireJS module file must exist under view/adminhtml/web/js/.'
        );
    }
}
