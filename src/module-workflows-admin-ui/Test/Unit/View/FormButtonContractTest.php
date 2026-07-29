<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Pins the save path of the workflow edit form (uiComponent form, not a legacy
 * adminhtml form).
 *
 * Magento_Ui/js/form/adapter binds the form's save/reset handlers to the literal
 * selectors '#save' and '#reset' (Magento_Ui/js/form/adapter/buttons is exactly:
 * {'reset': '#reset', 'save': '#save', 'saveAndContinue': '#save_and_continue'}).
 * Those ids are emitted by Magento\Ui\Component\Control\Container, which renders
 * the buttons declared in the form's <settings><buttons>. A button contributed by
 * a Widget\Form\Container block instead carries no id and is never bound — and
 * that base class additionally targeted '#edit_form', a legacy form id a
 * uiComponent form never renders. Both halves of that mistake are asserted
 * against here.
 *
 * The second half of a working save is somewhere to POST: the provider's client
 * submits to the data source's config.submit_url.
 */
class FormButtonContractTest extends TestCase
{
    private const BUTTON_PROVIDER_INTERFACE =
        'Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface';

    private function moduleRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function formXml(): \SimpleXMLElement
    {
        $path = $this->moduleRoot() . '/view/adminhtml/ui_component/mageos_workflows_form.xml';
        $xml = simplexml_load_file($path);
        if ($xml === false) {
            $this->fail('Could not parse mageos_workflows_form.xml');
        }
        return $xml;
    }

    /**
     * @return array<string, string> button name => class
     */
    private function declaredButtons(): array
    {
        $buttons = [];
        foreach ($this->formXml()->settings->buttons->button ?? [] as $button) {
            $buttons[(string) $button['name']] = (string) $button['class'];
        }
        return $buttons;
    }

    public function testFormDeclaresItsButtonsAsUiComponentButtons(): void
    {
        $buttons = $this->declaredButtons();

        $this->assertArrayHasKey(
            'save',
            $buttons,
            'The form must declare a save button in <settings><buttons> — a container-block '
            . 'button carries no #save id and Magento_Ui/js/form/adapter never binds it.'
        );
        $this->assertArrayHasKey('back', $buttons);
        $this->assertArrayHasKey('delete', $buttons);
    }

    public function testEveryDeclaredButtonClassExistsAndIsAButtonProvider(): void
    {
        $buttons = $this->declaredButtons();
        $this->assertTrue($buttons !== [], 'Expected the form to declare buttons.');

        foreach ($buttons as $name => $class) {
            $relative = str_replace('MageOS\\WorkflowsAdminUi\\', '', $class);
            $file = $this->moduleRoot() . '/' . str_replace('\\', '/', $relative) . '.php';

            $this->assertTrue(
                is_file($file),
                sprintf('Button "%s" declares %s but %s does not exist.', $name, $class, $file)
            );

            $source = (string) file_get_contents($file);
            $this->assertStringContainsString(
                'ButtonProviderInterface',
                $source,
                sprintf('Button class %s must implement %s.', $class, self::BUTTON_PROVIDER_INTERFACE)
            );
            $this->assertStringContainsString(
                'function getButtonData',
                $source,
                sprintf('Button class %s must define getButtonData().', $class)
            );
        }
    }

    public function testSaveButtonDoesNotTargetTheLegacyEditFormId(): void
    {
        $saveButton = $this->moduleRoot() . '/Block/Adminhtml/Workflow/Edit/SaveButton.php';
        $this->assertTrue(is_file($saveButton), 'SaveButton is missing.');

        $source = (string) file_get_contents($saveButton);

        $this->assertStringContainsString(
            "'event' => 'save'",
            $source,
            'The save button must raise the form save event.'
        );
        $this->assertStringNotContainsString(
            '#edit_form',
            $source,
            "A uiComponent form never renders '#edit_form'; targeting it is what made Save a no-op."
        );
    }

    public function testFormDataSourceDeclaresASubmitUrl(): void
    {
        $source = (string) file_get_contents(
            $this->moduleRoot() . '/view/adminhtml/ui_component/mageos_workflows_form.xml'
        );

        $this->assertStringContainsString(
            'name="submit_url"',
            $source,
            'Without config.submit_url the form validates and then posts nowhere.'
        );
        $this->assertStringContainsString(
            'mageos_workflows/workflow/save',
            $source,
            'submit_url must point at the Save controller.'
        );
    }

    /**
     * The layout must not reintroduce a legacy container block alongside the
     * uiComponent form: it would render a second, idless button set.
     */
    public function testEditLayoutRendersOnlyTheUiComponentForm(): void
    {
        $layout = (string) file_get_contents(
            $this->moduleRoot() . '/view/adminhtml/layout/mageos_workflows_workflow_edit.xml'
        );

        $this->assertStringContainsString('<uiComponent name="mageos_workflows_form"/>', $layout);
        $this->assertStringNotContainsString(
            'Block\Adminhtml\Workflow\Edit"',
            $layout,
            'The Widget\Form\Container block must stay out of this layout.'
        );
    }
}
