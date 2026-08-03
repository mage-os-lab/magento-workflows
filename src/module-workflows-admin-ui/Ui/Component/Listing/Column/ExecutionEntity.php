<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use MageOS\WorkflowsAdminUi\Model\OptionLabel;
use MageOS\WorkflowsAdminUi\Model\Source\EntityType;

/**
 * "Entity" column for the executions grid.
 *
 * A batch execution (05 batch aggregation) has no single entity: it carries
 * entity_id = 0 and a batch trigger context. Rather than showing a misleading
 * "0", render "batch (143 items)" using context.trigger.count. Per-entity
 * executions keep showing their entity id.
 *
 * The bare id is also ambiguous across entity types, so it is prefixed with the
 * owning workflow's entity-type LABEL ("Order #142"). The execution table has no
 * entity_type column, but the stamped context already carries workflow.entity_type
 * (Dispatcher::createExecution) and this column already decodes that context for
 * the batch check — so the prefix costs no extra query. An unknown/absent type
 * degrades to the bare id rather than a bogus prefix.
 */
class ExecutionEntity extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly EntityType $entityTypeSource,
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
            $label = $this->entityTypeLabel($item);
            if ($entityId > 0) {
                $item[$name] = $label === '' ? (string) $entityId : sprintf('%s #%d', $label, $entityId);
                continue;
            }
            if (!$this->isBatch($item)) {
                $item[$name] = (string) $entityId;
                continue;
            }
            $batch = (string) __('batch (%1 items)', $this->batchCount($item));
            $item[$name] = $label === '' ? $batch : $label . ' ' . $batch;
        }

        return $dataSource;
    }

    /**
     * Entity-type label stamped into the execution context, or '' when the context
     * names no type (older rows, hand-written fixtures) — the cell then stays the
     * bare id rather than gaining a bogus prefix.
     *
     * @param array<string, mixed> $item
     */
    private function entityTypeLabel(array $item): string
    {
        $workflow = $this->context($item)['workflow'] ?? null;
        $entityType = is_array($workflow) ? (string) ($workflow['entity_type'] ?? '') : '';

        return $entityType === '' ? '' : OptionLabel::resolve($this->entityTypeSource, $entityType);
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
        $trigger = $this->context($item)['trigger'] ?? null;
        return is_array($trigger) ? $trigger : [];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function context(array $item): array
    {
        $context = $item['context'] ?? null;
        if (is_string($context)) {
            $context = json_decode($context, true);
        }
        return is_array($context) ? $context : [];
    }
}
