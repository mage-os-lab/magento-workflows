<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Workflow;

use Magento\Backend\Block\Widget\Container;
use Magento\Backend\Block\Widget\Context;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Module\Manager as ModuleManager;

/**
 * Page container for the workflow grid: header text + the New Workflow
 * buttons. Deliberately NOT Widget\Grid\Container — that base class
 * synthesizes a legacy "{blockGroup}\Block\{controller}\Grid" child by
 * convention, and this page renders the mageos_workflows_listing
 * uiComponent instead; the phantom class made the whole page throw
 * "Invalid block type" on a real install (caught by the integration
 * lane's controller suite).
 *
 * The second, visual entry point appears only when the optional canvas module
 * is enabled and the admin may author (::manage) — admin-ui never depends on
 * canvas, so its presence is probed through the framework's Module\Manager, the
 * same way VisualEditorButton does it on the form.
 */
class Index extends Container
{
    public function __construct(
        Context $context,
        private readonly ModuleManager $moduleManager,
        private readonly AuthorizationInterface $authorization,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _construct(): void
    {
        $this->_headerText = __('Workflows');
        parent::_construct();
        $this->buttonList->add(
            'add',
            [
                'label' => __('New Workflow'),
                'onclick' => sprintf("setLocation('%s')", $this->getUrl('*/*/new')),
                'class' => 'add primary',
            ]
        );

        if ($this->moduleManager->isEnabled('MageOS_WorkflowsCanvas')
            && $this->authorization->isAllowed('MageOS_Workflows::manage')
        ) {
            $this->buttonList->add(
                'add_visual',
                [
                    'label' => __('New Workflow (Visual)'),
                    'onclick' => sprintf(
                        "setLocation('%s')",
                        $this->getUrl('mageos_workflows_canvas/canvas/edit')
                    ),
                    'class' => 'add',
                ]
            );
        }
    }
}
