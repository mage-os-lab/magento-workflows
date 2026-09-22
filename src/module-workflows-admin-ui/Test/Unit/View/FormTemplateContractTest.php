<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Pins the form-template ↔ layout-builder pairing of the workflow edit form.
 *
 * A ui-component form has two render halves that MUST agree:
 *
 *  - the .xhtml form template, which hard-codes the knockout scope (and the
 *    loading-mask spinner's data-component) it binds the form body to, and
 *  - the layout builder (Structure::generate), which decides which component
 *    names actually get registered client-side.
 *
 * The default template (templates/form/default) binds "{name}.areas" — a
 *  component that only the TABS layout builder creates (Tabs::initAreas).
 * The collapsible template (templates/form/collapsible) binds
 *  "{name}.{name}" — the node the GENERIC layout (the fallback when no
 *  <settings><layout> is declared) creates.
 *
 * Mismatching them (default template + generic layout) fails silently and
 * totally: the scope binding waits forever for a component that never
 * registers, so not a single field renders; form.js hides the spinner via
 * loader.get(this.name), whose selector doesn't match the ".areas"-suffixed
 * spinner element, so the loading mask never goes away; and because nothing
 * errors, the console and the network tab are both clean. That was the
 * edit form's "infinite loader, buttons but no fields" bug.
 *
 * This form uses the cms_page_form pattern: collapsible template, no layout
 * declaration. If someone reintroduces the default template, they must also
 * declare <layout><type>tabs</type></layout> — and vice versa.
 */
class FormTemplateContractTest extends TestCase
{
    private function moduleRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function formXmlSource(): string
    {
        return (string) file_get_contents(
            $this->moduleRoot() . '/view/adminhtml/ui_component/mageos_workflows_form.xml'
        );
    }

    public function testFormTemplateMatchesItsLayoutBuilder(): void
    {
        $source = $this->formXmlSource();

        $declaresCollapsibleTemplate = str_contains($source, 'templates/form/collapsible');
        $declaresTabsLayout = (bool) preg_match('#<layout>.*<type>tabs</type>.*</layout>#s', $source);

        $this->assertTrue(
            $declaresCollapsibleTemplate || $declaresTabsLayout,
            'The form declares neither the collapsible template nor a tabs layout. '
            . 'The default form template binds "{name}.areas", which only the tabs '
            . 'layout builder registers — without one of the two the form renders '
            . 'an infinite loading mask and zero fields, with no console error.'
        );

        if ($declaresCollapsibleTemplate) {
            $this->assertFalse(
                $declaresTabsLayout,
                'Declare the collapsible template OR a tabs layout, not both: the '
                . 'collapsible template binds "{name}.{name}", which the tabs '
                . 'builder does not register at that name.'
            );
        }
    }

    public function testCollapsibleTemplateIsDeclaredInTheDataArgument(): void
    {
        $xml = simplexml_load_file(
            $this->moduleRoot() . '/view/adminhtml/ui_component/mageos_workflows_form.xml'
        );
        if ($xml === false) {
            $this->fail('Could not parse mageos_workflows_form.xml');
        }

        $template = null;
        foreach ($xml->argument as $argument) {
            if ((string) $argument['name'] !== 'data') {
                continue;
            }
            foreach ($argument->item as $item) {
                if ((string) $item['name'] === 'template') {
                    $template = (string) $item;
                }
            }
        }

        $this->assertSame(
            'templates/form/collapsible',
            $template,
            'The form must declare the collapsible form template in its data '
            . 'argument (cms_page_form pattern) so the generic layout builder '
            . 'and the template agree on the knockout scope name.'
        );
    }
}
