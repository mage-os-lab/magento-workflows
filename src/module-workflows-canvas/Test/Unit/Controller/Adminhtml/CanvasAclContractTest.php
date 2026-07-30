<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCanvas\Test\Unit\Controller\Adminhtml;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use MageOS\WorkflowsCanvas\Controller\Adminhtml\Canvas\Edit;
use MageOS\WorkflowsCanvas\Controller\Adminhtml\Canvas\View;
use MageOS\WorkflowsCanvas\Controller\Adminhtml\Data\DryRun;
use MageOS\WorkflowsCanvas\Controller\Adminhtml\Data\ExecutionSteps;
use MageOS\WorkflowsCanvas\Controller\Adminhtml\Data\Validate;
use PHPUnit\Framework\TestCase;

/**
 * ACL + HTTP-method contract for the canvas admin surface (implementation plan
 * 07). The server is the sole authority: read surfaces are gated by ::view,
 * every WRITE / editing surface by ::manage, and dry-run by its dedicated
 * ::dry_run resource. The validate proxy is POST (so the admin router enforces
 * the form key) and ::manage, matching the save path the canvas ultimately
 * posts through. Controllers have a heavy Action\Context constructor, so the
 * ADMIN_RESOURCE constant + implemented HTTP interface are asserted by
 * reflection on the class, not an instance.
 *
 * The option-source feed is NOT listed here: the canvas config panel and the
 * template install form consume the same endpoint, so it lives in admin-ui
 * (mageos_workflows/data/options) and its contract is pinned by that module's
 * OptionsAclContractTest.
 */
class CanvasAclContractTest extends TestCase
{
    public function testViewerAndReadDataAreGatedByView(): void
    {
        foreach ([View::class, ExecutionSteps::class] as $controller) {
            $this->assertSame(
                'MageOS_Workflows::view',
                $controller::ADMIN_RESOURCE,
                $controller . ' must be gated by the view resource'
            );
        }
    }

    public function testEditorAndValidateProxyAreGatedByManage(): void
    {
        foreach ([Edit::class, Validate::class] as $controller) {
            $this->assertSame(
                'MageOS_Workflows::manage',
                $controller::ADMIN_RESOURCE,
                $controller . ' (a write/editing surface) must be gated by the manage resource'
            );
        }
    }

    public function testDryRunIsGatedByDryRun(): void
    {
        $this->assertSame('MageOS_Workflows::dry_run', DryRun::ADMIN_RESOURCE);
    }

    public function testValidateProxyIsPostOnly(): void
    {
        $this->assertTrue(
            is_a(Validate::class, HttpPostActionInterface::class, true),
            'The validate proxy must be POST so the admin router enforces the form key'
        );
    }

    public function testEditorIsGetOnly(): void
    {
        $this->assertTrue(is_a(Edit::class, HttpGetActionInterface::class, true));
    }
}
