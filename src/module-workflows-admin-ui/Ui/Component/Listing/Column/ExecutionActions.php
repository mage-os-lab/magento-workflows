<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class ExecutionActions extends Column
{
    private const URL_VIEW = 'mageos_workflows/execution/view';

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
            if (!isset($item['execution_id'])) {
                continue;
            }
            $item[$name] = [
                'view' => [
                    'href' => $this->urlBuilder->getUrl(self::URL_VIEW, ['execution_id' => $item['execution_id']]),
                    'label' => __('View'),
                ],
            ];
        }

        return $dataSource;
    }
}
