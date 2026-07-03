<?php
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Model;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\DispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns a schedule-type workflow's root condition tree into matched entities
 * and dispatches one execution per match (docs/05-triggers.md#scheduled-triggers-workflows-scheduler).
 *
 * - Mapped path: ConditionToSearchCriteria produces a SearchCriteria, which
 *   is queried against the entity's repository, page size 500.
 * - Fallback path: the condition tree could not be index-mapped (nested or
 *   unsupported), so we page through the repository unfiltered by the root
 *   conditions (the watermark filter still applies) within the match cap,
 *   and flag the dispatch payload so the engine knows it must re-evaluate
 *   the root conditions per-execution - it does this anyway.
 *
 * Guard rails: per-run match cap (config mageos_workflows/scheduler/match_cap,
 * default 5000) and a watermark so re-runs don't reprocess the same rows.
 * Cross-execution dedupe on (workflow_id, entity_id) is the dispatcher/
 * engine's debounce, not this class's job.
 */
class QueryRunner
{
    private const XML_PATH_MATCH_CAP = 'mageos_workflows/scheduler/match_cap';
    private const DEFAULT_MATCH_CAP = 5000;
    private const PAGE_SIZE = 500;

    /**
     * Marks a dispatch payload as having skipped index-level root-condition
     * filtering, so the engine knows this candidate was not pre-filtered.
     */
    private const FALLBACK_FLAG = '_workflows_scheduler_fallback';

    /**
     * @param array<string, object> $repositories entity_type => repository exposing
     *        getList(SearchCriteriaInterface): SearchResultsInterface
     */
    public function __construct(
        private readonly DispatcherInterface $dispatcher,
        private readonly ConditionToSearchCriteria $conditionToSearchCriteria,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly FilterBuilder $filterBuilder,
        private readonly FilterGroupBuilder $filterGroupBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger,
        private readonly array $repositories = []
    ) {
    }

    /**
     * @return string|null the watermark to persist for this workflow (unchanged when nothing matched)
     */
    public function run(WorkflowInterface $workflow, ?string $previousWatermark): ?string
    {
        $workflowId = (int) $workflow->getWorkflowId();
        $entityType = $workflow->getEntityType();

        $repository = $this->repositories[$entityType] ?? null;
        if ($repository === null || !method_exists($repository, 'getList')) {
            $this->logger->warning(sprintf(
                'QueryRunner: no queryable repository configured for entity type "%s" (workflow #%d)',
                $entityType,
                $workflowId
            ));
            return $previousWatermark;
        }

        // Per docs: always watermark on created_at for orders, updated_at otherwise.
        $watermarkField = $entityType === 'sales_order' ? 'created_at' : 'updated_at';

        $matchCap = (int) $this->scopeConfig->getValue(self::XML_PATH_MATCH_CAP);
        if ($matchCap <= 0) {
            $matchCap = self::DEFAULT_MATCH_CAP;
        }

        $mappedCriteria = $this->conditionToSearchCriteria->convert(
            $workflow->getConditionsSerialized(),
            $entityType
        );
        $isMapped = $mappedCriteria !== null;
        if (!$isMapped) {
            $this->logger->warning(sprintf(
                'QueryRunner: workflow #%d (%s) root conditions could not be index-mapped; '
                . 'falling back to unfiltered paging within the match cap. '
                . 'The engine will re-evaluate root conditions per-execution.',
                $workflowId,
                $workflow->getName()
            ));
        }
        $baseFilterGroups = $isMapped ? $mappedCriteria->getFilterGroups() : [];

        $sortOrder = $this->sortOrderBuilder
            ->setField($watermarkField)
            ->setDirection(SortOrder::SORT_ASC)
            ->create();

        $newWatermark = $previousWatermark;
        $matched = 0;
        $currentPage = 1;
        $items = [];

        do {
            $filterGroups = $baseFilterGroups;
            if ($previousWatermark !== null && $previousWatermark !== '') {
                $watermarkFilter = $this->filterBuilder
                    ->setField($watermarkField)
                    ->setValue($previousWatermark)
                    ->setConditionType('gt')
                    ->create();
                $filterGroups[] = $this->filterGroupBuilder->setFilters([$watermarkFilter])->create();
            }

            $criteria = $this->searchCriteriaBuilder
                ->setFilterGroups($filterGroups)
                ->setSortOrders([$sortOrder])
                ->setPageSize(self::PAGE_SIZE)
                ->setCurrentPage($currentPage)
                ->create();

            $items = $repository->getList($criteria)->getItems();

            foreach ($items as $entity) {
                if ($matched >= $matchCap) {
                    break 2;
                }

                $flat = $this->toFlatArray($entity);
                $entityId = $this->resolveEntityId($entity, $flat);
                if ($entityId === null) {
                    continue;
                }

                $payload = ['entity_id' => $entityId] + $flat;
                if (!$isMapped) {
                    $payload[self::FALLBACK_FLAG] = true;
                }

                $this->dispatcher->dispatch($workflowId, $payload, WorkflowInterface::TRIGGER_TYPE_SCHEDULE);
                $matched++;

                $watermarkValue = $flat[$watermarkField] ?? null;
                if (is_string($watermarkValue) && $watermarkValue !== ''
                    && ($newWatermark === null || $watermarkValue > $newWatermark)
                ) {
                    $newWatermark = $watermarkValue;
                }
            }

            $currentPage++;
        } while (count($items) === self::PAGE_SIZE && $matched < $matchCap);

        return $newWatermark;
    }

    /**
     * Converts the repository DTO to a flat snapshot array for the dispatch
     * payload, via getData() (extensible/EAV models) or __toArray() as a
     * fallback for plain data objects.
     */
    private function toFlatArray(object $entity): array
    {
        if (method_exists($entity, 'getData')) {
            $data = $entity->getData();
            if (is_array($data)) {
                return $data;
            }
        }
        if (method_exists($entity, '__toArray')) {
            return $entity->__toArray();
        }
        return [];
    }

    private function resolveEntityId(object $entity, array $flat): ?int
    {
        if (isset($flat['entity_id']) && is_numeric($flat['entity_id'])) {
            return (int) $flat['entity_id'];
        }
        if (method_exists($entity, 'getId')) {
            $id = $entity->getId();
            return $id !== null && is_numeric($id) ? (int) $id : null;
        }
        return null;
    }
}
