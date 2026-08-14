<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Test\Unit\Controller\Adminhtml\Workflow;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use MageOS\WorkflowsAdminUi\Controller\Adminhtml\Template\Install;
use MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow\Delete;
use MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow\DryRun;
use MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow\Edit;
use MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow\Index;
use MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow\MassDelete;
use MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow\MassDisable;
use MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow\MassEnable;
use MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow\NewAction;
use MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow\Run;
use MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow\Save;
use PHPUnit\Framework\TestCase;

/**
 * HTTP-method contract for the admin workflow controllers.
 *
 * The rule: a controller whose execute() fires a side effect unconditionally is
 * POST-only. That is not style. In adminhtml, POST is what routes a request
 * through the CSRF gate —
 * Magento\Backend\App\AbstractAction::_processUrlKeys() validates the form key
 * on every POST from a logged-in admin, and falls back to the secret URL key
 * ONLY on non-POST requests. The secret key is not a CSRF defence: merchants
 * turn it off (Advanced > Admin > Security), and it leaks through Referer
 * headers, browser history and shared screenshots.
 *
 * "Run Now" was the hole. It dispatched a real workflow — refunds, customer
 * emails, outbound webhooks — against an entity id read straight out of a GET
 * URL, so any page a logged-in admin visited could fire it with an <img> tag on
 * a store with the secret key disabled. It is POST now, like every other
 * mutating controller here, and this test is what keeps it that way.
 *
 * Controllers have a heavy Action\Context constructor, so the constants and
 * implemented interfaces are asserted by reflection on the class, never an
 * instance (the posture TemplateAclTest and CanvasAclContractTest document).
 */
class WorkflowActionMethodContractTest extends TestCase
{
    /**
     * Controllers whose execute() mutates or fires side effects with no
     * further method check of their own.
     *
     * @return array<string, string> class => what it does
     */
    private function mutatingControllers(): array
    {
        return [
            Run::class => 'dispatches a live workflow run (refunds, emails, webhooks)',
            Save::class => 'writes a workflow definition',
            Delete::class => 'deletes a workflow',
            MassEnable::class => 'enables workflows in bulk',
            MassDisable::class => 'disables workflows in bulk',
            MassDelete::class => 'deletes workflows in bulk',
        ];
    }

    public function testEveryMutatingControllerIsPostOnly(): void
    {
        foreach ($this->mutatingControllers() as $controller => $what) {
            $this->assertTrue(
                is_a($controller, HttpPostActionInterface::class, true),
                $controller . ' ' . $what . ' — it must declare itself POST-only'
            );
            $this->assertFalse(
                is_a($controller, HttpGetActionInterface::class, true),
                $controller . ' must not also accept GET: a GET skips the admin form-key check '
                . 'and leaves only the secret URL key, which merchants routinely disable'
            );
        }
    }

    /**
     * Nothing in this package may opt out of the form-key check by declaring
     * itself CSRF-aware — that interface exists for storefront endpoints with
     * their own token, not for admin actions.
     */
    public function testNoMutatingControllerOptsOutOfTheCsrfCheck(): void
    {
        foreach (array_keys($this->mutatingControllers()) as $controller) {
            $this->assertFalse(
                is_a($controller, CsrfAwareActionInterface::class, true),
                $controller . ' must not bypass the admin form-key validation'
            );
        }
    }

    public function testRunNowStillRequiresTheDedicatedManualRunResource(): void
    {
        $this->assertSame(
            'MageOS_Workflows::manual_run',
            Run::ADMIN_RESOURCE,
            'Firing a live run is its own grant, deliberately not implied by ::manage'
        );
    }

    /**
     * Read-only pages stay GET; making them POST would break bookmarks and the
     * grid's own links for no security gain.
     */
    public function testReadOnlyPagesStayGet(): void
    {
        foreach ([Index::class, Edit::class, NewAction::class] as $controller) {
            $this->assertTrue(is_a($controller, HttpGetActionInterface::class, true), $controller);
            $this->assertFalse(
                is_a($controller, HttpPostActionInterface::class, true),
                $controller . ' renders a page; it has no write branch to POST to'
            );
        }
    }

    /**
     * Two controllers legitimately answer both verbs — they render a form on
     * GET and act on POST. They are only safe because the write branch is
     * guarded by an explicit isPost() check inside execute(); assert the guard
     * is actually there rather than trusting the docblock.
     */
    public function testDualVerbControllersGuardTheirWriteBranchWithIsPost(): void
    {
        $sources = [
            DryRun::class => 'Controller/Adminhtml/Workflow/DryRun.php',
            Install::class => 'Controller/Adminhtml/Template/Install.php',
        ];

        foreach ($sources as $controller => $relative) {
            $this->assertTrue(is_a($controller, HttpGetActionInterface::class, true), $controller);
            $this->assertTrue(is_a($controller, HttpPostActionInterface::class, true), $controller);

            $path = dirname(__DIR__, 5) . '/' . $relative;
            $this->assertTrue(is_file($path), 'Missing ' . $relative);
            $this->assertStringContainsString(
                'isPost()',
                (string) file_get_contents($path),
                $controller . ' answers GET too, so its write branch must check the verb itself'
            );
        }
    }
}
