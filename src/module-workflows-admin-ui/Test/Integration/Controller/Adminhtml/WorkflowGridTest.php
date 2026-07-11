<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Test\Integration\Controller\Adminhtml;

use Magento\TestFramework\TestCase\AbstractBackendController;

/**
 * Plan #25 (docs/20-integration-test-plan.md §6): the workflow grid page
 * renders under its real ACL resource, layout handle, and routing. ACL
 * has-access / no-access come from AbstractBackendController via $uri/$resource.
 *
 * @magentoAppArea adminhtml
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class WorkflowGridTest extends AbstractBackendController
{
    /**
     * @var string
     */
    protected $uri = 'backend/mageos_workflows/workflow/index';

    /**
     * @var string
     */
    protected $resource = 'MageOS_Workflows::view';

    public function testGridPageRendersListing(): void
    {
        $this->dispatch($this->uri);

        $this->assertSame(200, $this->getResponse()->getHttpResponseCode());
        $body = (string) $this->getResponse()->getBody();
        $this->assertStringContainsString(
            'mageos_workflows_listing',
            $body,
            'The grid page must mount the workflow listing ui component (layout handle wired)'
        );
    }
}
