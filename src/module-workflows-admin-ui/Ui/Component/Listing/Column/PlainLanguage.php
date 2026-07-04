<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use MageOS\Workflows\Model\PlainLanguageRenderer;

/**
 * Adds the plain-language summary sentence (docs/11-admin-ui.md) as its own grid column.
 * Cheap: every field it needs (trigger_type, trigger_ref, entity_type, conditions_serialized,
 * definition) is already present on the raw grid row, so no extra hydration/queries per row.
 */
class PlainLanguage extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly PlainLanguageRenderer $renderer,
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
            $item[$name] = $this->renderer->renderFromFields(
                (string) ($item['trigger_type'] ?? ''),
                (string) ($item['trigger_ref'] ?? ''),
                (string) ($item['entity_type'] ?? ''),
                isset($item['conditions_serialized']) ? (string) $item['conditions_serialized'] : null,
                (string) ($item['definition'] ?? '')
            );
        }

        return $dataSource;
    }
}
