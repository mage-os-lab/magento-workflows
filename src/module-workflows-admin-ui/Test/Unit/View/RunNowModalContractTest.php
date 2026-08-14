<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Test\Unit\View;

use MageOS\WorkflowsAdminUi\Block\Adminhtml\Workflow\RunNowModal;
use PHPUnit\Framework\TestCase;

/**
 * Structural contract for the "Run Now" modal — the half of the feature that
 * lives in a .phtml, a RequireJS module and a button's on_click string, and so
 * in no ordinary unit test.
 *
 * The bug class this kills is a broken hand-off: the toolbar button raises one
 * custom event on one element id, and three files have to agree on it (the
 * button, the container the template renders, the module that listens). If any
 * of them drifts the button silently does nothing — exactly the failure mode
 * the window.prompt it replaced could not have.
 */
class RunNowModalContractTest extends TestCase
{
    private const OPEN_EVENT = 'mageos:open-run-now';

    private function moduleRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function read(string $relative): string
    {
        $path = $this->moduleRoot() . '/' . $relative;
        $this->assertTrue(is_file($path), 'Missing ' . $relative);

        return (string) file_get_contents($path);
    }

    private function templateSource(): string
    {
        return $this->read('view/adminhtml/templates/workflow/run-now-modal.phtml');
    }

    public function testTheButtonNoLongerPromptsAndOnlyOpensTheModal(): void
    {
        $source = $this->read('Block/Adminhtml/Workflow/Edit/RunNowButton.php');

        $this->assertStringNotContainsString(
            'window.prompt(',
            $source,
            'The entity id is asked for by the modal picker now, not a browser prompt.'
        );
        $this->assertStringNotContainsString(
            'location.href',
            $source,
            'Building the run URL is the JS module\'s job; the on_click only opens the modal.'
        );
        $this->assertStringContainsString(self::OPEN_EVENT, $source);
        $this->assertStringContainsString(
            'RunNowModal::CONTAINER_ID',
            $source,
            'The button must target the container id the modal block declares, not a copy of it.'
        );
    }

    /**
     * Both halves render, or neither does: a button whose modal was gated away
     * would be a dead control.
     */
    public function testTheButtonAndTheModalShareTheSameGates(): void
    {
        $button = $this->read('Block/Adminhtml/Workflow/Edit/RunNowButton.php');

        $this->assertStringContainsString('getWorkflowId() === null', $button);
        $this->assertStringContainsString('RunNowModal::ACL_MANUAL_RUN', $button);
        $this->assertSame(
            'MageOS_Workflows::manual_run',
            RunNowModal::ACL_MANUAL_RUN,
            'The manual-run resource is the one the Run controller enforces.'
        );
        $this->assertStringContainsString(
            '$block->canRun()',
            $this->templateSource(),
            'The template must render nothing when the block reports it cannot run.'
        );
    }

    public function testTheContainerCarriesTheIdTheEventIsTriggeredOn(): void
    {
        $source = $this->templateSource();

        $this->assertStringContainsString('RunNowModal::CONTAINER_ID', $source);
        $this->assertStringContainsString('id="<?= $block->escapeHtmlAttr($containerId) ?>"', $source);
        $this->assertStringContainsString(
            self::OPEN_EVENT,
            $this->read('view/adminhtml/web/js/run-now-modal.js'),
            'The module must listen for the event the button raises.'
        );
    }

    public function testThePickerRendersTheRealInputAndOnlyOffersRecentWhenThereAreAny(): void
    {
        $source = $this->templateSource();

        $this->assertStringContainsString('data-role="run-now-entity-id"', $source);
        $this->assertStringContainsString(
            '<?php if ($recent !== []): ?>',
            $source,
            'An empty recent list must not render a select with nothing in it.'
        );
        $this->assertStringContainsString('data-role="run-now-recent"', $source);
        $this->assertStringContainsString(
            'data-run-url="<?= $block->escapeUrl($block->getRunUrl()) ?>"',
            $source,
            'The run URL reaches the module through a data attribute, never an inline script.'
        );
    }

    /**
     * The picker starts hidden: the modal widget only takes the container over
     * once RequireJS has booted, and an un-hidden container would flash the
     * form's own content on every page load.
     */
    public function testTheContainerIsHiddenUntilTheModalTakesItOver(): void
    {
        $this->assertStringContainsString('style="display:none"', $this->templateSource());
    }

    public function testTheTemplateContainsNoInlineScript(): void
    {
        $this->assertStringNotContainsString(
            '<script',
            $this->templateSource(),
            'The modal boots through data-mage-init and RequireJS (CSP).'
        );
    }

    /**
     * Every user-facing string is translated server-side into a data-*
     * attribute (the param-search-select precedent), so the module needs no
     * mage/translate and there is one translation pass, not two.
     */
    public function testEveryModalStringIsTranslatedServerSide(): void
    {
        $source = $this->templateSource();

        foreach (['data-title', 'data-label-run', 'data-label-cancel', 'data-label-invalid'] as $attribute) {
            $this->assertStringContainsString($attribute . '="<?= $block->escapeHtmlAttr(__(', $source, $attribute);
        }
        $this->assertStringNotContainsString(
            "'mage/translate'",
            $this->read('view/adminhtml/web/js/run-now-modal.js'),
            'Strings arrive already translated in data-* attributes.'
        );
    }

    public function testTheModuleIsMappedAndPostsTheEntityId(): void
    {
        $this->assertStringContainsString('mageosWorkflowsRunNow', $this->templateSource());
        $this->assertStringContainsString(
            'mageosWorkflowsRunNow:',
            $this->read('view/adminhtml/requirejs-config.js'),
            'data-mage-init resolves nothing unless the widget is in the RequireJS map.'
        );

        $js = $this->read('view/adminhtml/web/js/run-now-modal.js');
        $this->assertStringContainsString(
            "'mage/utils/misc'",
            $js,
            'The POST is built by the canonical admin helper, which stamps window.FORM_KEY into it.'
        );
        $this->assertStringContainsString('miscUtils.submit({', $js);
        $this->assertStringContainsString("'entity_id': entityId", $js);
    }

    /**
     * The regression this pins: "Run Now" used to be a GET — the module appended
     * 'entity_id/<n>/' to the run URL and assigned window.location. Dispatching
     * refunds/emails/webhooks off a URL load is a CSRF hole whenever the
     * adminhtml secret key is switched off, which merchants do routinely. The
     * only thing standing between a state change and a drive-by request is that
     * this stays a POST, so no navigation may creep back in.
     */
    public function testTheModuleNeverNavigatesToTheRunUrl(): void
    {
        $js = $this->read('view/adminhtml/web/js/run-now-modal.js');

        $this->assertStringNotContainsString(
            'window.location',
            $js,
            'Running a workflow is a state change; it must be POSTed, never navigated to.'
        );
        $this->assertStringNotContainsString(
            "runUrl + 'entity_id/",
            $js,
            'The entity id travels in the POST body now, not as a URL path segment.'
        );
    }

    public function testTheEditLayoutRendersTheModalBlock(): void
    {
        $layout = $this->read('view/adminhtml/layout/mageos_workflows_workflow_edit.xml');

        $this->assertStringContainsString('Block\Adminhtml\Workflow\RunNowModal', $layout);
        $this->assertStringContainsString('workflow/run-now-modal.phtml', $layout);
    }
}
