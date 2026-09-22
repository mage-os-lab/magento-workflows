<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Test\Unit\View;

use MageOS\WorkflowsAdminUi\Block\Adminhtml\Template\Install;
use PHPUnit\Framework\TestCase;

/**
 * Structural contract for the install form template (issue #27), the half of
 * the widget mapping that lives in a .phtml and therefore in no unit test:
 * every widget the block can return must have its OWN branch in the template.
 *
 * The bug class this kills is silent fall-through. The template used to end in
 * a bare `else` rendering a text input, so a widget the block learned to return
 * — a number, a duration composite — rendered as a plain text box with no
 * error anywhere: the form still worked, it just quietly ignored the mapping.
 * A widget constant with no matching branch token fails here instead.
 *
 * The no-inline-script assertion pins the CSP posture the two enhanced widgets
 * depend on: both boot through data-mage-init and RequireJS, never a <script>.
 */
class InstallTemplateContractTest extends TestCase
{
    private function templateSource(): string
    {
        $path = dirname(__DIR__, 3) . '/view/adminhtml/templates/template/install.phtml';
        $this->assertTrue(is_file($path), 'The install form template is missing: ' . $path);

        return (string) file_get_contents($path);
    }

    /**
     * @return array<string, string> constant name => widget value
     */
    private function widgetConstants(): array
    {
        $constants = [];
        foreach ((new \ReflectionClass(Install::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'WIDGET_')) {
                $constants[$name] = (string) $value;
            }
        }
        return $constants;
    }

    public function testEveryWidgetTheBlockCanReturnHasItsOwnBranch(): void
    {
        $constants = $this->widgetConstants();
        $this->assertTrue(
            count($constants) >= 7,
            'Expected the block to declare the full widget set; finding fewer means the '
            . 'constant scan broke, not that the template is complete.'
        );

        $source = $this->templateSource();
        $missing = [];
        foreach ($constants as $name => $value) {
            if (!str_contains($source, "\$widget === '" . $value . "'")) {
                $missing[] = sprintf('%s (%s)', $name, $value);
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Install::widget() can return these, and install.phtml renders no branch for them:\n  "
            . implode("\n  ", $missing)
        );
    }

    /**
     * The corollary: no `else` catch-all, so an unbranched widget renders
     * nothing visible rather than a text input that looks correct.
     */
    public function testTheBranchChainHasNoCatchAllElse(): void
    {
        $this->assertStringNotContainsString(
            '<?php else: ?>',
            $this->templateSource(),
            'A catch-all else re-hides the fall-through the branch test exists to catch.'
        );
    }

    public function testTheEnhancedWidgetsBootDeclaratively(): void
    {
        $source = $this->templateSource();

        $this->assertStringContainsString('mageosWorkflowsParamSearch', $source);
        $this->assertStringContainsString('mageosWorkflowsParamDuration', $source);
        $this->assertStringContainsString('data-mage-init', $source);
    }

    /**
     * Both enhanced widgets progressively enhance an input that is ALWAYS
     * rendered: with JavaScript off, or when the options feed is unreachable,
     * the operator still has the field the form posts.
     */
    public function testTheEnhancedWidgetsStillRenderTheRealInput(): void
    {
        $source = $this->templateSource();

        $this->assertSame(
            2,
            substr_count($source, 'class="mageos-param-search"') + substr_count($source, 'class="mageos-param-duration"'),
            'Expected exactly one container for each enhanced widget.'
        );
        $this->assertTrue(
            substr_count($source, 'name="param[<?= $block->escapeHtmlAttr($key) ?>]"') >= 2,
            'Each enhanced widget must still render the real param[<key>] input inside its container.'
        );
    }

    public function testTheTemplateContainsNoInlineScript(): void
    {
        $this->assertStringNotContainsString(
            '<script',
            $this->templateSource(),
            'The install form must stay inline-script free: its widgets boot through '
            . 'data-mage-init and RequireJS (CSP).'
        );
    }

    /**
     * Every module JS the template boots must be registered in the RequireJS
     * map, or data-mage-init resolves nothing and the field silently stays
     * plain — the exact failure the fall-through test guards on the PHP side.
     */
    public function testEveryBootedWidgetIsMappedToAShippedModule(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $requireJsConfig = (string) file_get_contents($moduleRoot . '/view/adminhtml/requirejs-config.js');

        $widgets = [
            'mageosWorkflowsParamSearch' => 'js/param-search-select.js',
            'mageosWorkflowsParamDuration' => 'js/param-duration.js',
        ];
        foreach ($widgets as $widget => $file) {
            $this->assertStringContainsString(
                $widget . ':',
                $requireJsConfig,
                $widget . ' is booted by install.phtml but is not in the RequireJS map.'
            );
            $this->assertTrue(
                is_file($moduleRoot . '/view/adminhtml/web/' . $file),
                'Missing ' . $file . ' for widget ' . $widget . '.'
            );
        }
    }
}
