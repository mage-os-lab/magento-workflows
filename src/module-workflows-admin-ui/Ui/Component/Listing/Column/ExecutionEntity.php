<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * "Entity" column for the executions grid.
 *
 * A batch execution (05 batch aggregation) has no single entity: it carries
 * entity_id = 0 and a batch trigger context. Rather than showing a misleading
 * "0", render "batch (143 items)" using context.trigger.count. Per-entity
 * executions keep showing their entity id.
 */
class ExecutionEntity extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
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
            $entityId = (int) ($item['entity_id'] ?? 0);
            if ($entityId > 0) {
                $item[$name] = (string) $entityId;
                continue;
            }
            $item[$name] = $this->isBatch($item)
                ? (string) __('batch (%1 items)', $this->batchCount($item))
                : (string) $entityId;
        }

        return $dataSource;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isBatch(array $item): bool
    {
        return ($this->trigger($item)['batch'] ?? null) === true;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function batchCount(array $item): int
    {
        return (int) ($this->trigger($item)['count'] ?? 0);
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function trigger(array $item): array
    {
        $context = $item['context'] ?? null;
        if (is_string($context)) {
            $context = json_decode($context, true);
        }
        if (!is_array($context)) {
            return [];
        }
        $trigger = $context['trigger'] ?? null;
        return is_array($trigger) ? $trigger : [];
    }
}
