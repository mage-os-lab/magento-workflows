<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Test\Unit\Block\Adminhtml\Workflow\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\UrlInterface;
use MageOS\WorkflowsAdminUi\Block\Adminhtml\Workflow\Edit\VisualEditorButton;
use PHPUnit\Framework\TestCase;

/**
 * The form's visual-editor entry point (work package P1).
 *
 * Two axes decide what this button is: whether the optional canvas module is
 * enabled at all (admin-ui never depends on it, so presence is a Module\Manager
 * probe), and whether the admin may author (::manage).
 *
 * On a SAVED workflow both roles get a button — managers the editor, ::view-only
 * admins the read-only viewer. On the NEW-workflow form the button used to
 * vanish entirely; it now offers canvas-first creation to managers, linking to
 * the editor with NO workflow_id (the canvas bootstraps a blank workflow and the
 * admin Save controller creates the record). A ::view-only admin still gets
 * nothing there: an unsaved workflow has nothing to view.
 */
class VisualEditorButtonTest extends TestCase
{
    private const CANVAS_EDIT = 'mageos_workflows_canvas/canvas/edit';
    private const CANVAS_VIEW = 'mageos_workflows_canvas/canvas/view';

    public function testNewFormOffersCreationToManagers(): void
    {
        $data = $this->button(null, true, true)->getButtonData();

        $this->assertSame('Create in visual editor', (string) $data['label']);
        $this->assertStringContainsString(self::CANVAS_EDIT, $data['on_click']);
        $this->assertStringNotContainsString(
            'workflow_id',
            $data['on_click'],
            'There is no id yet — the canvas must open in create mode'
        );
        $this->assertSame('action-secondary', $data['class']);
        $this->assertSame(50, $data['sort_order']);
    }

    public function testNewFormOffersNothingToViewOnlyAdmins(): void
    {
        $this->assertSame([], $this->button(null, false, true)->getButtonData());
    }

    public function testNewFormOffersNothingWhenTheCanvasIsDisabled(): void
    {
        $this->assertSame([], $this->button(null, true, false)->getButtonData());
    }

    public function testSavedWorkflowStillOpensTheEditorForManagers(): void
    {
        $data = $this->button(7, true, true)->getButtonData();

        $this->assertSame('Open in visual editor', (string) $data['label']);
        $this->assertStringContainsString(self::CANVAS_EDIT . '?workflow_id=7', $data['on_click']);
    }

    public function testSavedWorkflowStillOpensTheViewerForViewOnlyAdmins(): void
    {
        $data = $this->button(7, false, true)->getButtonData();

        $this->assertSame('Open in visual editor', (string) $data['label']);
        $this->assertStringContainsString(self::CANVAS_VIEW . '?workflow_id=7', $data['on_click']);
    }

    public function testSavedWorkflowOffersNothingWhenTheCanvasIsDisabled(): void
    {
        $this->assertSame([], $this->button(7, true, false)->getButtonData());
    }

    private function button(?int $workflowId, bool $canManage, bool $canvasEnabled): VisualEditorButton
    {
        return new VisualEditorButton(
            $this->context($workflowId, $canManage),
            $this->moduleManager($canvasEnabled)
        );
    }

    private function context(?int $workflowId, bool $canManage): Context
    {
        return new class ($workflowId, $canManage) extends Context {
            public function __construct(
                private readonly ?int $workflowId,
                private readonly bool $canManage
            ) {
                // No parent call: the real widget context assembles the whole
                // layout/session stack, none of which a button provider touches.
            }

            public function getRequest()
            {
                return new class ($this->workflowId) implements RequestInterface {
                    public function __construct(private readonly ?int $workflowId)
                    {
                    }

                    public function getParam($key, $default = null)
                    {
                        return $key === 'workflow_id' ? $this->workflowId : $default;
                    }

                    public function getModuleName()
                    {
                        return '';
                    }

                    public function setModuleName($name)
                    {
                        return $this;
                    }

                    public function getActionName()
                    {
                        return '';
                    }

                    public function setActionName($name)
                    {
                        return $this;
                    }

                    public function setParams(array $params)
                    {
                        return $this;
                    }

                    public function getParams()
                    {
                        return [];
                    }

                    public function getCookie($name, $default)
                    {
                        return $default;
                    }

                    public function isSecure()
                    {
                        return false;
                    }
                };
            }

            public function getUrlBuilder()
            {
                return new class implements UrlInterface {
                    public function getUrl($routePath = null, $routeParams = null)
                    {
                        $url = (string) $routePath;
                        if (is_array($routeParams) && $routeParams !== []) {
                            $url .= '?' . http_build_query($routeParams);
                        }
                        return $url;
                    }

                    // @codingStandardsIgnoreStart -- inert interface-completeness
                    // stubs so the double loads against the full UrlInterface on a
                    // real install (mirrors admin-extension's RecordingUrlBuilder).
                    public function getUseSession()
                    {
                        throw new \BadMethodCallException(__METHOD__);
                    }

                    public function getBaseUrl($params = [])
                    {
                        throw new \BadMethodCallException(__METHOD__);
                    }

                    public function getCurrentUrl()
                    {
                        throw new \BadMethodCallException(__METHOD__);
                    }

                    public function getRouteUrl($routePath = null, $routeParams = null)
                    {
                        throw new \BadMethodCallException(__METHOD__);
                    }

                    public function addSessionParam()
                    {
                        throw new \BadMethodCallException(__METHOD__);
                    }

                    public function addQueryParams(array $data)
                    {
                        throw new \BadMethodCallException(__METHOD__);
                    }

                    public function setQueryParam($key, $data)
                    {
                        throw new \BadMethodCallException(__METHOD__);
                    }

                    public function escape($value)
                    {
                        throw new \BadMethodCallException(__METHOD__);
                    }

                    public function getDirectUrl($url, $params = [])
                    {
                        throw new \BadMethodCallException(__METHOD__);
                    }

                    public function sessionUrlVar($html)
                    {
                        throw new \BadMethodCallException(__METHOD__);
                    }

                    public function isOwnOriginUrl()
                    {
                        throw new \BadMethodCallException(__METHOD__);
                    }

                    public function getRedirectUrl($url)
                    {
                        throw new \BadMethodCallException(__METHOD__);
                    }

                    public function setScope($params)
                    {
                        throw new \BadMethodCallException(__METHOD__);
                    }
                    // @codingStandardsIgnoreEnd
                };
            }

            public function getAuthorization()
            {
                return new class ($this->canManage) implements AuthorizationInterface {
                    public function __construct(private readonly bool $canManage)
                    {
                    }

                    public function isAllowed($resource, $privilege = null)
                    {
                        return $resource === 'MageOS_Workflows::manage' && $this->canManage;
                    }
                };
            }
        };
    }

    private function moduleManager(bool $canvasEnabled): ModuleManager
    {
        return new class ($canvasEnabled) extends ModuleManager {
            public function __construct(private readonly bool $canvasEnabled)
            {
            }

            public function isEnabled($moduleName)
            {
                return $moduleName === 'MageOS_WorkflowsCanvas' && $this->canvasEnabled;
            }
        };
    }
}
