<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Workflow\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * Primary save. `data_attribute.mage-init.button.event = save` is what makes the
 * click reach Magento_Ui/js/form/form::save() for a uiComponent form. Deliberately
 * no `target`: the previous container-block button pointed at a legacy adminhtml
 * form id that a uiComponent form never renders, so nothing was ever triggered.
 *
 * The actual POST target is the form's <submitUrl> (mageos_workflows_form.xml
 * dataSource settings), not this button.
 */
class SaveButton extends GenericButton implements ButtonProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        return [
            'label' => __('Save Workflow'),
            'class' => 'save primary',
            'data_attribute' => [
                'mage-init' => ['button' => ['event' => 'save']],
                'form-role' => 'save',
            ],
            'sort_order' => 90,
        ];
    }
}
