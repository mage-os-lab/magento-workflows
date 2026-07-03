<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class WorkflowActions extends Column
{
    private const URL_EDIT = 'mageos_workflows/workflow/edit';
    private const URL_DELETE = 'mageos_workflows/workflow/delete';

    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $name = $this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            if (!isset($item['workflow_id'])) {
                continue;
            }
            $item[$name] = [
                'edit' => [
                    'href' => $this->urlBuilder->getUrl(self::URL_EDIT, ['workflow_id' => $item['workflow_id']]),
                    'label' => __('Edit'),
                ],
                'delete' => [
                    'href' => $this->urlBuilder->getUrl(self::URL_DELETE, ['workflow_id' => $item['workflow_id']]),
                    'label' => __('Delete'),
                    'confirm' => [
                        'title' => __('Delete "%1"', $item['name'] ?? ''),
                        'message' => __('Are you sure you want to delete this workflow?'),
                    ],
                    '__disableTmpl' => true,
                ],
            ];
        }

        return $dataSource;
    }
}
